<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\UncertaintyInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\UncertaintyListOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Schema\Schemas;
use SurrealDB\AgentMemory\Transport;

/**
 * Implements {@see UncertaintyInterface} over the Agent Memory HTTP API.
 *
 * @phpstan-import-type UncertaintyJson from Schemas
 */
final class Uncertainty implements UncertaintyInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function list(?UncertaintyListOptions $options = null): Page
    {
        /** @var Page<UncertaintyJson> */
        return $this->transport->requestPage($this->base(), 'unknowns', $options?->toQuery() ?? []);
    }

    public function listAll(?UncertaintyListOptions $options = null): array
    {
        Guard::collectable('Uncertainty::listAll()', 'Uncertainty::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    public function count(?UncertaintyListOptions $options = null): int
    {
        $page = $this->list(new UncertaintyListOptions(entity: $options?->entity, resolved: $options?->resolved, limit: 1, count: true));

        return $page->totalSize ?? $page->count();
    }

    public function resolve(string $uncertaintyId, string $acceptedValue, ?string $note = null): array
    {
        Guard::notBlank($acceptedValue, 'Resolving an uncertainty requires a non-empty accepted value.');

        $body = ['acceptedValue' => $acceptedValue] + ($note === null ? [] : ['note' => $note]);
        $path = $this->base() . '/' . Paths::encodeSegment($uncertaintyId) . '/resolve';

        /** @var UncertaintyJson */
        return $this->transport->requestMember('POST', $path, 'uncertainty', body: $body);
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/uncertainty';
    }
}
