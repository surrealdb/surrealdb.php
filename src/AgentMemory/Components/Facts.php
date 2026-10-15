<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Guard;
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
 * span both `src` and `dst` ({@see allEdgesOf()} walks both), and its
 * `events` are actions.
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

    /**
     * Every attribute matching `$options`, walking the pages in order
     * (`GET /attributes`). With `$max` the walk stops after the page that
     * reaches it, so up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many attributes are in (at least 1)
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     */
    public function allAttributes(?AttributeListOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Facts::allAttributes()', 'Facts::attributes()', $options?->cursor, $options->count ?? false, $max);

        return $this->attributes($options)->collect($max);
    }

    /** A page of relations, optionally by subject, object, or label. */
    public function relations(?RelationListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base() . '/relations', 'relations', $options?->toQuery() ?? []);
    }

    /**
     * Every relation matching `$options`, walking the pages in order
     * (`GET /relations`). With `$max` the walk stops after the page that
     * reaches it, so up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many relations are in (at least 1)
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     */
    public function allRelations(?RelationListOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Facts::allRelations()', 'Facts::relations()', $options?->cursor, $options->count ?? false, $max);

        return $this->relations($options)->collect($max);
    }

    /**
     * Every edge touching an entity, in both directions: the `src` walk, then
     * the `dst` walk, outbound first and with no de-duplication, so a
     * self-loop appears twice. This is the full set behind lookup's bounded
     * `relations` section. JS runs the two walks concurrently; here they run
     * in turn, in the same order.
     *
     * @param EntityRef|string $entity the subject, as `<type>/<name>`
     * @param string|null      $label  only edges with this label
     * @param int|null         $limit  relations per page
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$entity` is not a `<type>/<name>` ref
     */
    public function allEdgesOf(EntityRef|string $entity, ?string $label = null, ?int $limit = null): array
    {
        $ref = $entity instanceof EntityRef ? $entity : EntityRef::parse($entity);

        return [
            ...$this->allRelations(new RelationListOptions(src: $ref, label: $label, limit: $limit)),
            ...$this->allRelations(new RelationListOptions(dst: $ref, label: $label, limit: $limit)),
        ];
    }

    /** A page of actions, optionally by actor, verb, or time window. */
    public function actions(?ActionListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base() . '/actions', 'actions', $options?->toQuery() ?? []);
    }

    /**
     * Every action matching `$options`, walking the pages in order
     * (`GET /actions`). With `$max` the walk stops after the page that
     * reaches it, so up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many actions are in (at least 1)
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     */
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
