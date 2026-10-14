<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Documents;
use SurrealDB\AgentMemory\Enum\DocumentStatus;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Documents::list()}. */
final readonly class DocumentListOptions
{
    /**
     * @param DocumentStatus|string|null $status   filter to one pipeline status
     * @param string|null                $mimeType filter to one MIME type
     * @param int|null                   $limit    documents per page (default 100, max 500)
     * @param string|null                $cursor   continuation token from a previous page's `nextCursor`
     * @param bool                       $count    also report the filtered total as `totalSize` (costs a full count)
     */
    public function __construct(
        public DocumentStatus|string|null $status = null,
        public ?string $mimeType = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {}

    /**
     * @internal query parameters for `GET /documents`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'status' => Payload::enum($this->status),
            'mimeType' => $this->mimeType,
        ]) + Payload::page($this->limit, $this->cursor, $this->count);
    }
}
