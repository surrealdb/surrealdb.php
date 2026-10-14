<?php

namespace SurrealDB\AgentMemory;

use SurrealDB\AgentMemory\Enum\BatchExtractionMode;
use SurrealDB\AgentMemory\Enum\TurnRole;

/**
 * One conversation message for {@see AgentMemory::rememberMany()}.
 *
 * ```php
 * $memory->rememberMany([
 *     Message::user('I moved to Lisbon last month', addressees: ['Sam']),
 *     Message::assistant('Congratulations on the move!'),
 * ]);
 * ```
 */
final readonly class Message
{
    /**
     * @param TurnRole|string   $role       who sent the message
     * @param string            $content    what was said
     * @param list<string>|null $addressees who the message is addressed to, as entity names; grounds
     *                                      "you"/"we" at extraction (read by
     *                                      {@see BatchExtractionMode::PER_MESSAGE})
     * @param string|null       $ts         RFC 3339 time the message was sent
     */
    public function __construct(
        public TurnRole|string $role,
        public string $content,
        public ?array $addressees = null,
        public ?string $ts = null,
    ) {}

    /**
     * @param list<string>|null $addressees
     */
    public static function user(string $content, ?array $addressees = null, ?string $ts = null): self
    {
        return new self(TurnRole::USER, $content, $addressees, $ts);
    }

    /**
     * @param list<string>|null $addressees
     */
    public static function assistant(string $content, ?array $addressees = null, ?string $ts = null): self
    {
        return new self(TurnRole::ASSISTANT, $content, $addressees, $ts);
    }

    /**
     * @internal one entry of the `messages` array on `POST /facts/batch`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'role' => Payload::enum($this->role),
            'content' => $this->content,
            'addressees' => $this->addressees,
            'ts' => $this->ts,
        ]);
    }
}
