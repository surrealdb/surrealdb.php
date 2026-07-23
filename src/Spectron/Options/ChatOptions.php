<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron\Scope;
use SurrealDB\Spectron;

/** Options for {@see Spectron::chat()} and {@see Spectron::chatStream()}. */
final readonly class ChatOptions
{
    /**
     * @param string|null                                $sessionId   session to attach the conversation to
     * @param string|array<int,string|list<string>>|null $scopes      DNF scope selector for the conversation (outer OR, inner AND)
     * @param string|null                                $model       model override
     * @param bool                                       $bypassCache skip the response cache and force a fresh call
     * @param list<string>|null                          $labels      descriptive `key=value` labels for rows the chat persists
     */
    public function __construct(
        public ?string $sessionId = null,
        public string|array|null $scopes = null,
        public ?string $model = null,
        public bool $bypassCache = false,
        public ?array $labels = null,
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
        ]);
    }
}
