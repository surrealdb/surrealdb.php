<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Enum\MemoryQueryMode;
use SurrealDB\AgentMemory\Enum\ResultFamily;
use SurrealDB\AgentMemory\Enum\ScopeView;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\Scope;

/** Options for {@see AgentMemory::recall()}. */
final readonly class RecallOptions
{
    /**
     * @param int|null                                   $k                 maximum number of hits to return (default 10, max 50)
     * @param MemoryQueryMode|string|null                $mode              retrieval mode; defaults to `hybrid`
     * @param string|null                                $sessionId         session to scope the recall to
     * @param list<ResultFamily|string>|null             $include           result families to include; defaults to both
     * @param string|null                                $asOf              historical query timestamp (known time)
     * @param string|null                                $atInstant         system-time query instant (MVCC)
     * @param list<string>|null                          $labels            `key=value` label filter the result rows must all carry
     * @param string|array<int,string|list<string>>|null $lens              read lens: a DNF scope selector that narrows the read region
     * @param ScopeView|string|null                      $scopeView         scope read breadth; defaults to `strict`
     * @param string|null                                $validFrom         valid-time lower bound
     * @param string|null                                $validUntil        valid-time upper bound
     * @param string|null                                $source            free-form source label recorded on the trace
     * @param array<string,mixed>|null                   $location          geographic filter applied at read time
     * @param bool|null                                  $includeDuplicates also return chunks and turns flagged as near-duplicates of
     *                                                                      an older row (excluded by default)
     */
    public function __construct(
        public ?int $k = null,
        public MemoryQueryMode|string|null $mode = null,
        public ?string $sessionId = null,
        public ?array $include = null,
        public ?string $asOf = null,
        public ?string $atInstant = null,
        public ?array $labels = null,
        public string|array|null $lens = null,
        public ScopeView|string|null $scopeView = null,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
        public ?string $source = null,
        public ?array $location = null,
        public ?bool $includeDuplicates = null,
    ) {}

    /**
     * @internal wire fields for `POST /query`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'k' => $this->k,
            'mode' => Payload::enum($this->mode),
            'sessionId' => $this->sessionId,
            'include' => Payload::enums($this->include),
            'asOf' => $this->asOf,
            'atInstant' => $this->atInstant,
            'labels' => $this->labels,
            'lens' => Scope::normalise($this->lens),
            'scopeView' => Payload::enum($this->scopeView),
            'validFrom' => $this->validFrom,
            'validUntil' => $this->validUntil,
            'source' => $this->source,
            'location' => Payload::object($this->location),
            'includeDuplicates' => $this->includeDuplicates,
        ]);
    }
}
