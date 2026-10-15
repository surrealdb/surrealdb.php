<?php

namespace SurrealDB\SDK\Transport;

use SurrealDB\SDK\Contracts\WebSocketClientInterface;
use SurrealDB\SDK\Exceptions\SurrealException;
use function chr;
use function is_resource;
use function ord;
use function sprintf;
use function strlen;

/**
 * A compact, dependency-free synchronous WebSocket client (RFC 6455) built on
 * PHP stream sockets. This is the bundled default behind the WebSocket
 * transport; swap in a PECL/async client via DriverOptions when desired.
 *
 * It implements the client essentials: the upgrade handshake, masked client
 * frames, fragmentation reassembly, and ping/pong/close control frames.
 */
final class StreamWebSocketClient implements WebSocketClientInterface
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource|null */
    private $socket = null;

    private bool $connected = false;

    /**
     * @param float $pollInterval how long each blocking read waits before it
     *                            checks the socket again; reaching it does not
     *                            close an idle connection
     */
    public function __construct(
        private readonly float $connectTimeout = 10.0,
        private readonly float $pollInterval = 60.0,
    ) {}

    public function connect(string $url, array $subprotocols = [], array $headers = []): void
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            throw new SurrealException("Invalid WebSocket URL: {$url}");
        }

        $secure = strtolower($parts['scheme'] ?? 'ws') === 'wss';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $remote = sprintf('%s://%s:%d', $secure ? 'tls' : 'tcp', $host, $port);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(),
        );

        if ($socket === false) {
            throw new SurrealException("Unable to connect to {$remote}: {$errstr} ({$errno})");
        }

        $this->socket = $socket;
        $this->performHandshake($host, $port, $path, $subprotocols, $headers);
        $this->connected = true;
    }

    public function send(string $payload, bool $binary = false): void
    {
        $this->writeFrame($binary ? 0x2 : 0x1, $payload);
    }

    public function ping(string $payload = ''): void
    {
        $this->writeFrame(0x9, $payload);
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->connected && is_resource($this->socket)) {
            try {
                $this->writeFrame(0x8, pack('n', $code) . $reason);
            } catch (\Throwable) {
                // Best effort: the peer may already be gone.
            }
        }

        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected && is_resource($this->socket) && !feof($this->socket);
    }

    public function receive(?float $timeout = null): ?string
    {
        if (!$this->isConnected()) {
            return null;
        }

        $message = '';

        while (true) {
            // Only the first byte of a message may time out: once a frame has
            // started, the rest of it is read to completion.
            $frame = $this->readFrame($message === '' ? $timeout : null);

            if ($frame === null) {
                return null;
            }

            [$fin, $opcode, $data] = $frame;

            switch ($opcode) {
                case 0x9: // ping -> reply pong
                    $this->writeFrame(0xA, $data);
                    continue 2;
                case 0xA: // pong
                    continue 2;
                case 0x8: // close
                    $this->close();

                    return null;
                case 0x0: // continuation
                case 0x1: // text
                case 0x2: // binary
                    $message .= $data;

                    break;
                default:
                    continue 2;
            }

            if ($fin) {
                return $message;
            }
        }
    }

    /**
     * @param list<string>         $subprotocols
     * @param array<string,string> $headers
     */
    private function performHandshake(string $host, int $port, string $path, array $subprotocols, array $headers): void
    {
        $key = base64_encode(random_bytes(16));

        $lines = [
            "GET {$path} HTTP/1.1",
            "Host: {$host}:{$port}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
        ];

        if ($subprotocols !== []) {
            $lines[] = 'Sec-WebSocket-Protocol: ' . implode(', ', $subprotocols);
        }

        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $this->writeRaw(implode("\r\n", $lines) . "\r\n\r\n");

        $response = '';

        while (!str_contains($response, "\r\n\r\n")) {
            $line = is_resource($this->socket) ? fgets($this->socket) : false;

            if ($line === false) {
                throw new SurrealException('WebSocket handshake failed: connection closed during upgrade');
            }

            $response .= $line;
        }

        if (preg_match('#^HTTP/1\.1 101#i', $response) !== 1) {
            throw new SurrealException('WebSocket handshake failed: ' . (string) strtok($response, "\r\n"));
        }

        $expected = base64_encode(sha1($key . self::GUID, true));

        if (preg_match('#Sec-WebSocket-Accept:\s*(.+)\r\n#i', $response, $matches) !== 1
            || trim($matches[1]) !== $expected
        ) {
            throw new SurrealException('WebSocket handshake failed: invalid Sec-WebSocket-Accept header');
        }
    }

    /**
     * @param float|null $timeout seconds to wait for the frame to start, or null
     *                            to wait indefinitely
     *
     * @return array{0:bool,1:int,2:string}|null
     */
    private function readFrame(?float $timeout = null): ?array
    {
        $header = $this->readRaw(2, $timeout);

        if ($header === null) {
            return null;
        }

        $first = ord($header[0]);
        $second = ord($header[1]);
        $fin = ($first & 0x80) !== 0;
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7F;

        if ($length === 126) {
            $ext = $this->readRaw(2);

            if ($ext === null) {
                return null;
            }

            $length = (int) unpack('n', $ext)[1];
        } elseif ($length === 127) {
            $ext = $this->readRaw(8);

            if ($ext === null) {
                return null;
            }

            $length = (int) unpack('J', $ext)[1];
        }

        $maskKey = '';

        if ($masked) {
            $maskKey = $this->readRaw(4);

            if ($maskKey === null) {
                return null;
            }
        }

        $payload = $length > 0 ? $this->readRaw($length) : '';

        if ($payload === null) {
            return null;
        }

        if ($masked && $maskKey !== '') {
            $payload ^= str_repeat($maskKey, intdiv($length, 4) + 1);
        }

        return [$fin, $opcode, $payload];
    }

    private function writeFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($length <= 125) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = $length > 0 ? $payload ^ str_repeat($mask, intdiv($length, 4) + 1) : '';

        $this->writeRaw($header . $mask . $masked);
    }

    private function writeRaw(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new SurrealException('WebSocket is not connected');
        }

        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $chunk = @fwrite($this->socket, substr($data, $written));

            if ($chunk === false || $chunk === 0) {
                throw new SurrealException('Failed to write to the WebSocket connection');
            }

            $written += $chunk;
        }
    }

    /**
     * Read exactly `$length` bytes. A socket read timeout is not end of stream:
     * without a `$timeout` the read keeps waiting (an idle connection, e.g. one
     * waiting for live query notifications, is still open), and with one it
     * gives up only if no byte has arrived yet.
     */
    private function readRaw(int $length, ?float $timeout = null): ?string
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        $deadline = $timeout !== null ? microtime(true) + $timeout : null;
        $this->setReadTimeout($timeout ?? $this->pollInterval);

        $data = '';

        while (strlen($data) < $length) {
            $chunk = @fread($this->socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                if (!is_resource($this->socket) || feof($this->socket)) {
                    $this->connected = false;

                    return null;
                }

                $meta = stream_get_meta_data($this->socket);

                if (!empty($meta['timed_out'])) {
                    if ($data === '' && $deadline !== null && microtime(true) >= $deadline) {
                        return null;
                    }

                    if ($data !== '' && $deadline !== null) {
                        $deadline = null;
                        $this->setReadTimeout($this->pollInterval);
                    }
                }

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function setReadTimeout(float $seconds): void
    {
        if (!is_resource($this->socket)) {
            return;
        }

        $whole = (int) floor($seconds);
        stream_set_timeout($this->socket, $whole, (int) (($seconds - $whole) * 1_000_000));
    }
}
