<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Options\EntityListOptions;
use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

/** Entity records, attributes, relations, and attribute history. */
final class Entities
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /**
     * Lists entities, optionally filtered by type.
     *
     * @return list<array<string,mixed>>
     */
    public function list(?EntityListOptions $options = null): array
    {
        return ($this->transport->requestJson('GET', $this->base(), $options?->toQuery() ?? []) ?? [])['entities'] ?? [];
    }

    /**
     * Fetches a single entity by type and name, with its attributes and relations.
     *
     * @return array<int|string,mixed>
     */
    public function get(string $entityType, string $name): array
    {
        return $this->transport->requestJson('GET', $this->path($entityType, $name)) ?? [];
    }

    /**
     * Returns the supersession history for one attribute key.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $entityType, string $name, string $key): array
    {
        $path = $this->path($entityType, $name) . '/history/' . Paths::encodeSegment($key);

        return ($this->transport->requestJson('GET', $path) ?? [])['history'] ?? [];
    }

    /** Soft-deletes an entity (sets valid-until). */
    public function delete(string $entityType, string $name): void
    {
        $this->transport->requestJson('DELETE', $this->path($entityType, $name));
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/entities';
    }

    private function path(string $entityType, string $name): string
    {
        return $this->base() . '/' . Paths::encodeSegment($entityType) . '/' . Paths::encodeSegment($name);
    }
}
