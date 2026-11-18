<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Enum\InferMode;
use SurrealDB\AgentMemory\Enum\MemoryCategory;
use SurrealDB\AgentMemory\Enum\TurnRole;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\Scope;
use SurrealDB\AgentMemory\Triple;

/** Options for {@see AgentMemory::remember()}. */
final readonly class RememberOptions
{
    /**
     * @param InferMode|string|null                      $infer          inference mode; `full` is the server default
     * @param string|null                                $sessionId      existing session to attach the turn to (auto-created when absent)
     * @param string|array<int,string|list<string>>|null $scopes         DNF scope selector the write targets (outer OR, inner AND)
     * @param TurnRole|string|null                       $role           role to record on the turn; `user` by default
     * @param MemoryCategory|string|null                 $memoryCategory override the memory category for extracted/triple facts
     * @param list<string>|null                          $labels         descriptive `key=value` labels for the persisted rows
     * @param list<Triple|array<string,mixed>>|null      $triples        caller-supplied facts, consumed when `infer` is `triples`
     * @param string|null                                $observedAt     RFC 3339 time the content was observed: back-dates the facts' known
     *                                                                   time and anchors relative phrases ("yesterday") in triples
     * @param list<string>|null                          $addressees     who the turn is addressed to, as entity names; grounds "you"/"we"
     *                                                                   during `full`/`preview` extraction (at most 8 names)
     */
    public function __construct(
        public InferMode|string|null $infer = null,
        public ?string $sessionId = null,
        public string|array|null $scopes = null,
        public TurnRole|string|null $role = null,
        public MemoryCategory|string|null $memoryCategory = null,
        public ?array $labels = null,
        public ?array $triples = null,
        public ?string $observedAt = null,
        public ?array $addressees = null,
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
            'triples' => $this->triples === null ? null : array_map(
                static fn (Triple|array $triple): array => $triple instanceof Triple ? $triple->toPayload() : $triple,
                $this->triples,
            ),
            'observed_at' => $this->observedAt,
            'addressees' => $this->addressees,
        ]);
    }
}
