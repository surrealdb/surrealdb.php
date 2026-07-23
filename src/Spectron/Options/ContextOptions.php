<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Enum\ScopeView;
use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron\Scope;
use SurrealDB\Spectron;

/** Options for {@see Spectron::context()}. */
final readonly class ContextOptions
{
    /**
     * @param int|null                                   $k         maximum number of hits to draw context from
     * @param list<string>|null                          $labels    `key=value` label filter the source rows must all carry
     * @param string|array<int,string|list<string>>|null $lens      read lens: a DNF scope selector that narrows the read region
     * @param ScopeView|string|null                      $scopeView scope read breadth; defaults to `strict`
     */
    public function __construct(
        public ?int $k = null,
        public ?array $labels = null,
        public string|array|null $lens = null,
        public ScopeView|string|null $scopeView = null,
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
        ]);
    }
}
