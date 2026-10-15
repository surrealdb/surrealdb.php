<?php

namespace SurrealDB\Contracts;

/**
 * A minimal synchronous WebSocket client abstraction wrapped by the default
 * {@see \SurrealDB\Transport\WebSocketTransport}. Bring your own
 * implementation (e.g. a PECL or pure-PHP client) via DriverOptions, or rely on
 * the bundled stream-based default.
 */
interface WebSocketClientInterface
{
    /**
     * @param list<string>          $subprotocols
     * @param array<string,string>  $headers
     */
    public function connect(string $url, array $subprotocols = [], array $headers = []): void;

    public function send(string $payload, bool $binary = false): void;

    /**
     * Block for the next message payload.
     *
     * @return string|null null when the connection has closed.
     */
    public function receive(?float $timeout = null): ?string;

    public function ping(string $payload = ''): void;

    public function close(int $code = 1000, string $reason = ''): void;

    public function isConnected(): bool;
}
