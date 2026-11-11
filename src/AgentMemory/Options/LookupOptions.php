<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Enum\LookupSection;
use SurrealDB\AgentMemory\Payload;

/**
 * Options for {@see AgentMemory::lookup()}. Every section is bounded by its
 * limit and flags `truncated` when cut; walk the matching collection
 * ({@see AgentMemory::$facts}, {@see AgentMemory::$uncertainty}) for the rest.
 */
final readonly class LookupOptions
{
    /**
     * @param EntityRef|string|null           $subject          skip resolution and answer about this subject (`<type>/<name>`)
     * @param list<LookupSection|string>|null $include          sections to fill; defaults to every section but `passages`
     * @param string|null                     $entityType       restrict resolution to one entity type
     * @param float|null                      $ambiguityMargin  how far the top candidate must beat the runner-up to resolve
     *                                                          to one entity rather than `ambiguous` (default 0.15)
     * @param int|null                        $factLimit        attributes to return (default 6)
     * @param int|null                        $relationLimit    relations to return (default 12)
     * @param int|null                        $eventLimit       actions to return (default 3)
     * @param int|null                        $passageLimit     passages to return (default 3)
     * @param int|null                        $uncertaintyLimit open questions to return (default 10)
     */
    public function __construct(
        public EntityRef|string|null $subject = null,
        public ?array $include = null,
        public ?string $entityType = null,
        public ?float $ambiguityMargin = null,
        public ?int $factLimit = null,
        public ?int $relationLimit = null,
        public ?int $eventLimit = null,
        public ?int $passageLimit = null,
        public ?int $uncertaintyLimit = null,
    ) {}

    /**
     * @internal wire fields for `POST /lookup`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'subject' => Payload::ref($this->subject),
            'include' => Payload::enums($this->include),
            'entityType' => $this->entityType,
            'ambiguityMargin' => $this->ambiguityMargin,
            'factLimit' => $this->factLimit,
            'relationLimit' => $this->relationLimit,
            'eventLimit' => $this->eventLimit,
            'passageLimit' => $this->passageLimit,
            'uncertaintyLimit' => $this->uncertaintyLimit,
        ]);
    }
}
