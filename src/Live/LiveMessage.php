<?php

namespace SurrealDB\SDK\Live;

/**
 * A single live query notification pushed by the server.
 *
 * @template TRecord
 */
final readonly class LiveMessage
{
    /**
     * @param TRecord|null $record
     */
    public function __construct(
        public string $queryId,
        public LiveAction $action,
        public mixed $record = null,
        public mixed $value = null,
    ) {}
}
