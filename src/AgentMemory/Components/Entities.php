<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\EntitiesInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\EntityGetOptions;
use SurrealDB\AgentMemory\Options\EntityListOptions;
use SurrealDB\AgentMemory\Options\EntitySearchOptions;
use SurrealDB\AgentMemory\Options\NeighbourhoodOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Options\TopEntitiesOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Implements {@see EntitiesInterface} over the Agent Memory HTTP API. */
final class Entities implements EntitiesInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function list(?EntityListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'entities', $options?->toQuery() ?? []);
    }

    public function listAll(?EntityListOptions $options = null): array
    {
        Guard::collectable('Entities::listAll()', 'Entities::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    public function count(?EntityListOptions $options = null): int
    {
        $page = $this->list(new EntityListOptions(type: $options?->type, limit: 1, count: true));

        return $page->totalSize ?? $page->count();
    }

    public function search(string $query, ?EntitySearchOptions $options = null): array
    {
        Guard::notBlank($query, 'Entity search requires a non-empty query.');

        /** @var list<array<string,mixed>> */
        return $this->transport->requestMember('GET', $this->base() . '/search', 'matches', ['q' => $query] + ($options?->toQuery() ?? []));
    }

    public function top(?TopEntitiesOptions $options = null): array
    {
        /** @var list<array<string,mixed>> */
        return $this->transport->requestMember('GET', $this->base() . '/top', 'entities', $options?->toQuery() ?? []);
    }

    public function get(string $entityType, string $name, ?EntityGetOptions $options = null): array
    {
        return $this->transport->requestJson('GET', $this->path($entityType, $name), $options?->toQuery() ?? []) ?? [];
    }

    public function history(string $entityType, string $name, string $key): array
    {
        $path = $this->path($entityType, $name) . '/history/' . Paths::encodeSegment($key);

        /** @var list<array<string,mixed>> */
        return $this->transport->requestMember('GET', $path, 'history');
    }

    public function changes(string $entityType, string $name, ?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->path($entityType, $name) . '/history', 'history', $options?->toQuery() ?? []);
    }

    public function allChanges(string $entityType, string $name, ?PageOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Entities::allChanges()', 'Entities::changes()', $options?->cursor, $options->count ?? false, $max);

        return $this->changes($entityType, $name, $options)->collect($max);
    }

    public function neighbours(string $entityType, string $name, ?NeighbourhoodOptions $options = null): Page
    {
        return $this->transport->requestPage($this->path($entityType, $name) . '/neighbourhood', 'neighbours', $options?->toQuery() ?? []);
    }

    public function allNeighbours(string $entityType, string $name, ?NeighbourhoodOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Entities::allNeighbours()', 'Entities::neighbours()', $options?->cursor, $options->count ?? false, $max);

        return $this->neighbours($entityType, $name, $options)->collect($max);
    }

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
