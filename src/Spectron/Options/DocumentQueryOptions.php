<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Documents;
use SurrealDB\Spectron\Enum\QueryMode;
use SurrealDB\Spectron\Payload;

/** Options for {@see Documents::query()}. */
final readonly class DocumentQueryOptions
{
    /**
     * @param string                   $query          natural-language query (required)
     * @param int|null                 $k              max hits to return (default 10, max 50)
     * @param QueryMode|string|null    $mode           chunk query mode; defaults to `hybrid`
     * @param float|null               $threshold      similarity floor for vector hits
     * @param float|null               $vectorWeight   vector share in the hybrid blend
     * @param float|null               $rrfK           reciprocal-rank-fusion constant
     * @param array<string,mixed>|null $filter         structured metadata filter
     * @param bool|null                $expandGraph    follow graph edges from the initial hits
     * @param int|null                 $graphDepth     maximum edge distance when expanding
     * @param float|null               $graphAlpha     decay per hop when expanding
     * @param list<string>|null        $graphEdges     graph edge kinds followed by `expandGraph`
     * @param array<string,mixed>|null $location       geographic filter applied at read time
     * @param bool|null                $decomposeQuery opt into sub-question decomposition
     * @param bool|null                $useHyde        opt into HyDE query expansion
     * @param bool|null                $useReranker    opt into reranking the merged hits
     */
    public function __construct(
        public string $query,
        public ?int $k = null,
        public QueryMode|string|null $mode = null,
        public ?float $threshold = null,
        public ?float $vectorWeight = null,
        public ?float $rrfK = null,
        public ?array $filter = null,
        public ?bool $expandGraph = null,
        public ?int $graphDepth = null,
        public ?float $graphAlpha = null,
        public ?array $graphEdges = null,
        public ?array $location = null,
        public ?bool $decomposeQuery = null,
        public ?bool $useHyde = null,
        public ?bool $useReranker = null,
    ) {
        if ($this->query === '') {
            throw new \InvalidArgumentException('DocumentQueryOptions requires a non-empty query.');
        }
    }

    /**
     * @internal wire fields for `POST /documents/query`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'query' => $this->query,
            'k' => $this->k,
            'mode' => Payload::enum($this->mode),
            'threshold' => $this->threshold,
            'vectorWeight' => $this->vectorWeight,
            'rrfK' => $this->rrfK,
            'filter' => $this->filter,
            'expandGraph' => $this->expandGraph,
            'graphDepth' => $this->graphDepth,
            'graphAlpha' => $this->graphAlpha,
            'graphEdges' => $this->graphEdges,
            'location' => $this->location,
            'decomposeQuery' => $this->decomposeQuery,
            'useHyde' => $this->useHyde,
            'useReranker' => $this->useReranker,
        ]);
    }
}
