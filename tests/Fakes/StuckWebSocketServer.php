<?php

namespace SurrealDB\Tests\Fakes;

/**
 * A WebSocket server, in a child process, that completes the upgrade
 * handshake and then reads nothing: a write larger than the socket buffers
 * never finishes.
 */
final class StuckWebSocketServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    public readonly string $address;

    public function __construct()
    {
        $code = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
            fflush(STDOUT);
            $conn = stream_socket_accept($server, 10);
            $request = '';
            while (!str_contains($request, "\r\n\r\n") && ($line = fgets($conn)) !== false) {
                $request .= $line;
            }
            preg_match('#Sec-WebSocket-Key:\s*(\S+)#i', $request, $key);
            preg_match('#Sec-WebSocket-Protocol:\s*([^,\r\n]+)#i', $request, $protocol);
            $accept = base64_encode(sha1($key[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            fwrite($conn, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . (isset($protocol[1]) ? "Sec-WebSocket-Protocol: {$protocol[1]}\r\n" : '')
                . "Sec-WebSocket-Accept: {$accept}\r\n\r\n");
            sleep(60);
            PHP;

        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            throw new \RuntimeException('Could not start the test WebSocket server.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        $this->address = trim((string) fgets($pipes[1]));
    }

    public function url(): string
    {
        return "ws://{$this->address}/rpc";
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate($this->process);
        proc_close($this->process);
    }
}
