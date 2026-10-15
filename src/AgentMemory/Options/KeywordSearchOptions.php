<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\DocumentKeywords;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\Scope;

/** Options for {@see DocumentKeywords::search()}. */
final readonly class KeywordSearchOptions
{
    /**
     * @param string                                     $query     natural-language query (required)
     * @param int|null                                   $k         maximum number of keywords to return (default 10, max 50)
     * @param float|null                                 $threshold similarity floor (default 0.5); values of 0 or below fall back to
     *                                                              the default, so pass a small positive value to widen results
     * @param string|array<int,string|list<string>>|null $lens      read lens: a DNF scope selector that narrows the read region
     */
    public function __construct(
        public string $query,
        public ?int $k = null,
        public ?float $threshold = null,
        public string|array|null $lens = null,
    ) {
        if (trim($this->query) === '') {
            throw new InvalidArgumentException('KeywordSearchOptions requires a non-empty query.');
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
            'lens' => Scope::normalise($this->lens),
        ]);
    }
}
