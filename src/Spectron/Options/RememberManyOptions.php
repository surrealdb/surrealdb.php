<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Enum\BatchExtractionMode;
use SurrealDB\Spectron\Enum\InferMode;
use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron\Scope;
use SurrealDB\Spectron;

/** Options for {@see Spectron::rememberMany()}. */
final readonly class RememberManyOptions
{
    /**
     * @param string|null                                $sessionId existing session to attach the turns to (auto-created when absent)
     * @param string|array<int,string|list<string>>|null $scopes    DNF scope selector the batch targets (outer OR, inner AND)
     * @param BatchExtractionMode|string|null            $extract   bulk extraction strategy
     * @param InferMode|string|null                      $infer     inference mode
     * @param list<string>|null                          $labels    descriptive `key=value` labels for the persisted rows
     */
    public function __construct(
        public ?string $sessionId = null,
        public string|array|null $scopes = null,
        public BatchExtractionMode|string|null $extract = null,
        public InferMode|string|null $infer = null,
        public ?array $labels = null,
    ) {}

    /**
     * @internal wire fields for `POST /facts/batch`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'session_id' => $this->sessionId,
            'scopes' => Scope::normalise($this->scopes),
            'extract' => Payload::enum($this->extract),
            'infer' => Payload::enum($this->infer),
            'labels' => $this->labels,
        ]);
    }
}
