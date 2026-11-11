<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\Contracts\WebSocketClientInterface;

final class FakeWebSocketClient implements WebSocketClientInterface
{
    public ?string $url = null;

    /** @var list<string> */
    public array $subprotocols = [];

    /** @var list<array{payload: string, binary: bool}> */
    public array $sent = [];

    private bool $connected = false;

    public function connect(string $url, array $subprotocols = [], array $headers = []): void
    {
        $this->url = $url;
        $this->subprotocols = $subprotocols;
        $this->connected = true;
    }

    public function send(string $payload, bool $binary = false): void
    {
        $this->sent[] = ['payload' => $payload, 'binary' => $binary];
    }

    public function receive(?float $timeout = null): ?string
    {
        return null;
    }

    public function ping(string $payload = ''): void {}

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}
