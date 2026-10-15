<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see AgentMemory::forget()}. */
final readonly class ForgetOptions
{
    /**
     * @param bool $purge  right-to-be-forgotten: also remove the matching supersession history,
     *                     instead of keeping prior rows for audit
     * @param bool $dryRun only count what would be forgotten (`deleted`), writing nothing
     */
    public function __construct(
        public bool $purge = false,
        public bool $dryRun = false,
    ) {}

    /**
     * @internal wire fields for `POST /forget`; flags are only sent when set
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'dryRun' => $this->dryRun ? true : null,
            'purge' => $this->purge ? true : null,
        ]);
    }
}
