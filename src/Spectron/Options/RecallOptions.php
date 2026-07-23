<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Enum\ScopeView;
use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron\Scope;
use SurrealDB\Spectron;

/** Options for {@see Spectron::recall()}. */
final readonly class RecallOptions
{
    /**
     * @param int|null                                   $k          maximum number of hits to return
     * @param string|null                                $mode       retrieval mode; defaults to `hybrid`
     * @param string|null                                $sessionId  session to scope the recall to
     * @param list<string>|null                          $include    result families to include (`facts`, `passages`); defaults to both
     * @param string|null                                $asOf       historical query timestamp (known/valid time)
     * @param string|null                                $atInstant  system-time query instant (MVCC)
     * @param list<string>|null                          $labels     `key=value` label filter the result rows must all carry
     * @param string|array<int,string|list<string>>|null $lens       read lens: a DNF scope selector that narrows the read region
     * @param ScopeView|string|null                      $scopeView  scope read breadth; defaults to `strict`
     * @param string|null                                $validFrom  valid-time lower bound
     * @param string|null                                $validUntil valid-time upper bound
     * @param string|null                                $source     free-form source label recorded on the trace
     * @param array<string,mixed>|null                   $location   geographic filter applied at read time
     */
    public function __construct(
        public ?int $k = null,
        public ?string $mode = null,
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
            'mode' => $this->mode,
            'sessionId' => $this->sessionId,
            'include' => $this->include,
            'asOf' => $this->asOf,
            'atInstant' => $this->atInstant,
            'labels' => $this->labels,
            'lens' => Scope::normalise($this->lens),
            'scopeView' => Payload::enum($this->scopeView),
            'validFrom' => $this->validFrom,
            'validUntil' => $this->validUntil,
            'source' => $this->source,
            'location' => $this->location,
        ]);
    }
}
