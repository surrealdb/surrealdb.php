<?php

namespace SurrealDB\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Transport\StreamWebSocketClient;

/**
 * A server that closes the WebSocket while the client is idle: the close
 * frame sits unread in the socket, and the TCP connection stays open.
 */
final class StreamWebSocketClientCloseTest extends TestCase
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

    public function testABufferedCloseFrameMeansTheConnectionIsClosed(): void
    {
        // A text message, then a close frame (code 1000), without ending TCP.
        $url = $this->startServer(<<<'PHP'
            fwrite($conn, chr(0x81) . chr(2) . 'hi');
            fwrite($conn, chr(0x88) . chr(2) . pack('n', 1000));
            PHP);

        $client = new StreamWebSocketClient(connectTimeout: 5.0);
        $client->connect($url);

        try {
            usleep(300_000);

            self::assertFalse($client->isConnected());

            // What arrived before the close is still delivered.
            self::assertSame('hi', $client->receive(0.1));
            self::assertNull($client->receive(0.1));
        } finally {
            $client->close();
        }
    }

    public function testABufferedPingDoesNotBlockTheCheck(): void
    {
        $url = $this->startServer(<<<'PHP'
            fwrite($conn, chr(0x89) . chr(0));
            PHP);

        $client = new StreamWebSocketClient(connectTimeout: 5.0);
        $client->connect($url);

        try {
            usleep(300_000);
            $start = microtime(true);

            self::assertTrue($client->isConnected());
            self::assertLessThan(1.0, microtime(true) - $start);
        } finally {
            $client->close();
        }
    }

    public function testAPartlyArrivedFrameDoesNotBlockTheCheckAndIsStillRead(): void
    {
        // The header and part of the payload, then the rest a little later.
        $url = $this->startServer(<<<'PHP'
            fwrite($conn, chr(0x81) . chr(5) . 'he');
            usleep(800_000);
            fwrite($conn, 'llo');
            PHP);

        $client = new StreamWebSocketClient(connectTimeout: 5.0);
        $client->connect($url);

        try {
            usleep(300_000);
            $start = microtime(true);

            self::assertTrue($client->isConnected());
            self::assertLessThan(0.5, microtime(true) - $start);
            self::assertSame('hello', $client->receive(5.0));
        } finally {
            $client->close();
        }
    }

    public function testAnIdleOpenConnectionIsConnected(): void
    {
        $url = $this->startServer('');

        $client = new StreamWebSocketClient(connectTimeout: 5.0);
        $client->connect($url);

        try {
            self::assertTrue($client->isConnected());
        } finally {
            $client->close();
        }
    }

    public function testAWriteTheServerStopsReadingEndsInsteadOfHanging(): void
    {
        // Completes the handshake, then reads nothing.
        $url = $this->startServer('sleep(30);');

        $client = new StreamWebSocketClient(connectTimeout: 5.0, writeTimeout: 0.5);
        $client->connect($url);
        $started = microtime(true);

        try {
            // Far more than the socket buffers hold.
            $client->send(str_repeat('x', 16 * 1024 * 1024), binary: true);
            self::fail('The write should have run out of time.');
        } catch (SurrealException $error) {
            self::assertStringContainsString('stopped reading', $error->getMessage());
        }

        // The connection is closed: nothing can follow half a frame.
        self::assertFalse($client->isConnected());
        $client->close();
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testClosingAConnectionTheServerStopsReadingDoesNotHang(): void
    {
        $url = $this->startServer('sleep(30);');

        $client = new StreamWebSocketClient(connectTimeout: 5.0, writeTimeout: 0.5);
        $client->connect($url);

        // Fill the socket buffers, one frame at a time, without timing out.
        try {
            for ($i = 0; $i < 4096; $i++) {
                $client->send(str_repeat('x', 16 * 1024), binary: true);
            }
        } catch (SurrealException) {
        }

        $started = microtime(true);
        $client->close();
        self::assertLessThan(3.0, microtime(true) - $started);
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
