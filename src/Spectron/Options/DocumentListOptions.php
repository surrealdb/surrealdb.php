<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Documents;
use SurrealDB\Spectron\Enum\DocumentStatus;
use SurrealDB\Spectron\Payload;

/** Options for {@see Documents::list()}. */
final readonly class DocumentListOptions
{
    /**
     * @param DocumentStatus|string|null $status   filter to one pipeline status
     * @param string|null                $mimeType filter to one MIME type
     * @param int|null                   $page     1-based page number
     * @param int|null                   $pageSize rows per page
     */
    public function __construct(
        public DocumentStatus|string|null $status = null,
        public ?string $mimeType = null,
        public ?int $page = null,
        public ?int $pageSize = null,
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
            'page' => $this->page,
            'pageSize' => $this->pageSize,
        ]);
    }
}
