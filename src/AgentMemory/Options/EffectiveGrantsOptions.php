<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Principals;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Principals::effective()}. */
final readonly class EffectiveGrantsOptions
{
    /**
     * @param string      $path scope path to resolve grants at (required)
     * @param string|null $asOf historical resolution timestamp
     */
    public function __construct(
        public string $path,
        public ?string $asOf = null,
    ) {
        Guard::notBlank($this->path, 'EffectiveGrantsOptions requires a non-empty path.');
    }

    /**
     * @internal query parameters for `GET /principals/{id}/effective`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'path' => $this->path,
            'asOf' => $this->asOf,
        ]);
    }
}
