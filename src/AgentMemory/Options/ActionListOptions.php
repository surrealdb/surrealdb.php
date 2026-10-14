<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Facts;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Facts::actions()}. */
final readonly class ActionListOptions
{
    /**
     * @param EntityRef|string|null $actor  filter by acting entity, as `<type>/<name>`
     * @param string|null           $verb   filter by action verb
     * @param string|null           $since  inclusive lower bound on when the action occurred (RFC 3339)
     * @param string|null           $until  inclusive upper bound on when the action occurred (RFC 3339)
     * @param int|null              $limit  rows per page (default 100, max 500)
     * @param string|null           $cursor continuation token from a previous page's `nextCursor`
     * @param bool                  $count  also report the filtered total as `totalSize` (costs a full count)
     */
    public function __construct(
        public EntityRef|string|null $actor = null,
        public ?string $verb = null,
        public ?string $since = null,
        public ?string $until = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {}

    /**
     * @internal query parameters for `GET /actions`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'actor' => Payload::ref($this->actor),
            'verb' => $this->verb,
            'since' => $this->since,
            'until' => $this->until,
        ]) + Payload::page($this->limit, $this->cursor, $this->count);
    }
}
