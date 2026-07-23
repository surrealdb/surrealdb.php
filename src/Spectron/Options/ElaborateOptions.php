<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron;

/** Options for {@see Spectron::elaborate()}. */
final readonly class ElaborateOptions
{
    /**
     * @param string|null $entityRef entity to elaborate from (e.g. `person:tobie`)
     * @param int|null    $budget    edge-inference budget for the run
     * @param bool        $sweep     sweep all entities instead of one
     * @param bool        $dryRun    report what would be emitted without persisting
     */
    public function __construct(
        public ?string $entityRef = null,
        public ?int $budget = null,
        public bool $sweep = false,
        public bool $dryRun = false,
    ) {}

    /**
     * @internal wire fields for `POST /elaborate`; flags are only sent when set
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'entityRef' => $this->entityRef,
            'budget' => $this->budget,
            'sweep' => $this->sweep ? true : null,
            'dryRun' => $this->dryRun ? true : null,
        ]);
    }
}
