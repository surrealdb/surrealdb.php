<?php

namespace SurrealDB\SDK\Protocol;

use SurrealDB\SDK\Exceptions\ServerException;
use function is_array;
use function is_string;

/**
 * A single statement result yielded from a `query` call. Mirrors the JS
 * `QueryChunk`, collapsed to the fields meaningful for the synchronous engine.
 *
 * @template TResult
 */
final class QueryChunk
{
    /**
     * @param TResult|null $result
     */
    public function __construct(
        public int $index,
        public mixed $result = null,
        public string $status = 'OK',
        public ?string $time = null,
        public ?ServerException $error = null,
    ) {}

    /**
     * @param array<string,mixed> $result
     *
     * @return self<mixed>
     */
    public static function fromResult(int $index, array $result): self
    {
        $status = isset($result['status']) ? (string) $result['status'] : 'OK';
        $time = isset($result['time']) ? (string) $result['time'] : null;

        if ($status === 'OK') {
            return new self($index, $result['result'] ?? null, 'OK', $time);
        }

        $message = is_string($result['result'] ?? null) ? $result['result'] : 'Query execution failed';
        $details = isset($result['details']) && is_array($result['details']) ? $result['details'] : null;

        return new self($index, null, 'ERR', $time, new ServerException('Query', $message, 0, $details));
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * @return TResult|null
     */
    public function resultOrThrow(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }
}
