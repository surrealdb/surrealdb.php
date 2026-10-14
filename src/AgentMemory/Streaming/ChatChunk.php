<?php

namespace SurrealDB\AgentMemory\Streaming;

use function is_string;

/**
 * One incremental frame from a streaming `chat` call.
 *
 * The server sends a `meta` frame first (trace and session ids), then a
 * `chunk` frame per piece of reply text, then one `done` frame whose `raw`
 * payload holds the full `reply`, its `citations`, and the `memoryUpdates`.
 * The trace and session ids are carried forward onto every later frame.
 */
final readonly class ChatChunk
{
    public const string EVENT_META = 'meta';

    public const string EVENT_CHUNK = 'chunk';

    public const string EVENT_DONE = 'done';

    /**
     * @param string              $delta     reply text added by this frame (empty on `meta` and `done` frames)
     * @param string|null         $traceId   trace id, once the server has sent it
     * @param string|null         $sessionId session id the conversation is attached to, once sent
     * @param bool                $done      `true` on the terminal frame
     * @param array<string,mixed> $raw       the raw decoded frame payload
     * @param string|null         $event     the SSE event name (`meta`, `chunk`, `done`), when the frame named one
     */
    public function __construct(
        public string $delta,
        public ?string $traceId,
        public ?string $sessionId,
        public bool $done,
        public array $raw,
        public ?string $event = null,
    ) {}

    /**
     * Builds a chunk from a decoded SSE frame payload. Reply text is read from
     * `text` (what the server sends), falling back to `delta`/`token`; ids
     * accept both camelCase and snake_case spellings.
     *
     * @param array<string,mixed> $payload
     * @param string|null         $traceId   the id carried forward from an earlier frame
     * @param string|null         $sessionId the id carried forward from an earlier frame
     */
    public static function fromFrame(
        array $payload,
        bool $done,
        ?string $event = null,
        ?string $traceId = null,
        ?string $sessionId = null,
    ): self {
        return new self(
            $done || $event === self::EVENT_META
                ? ''
                : self::string($payload, 'text') ?? self::string($payload, 'delta') ?? self::string($payload, 'token') ?? '',
            self::string($payload, 'traceId') ?? self::string($payload, 'trace_id') ?? $traceId,
            self::string($payload, 'sessionId') ?? self::string($payload, 'session_id') ?? $sessionId,
            $done,
            $payload,
            $event,
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
