<?php

namespace SurrealDB\Spectron\Streaming;

use function is_string;

/** One incremental frame from a streaming `chat` call. */
final readonly class ChatChunk
{
    /**
     * @param string              $delta     token delta for this frame (may be empty on metadata-only frames)
     * @param string|null         $traceId   trace id, present once the server has assigned one
     * @param string|null         $sessionId session id the conversation is attached to
     * @param bool                $done      `true` on the terminal frame
     * @param array<string,mixed> $raw       the raw decoded frame payload
     */
    public function __construct(
        public string $delta,
        public ?string $traceId,
        public ?string $sessionId,
        public bool $done,
        public array $raw,
    ) {}

    /**
     * Builds a chunk from a decoded SSE frame payload, accepting both the
     * camelCase and snake_case field spellings the server emits.
     *
     * @param array<string,mixed> $payload
     */
    public static function fromFrame(array $payload, bool $done): self
    {
        return new self(
            self::string($payload, 'delta') ?? self::string($payload, 'token') ?? '',
            self::string($payload, 'traceId') ?? self::string($payload, 'trace_id'),
            self::string($payload, 'sessionId') ?? self::string($payload, 'session_id'),
            $done,
            $payload,
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
