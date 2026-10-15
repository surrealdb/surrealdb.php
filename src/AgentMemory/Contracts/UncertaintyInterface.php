<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Exceptions\NotFoundException;
use SurrealDB\AgentMemory\Exceptions\ScopeException;
use SurrealDB\AgentMemory\Options\UncertaintyListOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * Open questions memory has recorded, such as two contending values for one
 * attribute, and their settlement. A row's `resolvable` flag says whether
 * {@see resolve()} would succeed for the calling key.
 *
 * @phpstan-import-type UncertaintyJson from Schemas
 */
interface UncertaintyInterface
{
    /**
     * A page of uncertainty flags, optionally for one subject or settled state.
     *
     * @return Page<UncertaintyJson>
     */
    public function list(?UncertaintyListOptions $options = null): Page;

    /**
     * Every uncertainty flag matching `$options`, walking the pages in order
     * (`GET /uncertainty`).
     *
     * @return list<UncertaintyJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?UncertaintyListOptions $options = null): array;

    /**
     * How many flags match, without fetching them: one request for a single
     * row with `count: true`. Only the filters (`entity`, `resolved`) are
     * read; a response without `totalSize` counts the rows it carries.
     *
     * @throws InvalidArgumentException when the `entity` filter is not a `<type>/<name>` ref
     */
    public function count(?UncertaintyListOptions $options = null): int;

    /**
     * Settles a flag by accepting one value, written as a fresh high-trust
     * assertion that retires the contenders. The server stores the value in
     * normalised form (collapsed whitespace, control characters dropped).
     *
     * @param string      $acceptedValue the value to accept
     * @param string|null $note          why it was chosen, stored as the new row's source clause
     *
     * @return UncertaintyJson the settled `uncertainty` row
     *
     * @throws NotFoundException when no such flag is visible to the caller
     * @throws ScopeException    when the flag reaches beyond the caller's `memory:write` region
     */
    public function resolve(string $uncertaintyId, string $acceptedValue, ?string $note = null): array;
}
