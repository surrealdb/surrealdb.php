<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\ActionListOptions;
use SurrealDB\AgentMemory\Options\AttributeListOptions;
use SurrealDB\AgentMemory\Options\RelationListOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * The stored fact families as walkable collections: attributes
 * (`entity.key = value`), relations (labelled edges between entities), and
 * actions (events an entity performed). Each row carries its confidence,
 * scope, labels, and a templated `summary` sentence.
 *
 * These are the full walks behind the bounded sections of
 * {@see AgentMemoryInterface::lookup()}: its `facts` are attributes, its
 * `relations` span both `src` and `dst` ({@see allEdgesOf()} walks both), and
 * its `events` are actions.
 *
 * @phpstan-import-type ActionDetailJson from Schemas
 * @phpstan-import-type AttributeDetailJson from Schemas
 * @phpstan-import-type RelationDetailJson from Schemas
 */
interface FactsInterface
{
    /**
     * A page of attributes, optionally for one entity or key.
     *
     * @return Page<AttributeDetailJson>
     */
    public function attributes(?AttributeListOptions $options = null): Page;

    /**
     * Every attribute matching `$options`, walking the pages in order
     * (`GET /attributes`). With `$max` the walk stops after the page that
     * reaches it, so up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many attributes are in (at least 1)
     *
     * @return list<AttributeDetailJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     * @throws AgentMemoryException     when the request fails
     */
    public function allAttributes(?AttributeListOptions $options = null, ?int $max = null): array;

    /**
     * A page of relations, optionally by subject, object, or label.
     *
     * @return Page<RelationDetailJson>
     */
    public function relations(?RelationListOptions $options = null): Page;

    /**
     * Every relation matching `$options`, walking the pages in order
     * (`GET /relations`). With `$max` the walk stops after the page that
     * reaches it, so up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many relations are in (at least 1)
     *
     * @return list<RelationDetailJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     * @throws AgentMemoryException     when the request fails
     */
    public function allRelations(?RelationListOptions $options = null, ?int $max = null): array;

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
     * @return list<RelationDetailJson>
     *
     * @throws InvalidArgumentException when `$entity` is not a `<type>/<name>` ref
     * @throws AgentMemoryException     when the request fails
     */
    public function allEdgesOf(EntityRef|string $entity, ?string $label = null, ?int $limit = null): array;

    /**
     * A page of actions, optionally by actor, verb, or time window.
     *
     * @return Page<ActionDetailJson>
     */
    public function actions(?ActionListOptions $options = null): Page;

    /**
     * Every action matching `$options`, walking the pages in order
     * (`GET /actions`). With `$max` the walk stops after the page that
     * reaches it, so up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many actions are in (at least 1)
     *
     * @return list<ActionDetailJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     * @throws AgentMemoryException     when the request fails
     */
    public function allActions(?ActionListOptions $options = null, ?int $max = null): array;
}
