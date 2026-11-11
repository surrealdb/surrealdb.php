<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Enum\ScopeView;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\Scope;

/** Options for {@see AgentMemory::context()}. */
final readonly class ContextOptions
{
    /**
     * @param int|null                                   $k         maximum number of hits to draw context from (default 10, max 50)
     * @param list<string>|null                          $labels    `key=value` label filter the source rows must all carry
     * @param string|array<int,string|list<string>>|null $lens      read lens: a DNF scope selector that narrows the read region
     * @param ScopeView|string|null                      $scopeView scope read breadth; defaults to `strict`
     * @param EntityRef|string|null                      $subject   render one subject's own facts (`<type>/<name>`) instead of the
     *                                                              query's fact hits; the query still selects the passages
     */
    public function __construct(
        public ?int $k = null,
        public ?array $labels = null,
        public string|array|null $lens = null,
        public ScopeView|string|null $scopeView = null,
        public EntityRef|string|null $subject = null,
    ) {}

    /**
     * @internal wire fields for `POST /context`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'k' => $this->k,
            'labels' => $this->labels,
            'lens' => Scope::normalise($this->lens),
            'scopeView' => Payload::enum($this->scopeView),
            'subject' => Payload::ref($this->subject),
        ]);
    }
}
