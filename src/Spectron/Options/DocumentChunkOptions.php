<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Documents;
use SurrealDB\Spectron\Payload;

/** Options for {@see Documents::chunks()}. */
final readonly class DocumentChunkOptions
{
    /**
     * @param int|null $page     1-based page number
     * @param int|null $pageSize rows per page
     */
    public function __construct(
        public ?int $page = null,
        public ?int $pageSize = null,
    ) {}

    /**
     * @internal query parameters for `GET /documents/{id}/chunks`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'page' => $this->page,
            'pageSize' => $this->pageSize,
        ]);
    }
}
