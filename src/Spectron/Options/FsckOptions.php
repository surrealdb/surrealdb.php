<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron;

/** Options for {@see Spectron::fsck()}. */
final readonly class FsckOptions
{
    /**
     * @param string|null $check              restrict the run to one named check
     * @param float|null  $duplicateThreshold similarity threshold for duplicate detection
     * @param int|null    $maxResults         cap on reported findings
     */
    public function __construct(
        public ?string $check = null,
        public ?float $duplicateThreshold = null,
        public ?int $maxResults = null,
    ) {}

    /**
     * @internal wire fields for `POST /fsck`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'check' => $this->check,
            'duplicateThreshold' => $this->duplicateThreshold,
            'maxResults' => $this->maxResults,
        ]);
    }
}
