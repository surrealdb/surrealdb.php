<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Exceptions\NotFoundException;
use SurrealDB\AgentMemory\Options\EntityGetOptions;
use SurrealDB\AgentMemory\Options\EntityListOptions;
use SurrealDB\AgentMemory\Options\EntitySearchOptions;
use SurrealDB\AgentMemory\Options\NeighbourhoodOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Options\TopEntitiesOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * Entity records with their attributes, relations, history, and graph
 * neighbours, plus deterministic name search and ranked listings.
 *
 * @phpstan-import-type AttributeDetailJson from Schemas
 * @phpstan-import-type EntityDetailJson from Schemas
 * @phpstan-import-type EntityMatchJson from Schemas
 * @phpstan-import-type EntityResponseJson from Schemas
 * @phpstan-import-type NeighbourJson from Schemas
 */
interface EntitiesInterface
{
    /**
     * A page of entities, optionally filtered by type.
     *
     * @return Page<EntityDetailJson>
     */
    public function list(?EntityListOptions $options = null): Page;

    /**
     * Every entity matching `$options`, walking the pages in order
     * (`GET /entities`).
     *
     * @return list<EntityDetailJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     * @throws AgentMemoryException     when the request fails
     */
    public function listAll(?EntityListOptions $options = null): array;

    /**
     * How many entities match, without fetching them: one request for a
     * single row with `count: true`. Only the `type` filter is read; a
     * response without `totalSize` counts the rows it carries.
     */
    public function count(?EntityListOptions $options = null): int;

    /**
     * Searches entities by name, best match first (`GET /entities/search`).
     * Lexical and deterministic: an exact match on the normalised name scores
     * `1.0`, and each match carries its fact count and a `distinguisher` so
     * same-named candidates can be told apart.
     *
     * @return list<EntityMatchJson> the `matches`
     *
     * @throws InvalidArgumentException when the query is blank, before any request is sent
     * @throws AgentMemoryException     when the request fails
     */
    public function search(string $query, ?EntitySearchOptions $options = null): array;

    /**
     * The highest-ranked entities by coverage, importance, or recency
     * (`GET /entities/top`).
     *
     * @return list<EntityMatchJson> the ranked `entities`, each with its score and fact count
     */
    public function top(?TopEntitiesOptions $options = null): array;

    /**
     * Fetches a single entity by type and name, with its attributes and
     * relations, optionally as of a point in time.
     *
     * @return EntityResponseJson
     *
     * @throws NotFoundException    when no such entity is visible to the caller
     * @throws AgentMemoryException when the request fails otherwise
     */
    public function get(string $entityType, string $name, ?EntityGetOptions $options = null): array;

    /**
     * Returns the supersession history for one attribute key.
     *
     * @return list<AttributeDetailJson>
     */
    public function history(string $entityType, string $name, string $key): array;

    /**
     * One page of every key's supersession chain for the entity, newest first
     * (`GET /entities/{type}/{name}/history`): what changed about the subject,
     * where {@see history()} is how one key changed.
     *
     * @return Page<AttributeDetailJson>
     */
    public function changes(string $entityType, string $name, ?PageOptions $options = null): Page;

    /**
     * The entity's whole change history, walking the pages in order
     * (`GET /entities/{type}/{name}/history`). An attribute revised on every
     * sync has an unbounded chain, so pass `$max` to stop the walk after the
     * page that reaches it; up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many rows are in (at least 1)
     *
     * @return list<AttributeDetailJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     * @throws AgentMemoryException     when the request fails
     */
    public function allChanges(string $entityType, string $name, ?PageOptions $options = null, ?int $max = null): array;

    /**
     * A page of the entity's graph neighbours (`GET /entities/{type}/{name}/neighbourhood`):
     * each relation edge with its label, direction (`outbound`), and the entity
     * at the far end.
     *
     * @return Page<NeighbourJson>
     */
    public function neighbours(string $entityType, string $name, ?NeighbourhoodOptions $options = null): Page;

    /**
     * Every graph neighbour of the entity, walking the pages in order
     * (`GET /entities/{type}/{name}/neighbourhood`). With `$max` the walk
     * stops after the page that reaches it, so up to one page more can come
     * back.
     *
     * @param int|null $max stop collecting once this many neighbours are in (at least 1)
     *
     * @return list<NeighbourJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     * @throws AgentMemoryException     when the request fails
     */
    public function allNeighbours(string $entityType, string $name, ?NeighbourhoodOptions $options = null, ?int $max = null): array;

    /** Soft-deletes an entity (sets valid-until). */
    public function delete(string $entityType, string $name): void;
}
