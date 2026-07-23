<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\DocumentKeywords;
use SurrealDB\Spectron\Payload;

/** Options for {@see DocumentKeywords::search()}. */
final readonly class KeywordSearchOptions
{
    /**
     * @param string     $query     natural-language query (required)
     * @param int|null   $k         maximum number of keywords to return
     * @param float|null $threshold similarity floor for matches
     */
    public function __construct(
        public string $query,
        public ?int $k = null,
        public ?float $threshold = null,
    ) {
        if ($this->query === '') {
            throw new \InvalidArgumentException('KeywordSearchOptions requires a non-empty query.');
        }
    }

    /**
     * @internal wire fields for `POST /documents/keywords/search`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'query' => $this->query,
            'k' => $this->k,
            'threshold' => $this->threshold,
        ]);
    }
}
