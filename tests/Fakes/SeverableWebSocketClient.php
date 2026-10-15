<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\SDK\Contracts\WebSocketClientInterface;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Transport\StreamWebSocketClient;

/**
 * Wraps the real stream client and can drop the connection without a close
 * handshake, the way a crashed server or a broken network path does.
 */
final class SeverableWebSocketClient implements WebSocketClientInterface
{
    private bool $severed = false;

    private bool $refusing = false;

    public int $connects = 0;

    /**
     * Called with each outgoing payload before it is sent; it may sever the
     * connection to fail that send, or return a payload to send instead.
     *
     * @var (\Closure(string): ?string)|null
     */
    public ?\Closure $beforeSend = null;

    public function __construct(
        private readonly StreamWebSocketClient $inner = new StreamWebSocketClient(),
    ) {}

    public function sever(): void
    {
        $this->severed = true;
        $this->inner->close();
    }

    /** Make later connection attempts fail, as if the server were down. */
    public function refuseConnections(bool $refuse = true): void
    {
        $this->refusing = $refuse;
    }

    public function connect(string $url, array $subprotocols = [], array $headers = []): void
    {
        $this->connects++;

        if ($this->refusing) {
            throw new SurrealException('Connection refused');
        }

        $this->severed = false;
        $this->inner->connect($url, $subprotocols, $headers);
    }

    public function send(string $payload, bool $binary = false): void
    {
        if ($this->beforeSend !== null) {
            $payload = ($this->beforeSend)($payload) ?? $payload;
        }

        if ($this->severed) {
            throw new SurrealException('Failed to write to the WebSocket connection');
        }

        $this->inner->send($payload, $binary);
    }

    public function receive(?float $timeout = null): ?string
    {
        return $this->severed ? null : $this->inner->receive($timeout);
    }

    public function ping(string $payload = ''): void
    {
        $this->inner->ping($payload);
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->inner->close($code, $reason);
    }

    public function isConnected(): bool
    {
        return !$this->severed && $this->inner->isConnected();
    }
}
