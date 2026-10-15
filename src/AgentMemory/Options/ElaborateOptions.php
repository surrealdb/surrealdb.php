<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see AgentMemory::elaborate()}: name one entity, or sweep. */
final readonly class ElaborateOptions
{
    /**
     * @param EntityRef|string|null $entityRef entity to elaborate from, as `<type>/<name>` (e.g. `person/tobie`)
     * @param int|null              $budget    edge-inference budget for the run
     * @param bool                  $sweep     sweep the low-relation entities instead of one
     * @param bool                  $dryRun    report what would be emitted without persisting
     */
    public function __construct(
        public EntityRef|string|null $entityRef = null,
        public ?int $budget = null,
        public bool $sweep = false,
        public bool $dryRun = false,
    ) {
        if ($this->entityRef === null && !$this->sweep) {
            throw new InvalidArgumentException('ElaborateOptions needs an entityRef to elaborate from, or sweep: true.');
        }
    }

    /**
     * @internal wire fields for `POST /elaborate`; flags are only sent when set
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'entityRef' => Payload::ref($this->entityRef),
            'budget' => $this->budget,
            'sweep' => $this->sweep ? true : null,
            'dryRun' => $this->dryRun ? true : null,
        ]);
    }
}
