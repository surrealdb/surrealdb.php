<?php

namespace SurrealDB\SDK\Query\Frame;

/**
 * A value produced by a statement. When a statement returns a list (a
 * `SELECT`, for example) each element arrives as its own frame; otherwise
 * the whole result arrives as one frame with `$single` set.
 *
 * @template T
 * @extends Frame<T>
 */
final readonly class ValueFrame extends Frame
{
    /**
     * @param T $value
     */
    public function __construct(
        int $query,
        public mixed $value,
        /** Whether this is the statement's whole result rather than one element of it. */
        public bool $single,
    ) {
        parent::__construct($query);
    }
}
