<?php

namespace SurrealDB\SDK\Transport;

use SurrealDB\SDK\Contracts\WebSocketClientInterface;
use SurrealDB\SDK\Exceptions\SurrealException;

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

    public function __construct(
        private readonly float $connectTimeout = 10.0,
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

        if ($timeout !== null && is_resource($this->socket)) {
            $seconds = (int) floor($timeout);
            stream_set_timeout($this->socket, $seconds, (int) (($timeout - $seconds) * 1_000_000));
        }

        $message = '';

        while (true) {
            $frame = $this->readFrame();

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
     * @return array{0:bool,1:int,2:string}|null
     */
    private function readFrame(): ?array
    {
        $header = $this->readRaw(2);

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

    private function readRaw(int $length): ?string
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        $data = '';

        while (strlen($data) < $length) {
            $chunk = @fread($this->socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);

                if (!empty($meta['timed_out']) || feof($this->socket)) {
                    return null;
                }

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }
}
