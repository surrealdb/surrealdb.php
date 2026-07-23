<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\DocumentKeywords;
use SurrealDB\Spectron\Payload;

/** Options for {@see DocumentKeywords::list()}. */
final readonly class KeywordListOptions
{
    /**
     * @param string|null $q                substring filter on the normalised keyword
     * @param int|null    $minDocumentCount only keywords linked to at least this many documents
     * @param string|null $sort             sort key
     * @param int|null    $page             1-based page number
     * @param int|null    $pageSize         rows per page
     */
    public function __construct(
        public ?string $q = null,
        public ?int $minDocumentCount = null,
        public ?string $sort = null,
        public ?int $page = null,
        public ?int $pageSize = null,
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
            'page' => $this->page,
            'pageSize' => $this->pageSize,
        ]);
    }
}
