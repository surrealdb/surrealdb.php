<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Enum\BatchExtractionMode;
use SurrealDB\AgentMemory\Enum\InferMode;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\Scope;
use SurrealDB\AgentMemory;

/** Options for {@see AgentMemory::rememberMany()}. */
final readonly class RememberManyOptions
{
    /**
     * @param string|null                                $sessionId existing session to attach the turns to (auto-created when absent)
     * @param string|array<int,string|list<string>>|null $scopes    DNF scope selector the batch targets (outer OR, inner AND)
     * @param BatchExtractionMode|string|null            $extract   bulk extraction strategy: `whole_conversation` (the server
     *                                                              default) runs one extraction over the transcript; `per_message`
     *                                                              runs one per message and honours each message's `addressees`
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
