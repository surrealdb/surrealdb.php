<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Exceptions\NotFoundException;
use SurrealDB\AgentMemory\Exceptions\ScopeException;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\UncertaintyListOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/**
 * Open questions memory has recorded, such as two contending values for one
 * attribute, and their settlement. A row's `resolvable` flag says whether
 * {@see resolve()} would succeed for the calling key.
 */
final class Uncertainty
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** A page of uncertainty flags, optionally for one subject or settled state. */
    public function list(?UncertaintyListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'unknowns', $options?->toQuery() ?? []);
    }

    /**
     * Every uncertainty flag matching `$options`, walking the pages in order
     * (`GET /uncertainty`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?UncertaintyListOptions $options = null): array
    {
        Guard::collectable('Uncertainty::listAll()', 'Uncertainty::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    /**
     * Settles a flag by accepting one value, written as a fresh high-trust
     * assertion that retires the contenders. The server stores the value in
     * normalised form (collapsed whitespace, control characters dropped).
     *
     * @param string      $acceptedValue the value to accept
     * @param string|null $note          why it was chosen, stored as the new row's source clause
     *
     * @return array<string,mixed> the settled `uncertainty` row
     *
     * @throws NotFoundException when no such flag is visible to the caller
     * @throws ScopeException    when the flag reaches beyond the caller's `memory:write` region
     */
    public function resolve(string $uncertaintyId, string $acceptedValue, ?string $note = null): array
    {
        Guard::notBlank($acceptedValue, 'Resolving an uncertainty requires a non-empty accepted value.');

        $body = ['acceptedValue' => $acceptedValue] + ($note === null ? [] : ['note' => $note]);
        $path = $this->base() . '/' . Paths::encodeSegment($uncertaintyId) . '/resolve';

        /** @var array<string,mixed> */
        return $this->transport->requestMember('POST', $path, 'uncertainty', body: $body);
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/uncertainty';
    }
}
