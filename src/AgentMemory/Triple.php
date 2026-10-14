<?php

namespace SurrealDB\AgentMemory;

use SurrealDB\AgentMemory\Enum\InferMode;
use SurrealDB\AgentMemory\Enum\MemoryCategory;
use SurrealDB\AgentMemory\Options\RememberOptions;

/**
 * A caller-supplied fact, written verbatim by {@see AgentMemory::remember()}
 * when {@see RememberOptions::$infer} is {@see InferMode::TRIPLES} (no model
 * extraction runs). One of the three fact families:
 *
 * ```php
 * Triple::attribute('person/alice', 'role', 'CTO');                    // alice.role = CTO
 * Triple::relation('person/alice', 'works_at', 'company/acme');        // alice -[works_at]-> acme
 * Triple::action('person/alice', 'shipped', object: 'the v2 release'); // alice shipped the v2 release
 * ```
 *
 * Time is stated at most one way per fact: an explicit `occurredAt`
 * (RFC 3339, authoritative), or a verbatim `temporalHint` ("last Friday")
 * resolved against the request's `observedAt`; a relation may instead carry
 * an explicit `validFrom`/`validUntil` window.
 */
final readonly class Triple
{
    private function __construct(
        public EntityRef $entity,
        public ?string $key = null,
        public ?string $value = null,
        public ?EntityRef $target = null,
        public ?string $verb = null,
        public ?string $object = null,
        public ?string $summary = null,
        public ?float $confidence = null,
        public MemoryCategory|string|null $memoryCategory = null,
        public ?string $occurredAt = null,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
        public ?string $temporalHint = null,
        public ?string $sourceClause = null,
    ) {
        if ($this->occurredAt !== null && $this->temporalHint !== null) {
            throw new \InvalidArgumentException('A triple states its time once: pass occurredAt or temporalHint, not both.');
        }

        if ($this->validFrom !== null && ($this->occurredAt !== null || $this->temporalHint !== null)) {
            throw new \InvalidArgumentException('validFrom states when a relation starts; it cannot be combined with occurredAt or temporalHint.');
        }

        if ($this->confidence !== null && ($this->confidence < 0.0 || $this->confidence > 1.0)) {
            throw new \InvalidArgumentException("Triple confidence must lie in [0, 1], got {$this->confidence}.");
        }
    }

    /**
     * An attribute: `entity.key = value`.
     *
     * @param EntityRef|string           $entity         subject, as `<type>/<name>`
     * @param float|null                 $confidence     assertion confidence in `[0, 1]`; the upsert trust prior when omitted
     * @param string|null                $occurredAt     RFC 3339 time the value became true (sets its valid-from)
     * @param string|null                $temporalHint   verbatim phrase such as "since 2019", resolved server-side
     * @param string|null                $sourceClause   the source sentence stating this fact, kept for lexical search
     */
    public static function attribute(
        EntityRef|string $entity,
        string $key,
        string $value,
        ?float $confidence = null,
        MemoryCategory|string|null $memoryCategory = null,
        ?string $occurredAt = null,
        ?string $temporalHint = null,
        ?string $sourceClause = null,
    ): self {
        return new self(
            self::entity($entity),
            key: self::required('key', $key),
            value: $value,
            confidence: $confidence,
            memoryCategory: $memoryCategory,
            occurredAt: $occurredAt,
            temporalHint: $temporalHint,
            sourceClause: $sourceClause,
        );
    }

    /**
     * A relation: `entity -[label]-> target`.
     *
     * @param EntityRef|string $entity     subject, as `<type>/<name>`
     * @param string           $label      lowercase snake_case relation label (e.g. `works_at`)
     * @param EntityRef|string $target     object entity, as `<type>/<name>`
     * @param string|null      $validFrom  RFC 3339 start of the relation's world-time validity
     * @param string|null      $validUntil RFC 3339 end of the relation's world-time validity
     */
    public static function relation(
        EntityRef|string $entity,
        string $label,
        EntityRef|string $target,
        MemoryCategory|string|null $memoryCategory = null,
        ?string $occurredAt = null,
        ?string $validFrom = null,
        ?string $validUntil = null,
        ?string $temporalHint = null,
        ?string $sourceClause = null,
    ): self {
        return new self(
            self::entity($entity),
            key: self::required('label', $label),
            target: self::entity($target),
            memoryCategory: $memoryCategory,
            occurredAt: $occurredAt,
            validFrom: $validFrom,
            validUntil: $validUntil,
            temporalHint: $temporalHint,
            sourceClause: $sourceClause,
        );
    }

    /**
     * An action (event): `actor verb object`.
     *
     * @param EntityRef|string $actor      who acted, as `<type>/<name>`
     * @param string           $verb       lowercase snake_case verb phrase (e.g. `shipped`, `moved_to`)
     * @param string|null      $object     the acted-on thing's name; links to an entity when one resolves
     * @param string|null      $summary    one self-contained sentence the event is found by; synthesised when omitted
     * @param string|null      $occurredAt RFC 3339 instant the event happened
     */
    public static function action(
        EntityRef|string $actor,
        string $verb,
        ?string $object = null,
        ?string $summary = null,
        ?float $confidence = null,
        MemoryCategory|string|null $memoryCategory = null,
        ?string $occurredAt = null,
        ?string $temporalHint = null,
        ?string $sourceClause = null,
    ): self {
        return new self(
            self::entity($actor),
            verb: self::required('verb', $verb),
            object: $object,
            summary: $summary,
            confidence: $confidence,
            memoryCategory: $memoryCategory,
            occurredAt: $occurredAt,
            temporalHint: $temporalHint,
            sourceClause: $sourceClause,
        );
    }

    /**
     * @internal one entry of the `triples` array on `POST /facts`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'entity' => $this->entity->toPayload(),
            'key' => $this->key,
            'value' => $this->value,
            'target' => $this->target?->toPayload(),
            'verb' => $this->verb,
            'object' => $this->object,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'memory_category' => Payload::enum($this->memoryCategory),
            'occurred_at' => $this->occurredAt,
            'valid_from' => $this->validFrom,
            'valid_until' => $this->validUntil,
            'temporal_hint' => $this->temporalHint,
            'source_clause' => $this->sourceClause,
        ]);
    }

    private static function entity(EntityRef|string $ref): EntityRef
    {
        return $ref instanceof EntityRef ? $ref : EntityRef::parse($ref);
    }

    private static function required(string $field, string $value): string
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException("A triple's {$field} must be non-empty.");
        }

        return $value;
    }
}
