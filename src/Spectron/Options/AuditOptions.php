<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron;

/** Options for {@see Spectron::audit()}. */
final readonly class AuditOptions
{
    /**
     * @param string|null $principal filter to one principal id
     * @param string|null $key       filter to one API key id
     * @param string|null $kind      filter to one activity kind (e.g. `write`, `recall`)
     * @param string|null $since     inclusive lower time bound
     * @param string|null $until     exclusive upper time bound
     * @param int|null    $limit     maximum number of rows to return
     */
    public function __construct(
        public ?string $principal = null,
        public ?string $key = null,
        public ?string $kind = null,
        public ?string $since = null,
        public ?string $until = null,
        public ?int $limit = null,
    ) {}

    /**
     * @internal query parameters for `GET /audit`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'principal' => $this->principal,
            'key' => $this->key,
            'kind' => $this->kind,
            'since' => $this->since,
            'until' => $this->until,
            'limit' => $this->limit,
        ]);
    }
}
