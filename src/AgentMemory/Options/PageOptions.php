<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Payload;

/**
 * Page parameters for a collection that takes no other filters. Usually only
 * `limit` and `count` are set by hand: {@see Page::next()} and
 * {@see Page::autoPaginate()} supply each `cursor` themselves.
 */
final readonly class PageOptions
{
    /**
     * @param int|null    $limit  rows per page (default 100, max 500)
     * @param string|null $cursor continuation token from a previous page's `nextCursor`
     * @param bool        $count  also report the total as `totalSize` (costs a full count)
     */
    public function __construct(
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {}

    /**
     * @internal query parameters shared by the paginated `GET` collections
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::page($this->limit, $this->cursor, $this->count);
    }
}
