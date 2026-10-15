<?php

namespace SurrealDB\SDK\Query\Frame;

/**
 * One frame of a streamed query: a {@see ValueFrame}, an {@see ErrorFrame},
 * or a {@see DoneFrame}, each tagged with the index of the statement it
 * belongs to. Mirrors the JS SDK's `Frame`.
 *
 * @template T
 */
abstract readonly class Frame
{
    public function __construct(
        /** The index of the statement this frame belongs to. */
        public int $query,
    ) {}

    /** Whether the frame belongs to the statement at the given index. */
    public function isOf(int $query): bool
    {
        return $this->query === $query;
    }

    /** @phpstan-assert-if-true ValueFrame<T> $this */
    public function isValue(): bool
    {
        return $this instanceof ValueFrame;
    }

    /** @phpstan-assert-if-true ErrorFrame<T> $this */
    public function isError(): bool
    {
        return $this instanceof ErrorFrame;
    }

    /** @phpstan-assert-if-true DoneFrame<T> $this */
    public function isDone(): bool
    {
        return $this instanceof DoneFrame;
    }
}
