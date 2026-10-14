<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Options\ActionListOptions;
use SurrealDB\AgentMemory\Options\AttributeListOptions;
use SurrealDB\AgentMemory\Options\RelationListOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/**
 * The stored fact families as walkable collections: attributes
 * (`entity.key = value`), relations (labelled edges between entities), and
 * actions (events an entity performed). Each row carries its confidence,
 * scope, labels, and a templated `summary` sentence.
 *
 * These are the full walks behind the bounded sections of
 * {@see AgentMemory::lookup()}: its `facts` are attributes, its `relations`
 * span both `src` and `dst`, and its `events` are actions.
 */
final class Facts
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** A page of attributes, optionally for one entity or key. */
    public function attributes(?AttributeListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base() . '/attributes', 'attributes', $options?->toQuery() ?? []);
    }

    /** A page of relations, optionally by subject, object, or label. */
    public function relations(?RelationListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base() . '/relations', 'relations', $options?->toQuery() ?? []);
    }

    /** A page of actions, optionally by actor, verb, or time window. */
    public function actions(?ActionListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base() . '/actions', 'actions', $options?->toQuery() ?? []);
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId);
    }
}
