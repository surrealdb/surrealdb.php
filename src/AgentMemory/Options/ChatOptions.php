<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\Scope;

/** Options for {@see AgentMemory::chat()} and {@see AgentMemory::chatStream()}. */
final readonly class ChatOptions
{
    /**
     * @param string|null                                $sessionId       session to attach the conversation to
     * @param string|array<int,string|list<string>>|null $scopes          DNF scope selector for the conversation (outer OR, inner AND)
     * @param string|null                                $model           model override
     * @param bool                                       $bypassCache     skip the response cache and force a fresh call
     * @param list<string>|null                          $labels          descriptive `key=value` labels for rows the chat persists
     * @param bool                                       $suppressMarkers strip inline citation markers (`[S1]`) from the reply text;
     *                                                                    `citations` still lists every cited source
     */
    public function __construct(
        public ?string $sessionId = null,
        public string|array|null $scopes = null,
        public ?string $model = null,
        public bool $bypassCache = false,
        public ?array $labels = null,
        public bool $suppressMarkers = false,
    ) {}

    /**
     * @internal wire fields for `POST /chat`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'sessionId' => $this->sessionId,
            'scopes' => Scope::normalise($this->scopes),
            'model' => $this->model,
            'bypassCache' => $this->bypassCache ? true : null,
            'labels' => $this->labels,
            'suppressMarkers' => $this->suppressMarkers ? true : null,
        ]);
    }
}
