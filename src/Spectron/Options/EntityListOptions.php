<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Entities;
use SurrealDB\Spectron\Payload;

/** Options for {@see Entities::list()}. */
final readonly class EntityListOptions
{
    /**
     * @param string|null $type filter to one entity type (e.g. `person`)
     */
    public function __construct(
        public ?string $type = null,
    ) {}

    /**
     * @internal query parameters for `GET /entities`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact(['type' => $this->type]);
    }
}
