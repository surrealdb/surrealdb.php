<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\FactsInterface;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\ActionListOptions;
use SurrealDB\AgentMemory\Options\AttributeListOptions;
use SurrealDB\AgentMemory\Options\RelationListOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Schema\Schemas;
use SurrealDB\AgentMemory\Transport;

/**
 * Implements {@see FactsInterface} over the Agent Memory HTTP API.
 *
 * @phpstan-import-type ActionDetailJson from Schemas
 * @phpstan-import-type AttributeDetailJson from Schemas
 * @phpstan-import-type RelationDetailJson from Schemas
 */
final class Facts implements FactsInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function attributes(?AttributeListOptions $options = null): Page
    {
        /** @var Page<AttributeDetailJson> */
        return $this->transport->requestPage($this->base() . '/attributes', 'attributes', $options?->toQuery() ?? []);
    }

    public function allAttributes(?AttributeListOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Facts::allAttributes()', 'Facts::attributes()', $options?->cursor, $options->count ?? false, $max);

        return $this->attributes($options)->collect($max);
    }

    public function relations(?RelationListOptions $options = null): Page
    {
        /** @var Page<RelationDetailJson> */
        return $this->transport->requestPage($this->base() . '/relations', 'relations', $options?->toQuery() ?? []);
    }

    public function allRelations(?RelationListOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Facts::allRelations()', 'Facts::relations()', $options?->cursor, $options->count ?? false, $max);

        return $this->relations($options)->collect($max);
    }

    public function allEdgesOf(EntityRef|string $entity, ?string $label = null, ?int $limit = null): array
    {
        $ref = $entity instanceof EntityRef ? $entity : EntityRef::parse($entity);

        return [
            ...$this->allRelations(new RelationListOptions(src: $ref, label: $label, limit: $limit)),
            ...$this->allRelations(new RelationListOptions(dst: $ref, label: $label, limit: $limit)),
        ];
    }

    public function actions(?ActionListOptions $options = null): Page
    {
        /** @var Page<ActionDetailJson> */
        return $this->transport->requestPage($this->base() . '/actions', 'actions', $options?->toQuery() ?? []);
    }

    public function allActions(?ActionListOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Facts::allActions()', 'Facts::actions()', $options?->cursor, $options->count ?? false, $max);

        return $this->actions($options)->collect($max);
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId);
    }
}
