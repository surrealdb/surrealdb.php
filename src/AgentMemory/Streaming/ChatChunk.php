<?php

namespace SurrealDB\AgentMemory\Streaming;

use SurrealDB\AgentMemory\Enum\ChatEvent;
use SurrealDB\AgentMemory\Schema\Schemas;
use function is_array;
use function is_string;

/**
 * One incremental frame from a streaming `chat` call.
 *
 * The server sends a `meta` frame first (trace and session ids), then a
 * `chunk` frame per piece of reply text, then one `done` frame carrying the
 * full `$reply`, its `$citations`, and the `$memoryUpdates`: the members
 * `chat()` returns. `$raw` keeps every decoded payload. The trace and session
 * ids are carried forward onto every later frame.
 *
 * @phpstan-import-type CitationJson from Schemas
 * @phpstan-import-type ExtractionResultJson from Schemas
 */
final readonly class ChatChunk
{
    /**
     * @param string                    $delta         reply text added by this frame (empty on `meta` and `done` frames)
     * @param string|null               $traceId       trace id, once the server has sent it
     * @param string|null               $sessionId     session id the conversation is attached to, once sent
     * @param bool                      $done          `true` on the terminal frame
     * @param array<string,mixed>       $raw           the raw decoded frame payload
     * @param ChatEvent|null            $event         the SSE event; null when the frame named none, or one this client does not know
     * @param string|null               $reply         the full reply text; terminal frame only
     * @param ExtractionResultJson|null $memoryUpdates what the turn wrote to memory; terminal frame only
     * @param list<CitationJson>|null   $citations     sources the reply cites; terminal frame only
     */
    public function __construct(
        public string $delta,
        public ?string $traceId,
        public ?string $sessionId,
        public bool $done,
        public array $raw,
        public ?ChatEvent $event = null,
        public ?string $reply = null,
        public ?array $memoryUpdates = null,
        public ?array $citations = null,
    ) {}

    /**
     * Builds a chunk from a decoded SSE frame payload. Reply text is read from
     * `text` (what the server sends), falling back to `delta`/`token`; ids
     * accept both camelCase and snake_case spellings. `reply`, `memoryUpdates`
     * and `citations` are read from the terminal frame only, and a value of
     * the wrong JSON type is left out (it stays in `raw`).
     *
     * @internal
     *
     * @param array<string,mixed> $payload
     * @param string|null         $traceId   the id carried forward from an earlier frame
     * @param string|null         $sessionId the id carried forward from an earlier frame
     */
    public static function fromFrame(
        array $payload,
        bool $done,
        ?ChatEvent $event = null,
        ?string $traceId = null,
        ?string $sessionId = null,
    ): self {
        /** @var ExtractionResultJson|null $memoryUpdates */
        $memoryUpdates = $done && is_array($payload['memoryUpdates'] ?? null) ? $payload['memoryUpdates'] : null;
        /** @var list<CitationJson>|null $citations */
        $citations = $done && is_array($payload['citations'] ?? null) && array_is_list($payload['citations'])
            ? $payload['citations']
            : null;

        return new self(
            delta: $done || $event === ChatEvent::META
                ? ''
                : self::string($payload, 'text') ?? self::string($payload, 'delta') ?? self::string($payload, 'token') ?? '',
            traceId: self::string($payload, 'traceId') ?? self::string($payload, 'trace_id') ?? $traceId,
            sessionId: self::string($payload, 'sessionId') ?? self::string($payload, 'session_id') ?? $sessionId,
            done: $done,
            raw: $payload,
            event: $event,
            reply: $done ? self::string($payload, 'reply') : null,
            memoryUpdates: $memoryUpdates,
            citations: $citations,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function string(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : null;
    }
}
