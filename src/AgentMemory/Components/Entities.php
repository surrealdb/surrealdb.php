<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Exceptions\NotFoundException;
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

/**
 * Entity records with their attributes, relations, history, and graph
 * neighbours, plus deterministic name search and ranked listings.
 */
final class Entities
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** A page of entities, optionally filtered by type. */
    public function list(?EntityListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'entities', $options?->toQuery() ?? []);
    }

    /**
     * Every entity matching `$options`, walking the pages in order
     * (`GET /entities`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?EntityListOptions $options = null): array
    {
        Guard::collectable('Entities::listAll()', 'Entities::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    /**
     * Searches entities by name, best match first (`GET /entities/search`).
     * Lexical and deterministic: an exact match on the normalised name scores
     * `1.0`, and each match carries its fact count and a `distinguisher` so
     * same-named candidates can be told apart.
     *
     * @return list<array<string,mixed>> the `matches`
     */
    public function search(string $query, ?EntitySearchOptions $options = null): array
    {
        Guard::notBlank($query, 'Entity search requires a non-empty query.');

        /** @var list<array<string,mixed>> */
        return $this->transport->requestMember('GET', $this->base() . '/search', 'matches', ['q' => $query] + ($options?->toQuery() ?? []));
    }

    /**
     * The highest-ranked entities by coverage, importance, or recency
     * (`GET /entities/top`).
     *
     * @return list<array<string,mixed>> the ranked `entities`, each with its score and fact count
     */
    public function top(?TopEntitiesOptions $options = null): array
    {
        /** @var list<array<string,mixed>> */
        return $this->transport->requestMember('GET', $this->base() . '/top', 'entities', $options?->toQuery() ?? []);
    }

    /**
     * Fetches a single entity by type and name, with its attributes and
     * relations, optionally as of a point in time.
     *
     * @return array<int|string,mixed>
     *
     * @throws NotFoundException when no such entity is visible to the caller
     */
    public function get(string $entityType, string $name, ?EntityGetOptions $options = null): array
    {
        return $this->transport->requestJson('GET', $this->path($entityType, $name), $options?->toQuery() ?? []) ?? [];
    }

    /**
     * Returns the supersession history for one attribute key.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $entityType, string $name, string $key): array
    {
        $path = $this->path($entityType, $name) . '/history/' . Paths::encodeSegment($key);

        /** @var list<array<string,mixed>> */
        return $this->transport->requestMember('GET', $path, 'history');
    }

    /**
     * One page of every key's supersession chain for the entity, newest first
     * (`GET /entities/{type}/{name}/history`): what changed about the subject,
     * where {@see history()} is how one key changed.
     */
    public function changes(string $entityType, string $name, ?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->path($entityType, $name) . '/history', 'history', $options?->toQuery() ?? []);
    }

    /**
     * The entity's whole change history, walking the pages in order
     * (`GET /entities/{type}/{name}/history`). An attribute revised on every
     * sync has an unbounded chain, so pass `$max` to stop the walk after the
     * page that reaches it; up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many rows are in (at least 1)
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     */
    public function allChanges(string $entityType, string $name, ?PageOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Entities::allChanges()', 'Entities::changes()', $options?->cursor, $options->count ?? false, $max);

        return $this->changes($entityType, $name, $options)->collect($max);
    }

    /**
     * A page of the entity's graph neighbours (`GET /entities/{type}/{name}/neighbourhood`):
     * each relation edge with its label, direction (`outbound`), and the entity
     * at the far end.
     */
    public function neighbours(string $entityType, string $name, ?NeighbourhoodOptions $options = null): Page
    {
        return $this->transport->requestPage($this->path($entityType, $name) . '/neighbourhood', 'neighbours', $options?->toQuery() ?? []);
    }

    /**
     * Every graph neighbour of the entity, walking the pages in order
     * (`GET /entities/{type}/{name}/neighbourhood`). With `$max` the walk
     * stops after the page that reaches it, so up to one page more can come
     * back.
     *
     * @param int|null $max stop collecting once this many neighbours are in (at least 1)
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     */
    public function allNeighbours(string $entityType, string $name, ?NeighbourhoodOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Entities::allNeighbours()', 'Entities::neighbours()', $options?->cursor, $options->count ?? false, $max);

        return $this->neighbours($entityType, $name, $options)->collect($max);
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
