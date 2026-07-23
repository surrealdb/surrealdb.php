<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Enum\InferMode;
use SurrealDB\Spectron\Enum\MemoryCategory;
use SurrealDB\Spectron\Enum\TurnRole;
use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron\Scope;
use SurrealDB\Spectron;

/** Options for {@see Spectron::remember()}. */
final readonly class RememberOptions
{
    /**
     * @param InferMode|string|null                      $infer          inference mode; `full` is the server default
     * @param string|null                                $sessionId      existing session to attach the turn to (auto-created when absent)
     * @param string|array<int,string|list<string>>|null $scopes         DNF scope selector the write targets (outer OR, inner AND)
     * @param TurnRole|string|null                       $role           role to record on the turn; `user` by default
     * @param MemoryCategory|string|null                 $memoryCategory override the memory category for extracted/triple facts
     * @param list<string>|null                          $labels         descriptive `key=value` labels for the persisted rows
     * @param list<array<string,mixed>>|null             $triples        caller-supplied triples (consumed when `infer` is `triples`)
     */
    public function __construct(
        public InferMode|string|null $infer = null,
        public ?string $sessionId = null,
        public string|array|null $scopes = null,
        public TurnRole|string|null $role = null,
        public MemoryCategory|string|null $memoryCategory = null,
        public ?array $labels = null,
        public ?array $triples = null,
    ) {}

    /**
     * @internal wire fields for `POST /facts`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'infer' => Payload::enum($this->infer),
            'session_id' => $this->sessionId,
            'scopes' => Scope::normalise($this->scopes),
            'role' => Payload::enum($this->role),
            'memory_category' => Payload::enum($this->memoryCategory),
            'labels' => $this->labels,
            'triples' => $this->triples,
        ]);
    }
}
