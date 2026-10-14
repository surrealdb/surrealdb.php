<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory;

/** Options for {@see AgentMemory::consolidate()}. */
final readonly class ConsolidateOptions
{
    /**
     * @param bool     $dryRun           report what would consolidate without persisting
     * @param int|null $factLimit        cap on facts written per run
     * @param int|null $observationLimit cap on observations consumed per run
     */
    public function __construct(
        public bool $dryRun = false,
        public ?int $factLimit = null,
        public ?int $observationLimit = null,
    ) {}

    /**
     * @internal wire fields for `POST /consolidate`; `dryRun` is only sent when set
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'dryRun' => $this->dryRun ? true : null,
            'factLimit' => $this->factLimit,
            'observationLimit' => $this->observationLimit,
        ]);
    }
}
