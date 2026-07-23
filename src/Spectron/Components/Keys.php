<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Options\KeyCreateOptions;
use SurrealDB\Spectron\Options\KeyRotateOptions;
use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

/** Self-service API keys for this context (requires the `manage` grant). */
final class Keys
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /**
     * Mints a new key. The full bearer secret (`sp-{id}-{secret}`) is returned
     * once in the `key` field and cannot be retrieved again.
     *
     * @return array<int|string,mixed>
     */
    public function create(?KeyCreateOptions $options = null): array
    {
        $payload = $options?->toPayload() ?? [];

        return $this->transport->requestJson(
            'POST',
            $this->base(),
            $options?->toQuery() ?? [],
            $payload === [] ? null : $payload,
        ) ?? [];
    }

    /**
     * Lists key metadata for the context (secrets are never included).
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        return $this->transport->requestJson('GET', $this->base()) ?? [];
    }

    /** Revokes a key by name. */
    public function delete(string $keyName): void
    {
        $this->transport->requestJson('DELETE', $this->base() . '/' . Paths::encodeSegment($keyName));
    }

    /**
     * Rotates a key, returning a fresh secret in the `key` field.
     *
     * @return array<int|string,mixed>
     */
    public function rotate(string $keyName, ?KeyRotateOptions $options = null): array
    {
        return $this->transport->requestJson(
            'POST',
            $this->base() . '/' . Paths::encodeSegment($keyName) . '/rotate',
            $options?->toQuery() ?? [],
        ) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/keys';
    }
}
