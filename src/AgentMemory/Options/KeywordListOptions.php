<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\DocumentKeywords;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see DocumentKeywords::list()}. */
final readonly class KeywordListOptions
{
    /**
     * @param string|null $q                case-insensitive substring filter on the keyword text
     * @param int|null    $minDocumentCount only keywords linked to at least this many documents
     * @param string|null $sort             sort key; a cursor is only valid for the sort it was minted under
     * @param int|null    $limit            keywords per page (default 100, max 500)
     * @param string|null $cursor           continuation token from a previous page's `nextCursor`
     * @param bool        $count            also report the filtered total as `totalSize` (costs a full count)
     */
    public function __construct(
        public ?string $q = null,
        public ?int $minDocumentCount = null,
        public ?string $sort = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {}

    /**
     * @internal query parameters for `GET /documents/keywords`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'q' => $this->q,
            'minDocumentCount' => $this->minDocumentCount,
            'sort' => $this->sort,
        ]) + Payload::page($this->limit, $this->cursor, $this->count);
    }
}
