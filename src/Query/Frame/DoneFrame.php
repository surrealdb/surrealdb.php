<?php

namespace SurrealDB\SDK\Query\Frame;

/**
 * A statement finished successfully; all its values have been yielded.
 *
 * @template T
 * @extends Frame<T>
 */
final readonly class DoneFrame extends Frame
{
    public function __construct(
        int $query,
        /** How long the statement ran, as reported by the server. */
        public ?string $time = null,
        /** The statement type the server reported (`live`, `kill` or `other`), if any. */
        public ?string $type = null,
    ) {
        parent::__construct($query);
    }
}
