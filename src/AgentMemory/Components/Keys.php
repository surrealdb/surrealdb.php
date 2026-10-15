<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\KeysInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\KeyCreateOptions;
use SurrealDB\AgentMemory\Options\KeyRotateOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Implements {@see KeysInterface} over the Agent Memory HTTP API. */
final class Keys implements KeysInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

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

    public function list(?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'keys', $options?->toQuery() ?? []);
    }

    public function listAll(?PageOptions $options = null): array
    {
        Guard::collectable('Keys::listAll()', 'Keys::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    public function delete(string $keyName): void
    {
        $this->transport->requestJson('DELETE', $this->base() . '/' . Paths::encodeSegment($keyName));
    }

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
