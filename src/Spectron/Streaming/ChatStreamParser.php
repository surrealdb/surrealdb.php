<?php

namespace SurrealDB\Spectron\Streaming;

use Psr\Http\Message\StreamInterface;
use function is_array;
use function strlen;

/**
 * Parses a server-sent-event response body into {@see ChatChunk}s. Handles
 * multi-line `data:` payloads, comment lines, and the terminal `[DONE]`
 * sentinel. Chunks are yielded as the underlying stream delivers them, so an
 * HTTP client that streams responses surfaces tokens incrementally while a
 * buffering client yields them all at once.
 */
final class ChatStreamParser
{
    private const int READ_CHUNK_BYTES = 8192;

    /**
     * @return \Generator<int,ChatChunk>
     */
    public function parse(StreamInterface $body): \Generator
    {
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(self::READ_CHUNK_BYTES);

            while (($frame = $this->nextFrame($buffer)) !== null) {
                $chunk = $this->toChunk($frame);

                if ($chunk === null) {
                    continue;
                }

                yield $chunk;

                if ($chunk->done) {
                    return;
                }
            }
        }
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

    private function toChunk(string $frame): ?ChatChunk
    {
        $dataLines = [];

        foreach (preg_split('/\r?\n/', $frame) ?: [] as $line) {
            if (str_starts_with($line, ':')) {
                continue; // comment / keep-alive
            }

            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        if ($dataLines === []) {
            return null;
        }

        $data = implode("\n", $dataLines);

        if ($data === '[DONE]') {
            return new ChatChunk('', null, null, true, []);
        }

        $payload = json_decode($data, true);

        if (!is_array($payload)) {
            $payload = ['delta' => $data];
        }

        return ChatChunk::fromFrame($payload, ($payload['done'] ?? null) === true);
    }
}
