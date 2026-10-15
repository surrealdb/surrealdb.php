<?php

namespace SurrealDB\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Transport\StreamWebSocketClient;

/**
 * Drives the bundled client against a tiny WebSocket server running in a
 * child PHP process, so frames can arrive with real delays between them.
 */
final class StreamWebSocketClientTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    /** @var array<int,resource> */
    private array $pipes = [];

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    public function testEmptyFirstFragmentStartsTheMessageSoTheContinuationIsNotTimedOut(): void
    {
        // An empty, non-final text frame, then (after the caller's timeout has
        // passed) the final continuation frame carrying the payload.
        $url = $this->startServer(<<<'PHP'
            fwrite($conn, chr(0x01) . chr(0x00));
            usleep(500_000);
            fwrite($conn, chr(0x80) . chr(5) . 'hello');
            PHP);

        $client = new StreamWebSocketClient(connectTimeout: 5.0, pollInterval: 0.1);
        $client->connect($url);

        try {
            self::assertSame('hello', $client->receive(0.2));
            self::assertTrue($client->isConnected());
        } finally {
            $client->close();
        }
    }

    /**
     * Start a server that completes the upgrade handshake, runs `$script` with
     * the accepted connection in `$conn`, then waits for the client to close.
     */
    private function startServer(string $script): string
    {
        $code = <<<PHP
            \$server = stream_socket_server('tcp://127.0.0.1:0', \$errno, \$errstr);
            fwrite(STDOUT, stream_socket_get_name(\$server, false) . "\\n");
            fflush(STDOUT);
            \$conn = stream_socket_accept(\$server, 10);
            \$request = '';
            while (!str_contains(\$request, "\\r\\n\\r\\n") && (\$line = fgets(\$conn)) !== false) {
                \$request .= \$line;
            }
            preg_match('#Sec-WebSocket-Key:\\s*(\\S+)#i', \$request, \$m);
            \$accept = base64_encode(sha1(\$m[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            fwrite(\$conn, "HTTP/1.1 101 Switching Protocols\\r\\nUpgrade: websocket\\r\\nConnection: Upgrade\\r\\nSec-WebSocket-Accept: {\$accept}\\r\\n\\r\\n");
            {$script}
            stream_set_timeout(\$conn, 10);
            while (!feof(\$conn) && fread(\$conn, 1024) !== false) {
            }
            PHP;

        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $this->server = $process;
        $this->pipes = $pipes;

        $address = trim((string) fgets($pipes[1]));
        self::assertNotSame('', $address, 'The test WebSocket server did not start');

        return "ws://{$address}/rpc";
    }
}
