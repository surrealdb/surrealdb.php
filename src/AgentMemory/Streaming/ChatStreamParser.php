<?php

namespace SurrealDB\AgentMemory\Streaming;

use Psr\Http\Message\StreamInterface;
use SurrealDB\AgentMemory\Enum\ChatEvent;
use SurrealDB\AgentMemory\Exceptions\StreamException;
use SurrealDB\AgentMemory\Transport;
use function is_array;
use function is_string;
use function strlen;

/**
 * Parses a server-sent-event chat response into {@see ChatChunk}s.
 *
 * The server names each frame with an `event:` line and carries a JSON
 * `data:` payload: `meta` (`traceId`, `sessionId`), `chunk` (`text`), `done`
 * (`reply`, `citations`, `memoryUpdates`), and `error` (`error`), which is
 * raised as a {@see StreamException}. A frame named with an event this client
 * does not know is still yielded, with a null {@see ChatChunk::$event}.
 * Multi-line `data:` payloads, comment keep-alives, and the `[DONE]` sentinel
 * some proxies append are handled.
 * Chunks are yielded as the underlying stream delivers them, so an HTTP client
 * that streams responses surfaces tokens incrementally while a buffering
 * client yields them all at once.
 *
 * A reply is complete only once its terminal frame arrives. A stream that
 * ends before it (the connection dropped, or the server's task died) raises a
 * {@see StreamException} rather than passing off a partial reply as whole; as
 * the SSE specification requires, a trailing frame cut off before its blank
 * line is discarded, never parsed.
 *
 * Public, like `parseChatStream()` in `@surrealdb/memory`, for a response
 * opened with {@see Transport::requestStream()}.
 */
final class ChatStreamParser
{
    private const int READ_CHUNK_BYTES = 8192;

    private const string EVENT_ERROR = 'error';

    /**
     * @return \Generator<int,ChatChunk>
     *
     * @throws StreamException when the server sends an `error` frame, or the stream ends before its terminal frame
     */
    public function parse(StreamInterface $body): \Generator
    {
        $buffer = '';
        $traceId = null;
        $sessionId = null;

        while (!$body->eof()) {
            $buffer .= $body->read(self::READ_CHUNK_BYTES);

            while (($frame = $this->nextFrame($buffer)) !== null) {
                $chunk = $this->toChunk($frame, $traceId, $sessionId);

                if ($chunk === null) {
                    continue;
                }

                $traceId = $chunk->traceId;
                $sessionId = $chunk->sessionId;

                yield $chunk;

                if ($chunk->done) {
                    return;
                }
            }
        }

        throw new StreamException(
            'Chat stream ended before the final frame',
            0,
            trim($buffer) === '' ? 'the connection closed without a done event' : 'the last frame was cut off mid-way',
        );
    }

    /**
     * Splits the next complete frame off the buffer. Frames are separated by a
     * blank line; an incomplete trailing frame stays buffered.
     */
    private function nextFrame(string &$buffer): ?string
    {
        if (preg_match('/\r?\n\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$separator, $offset] = $match[0];
        $frame = substr($buffer, 0, (int) $offset);
        $buffer = substr($buffer, (int) $offset + strlen($separator));

        return $frame;
    }

    private function toChunk(string $frame, ?string $traceId, ?string $sessionId): ?ChatChunk
    {
        $name = null;
        $dataLines = [];

        foreach (preg_split('/\r?\n/', $frame) ?: [] as $line) {
            if (str_starts_with($line, ':')) {
                continue; // comment / keep-alive
            }

            if (str_starts_with($line, 'event:')) {
                $name = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                // Per the SSE spec only one space after the colon is syntax.
                $value = substr($line, 5);
                $dataLines[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
            }
        }

        if ($dataLines === []) {
            return null;
        }

        $data = implode("\n", $dataLines);
        $event = $name === null ? null : ChatEvent::tryFrom($name);

        if ($data === '[DONE]') {
            return new ChatChunk('', $traceId, $sessionId, true, [], $event);
        }

        $decoded = json_decode($data, true);

        if ($name === self::EVENT_ERROR) {
            $message = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : $data;

            throw new StreamException('Chat stream failed', 0, $message, extensions: is_array($decoded) ? $decoded : []);
        }

        $payload = is_array($decoded) ? $decoded : ['text' => $data];
        $done = $event === ChatEvent::DONE || ($payload['done'] ?? null) === true;

        return ChatChunk::fromFrame($payload, $done, $event, $traceId, $sessionId);
    }
}
