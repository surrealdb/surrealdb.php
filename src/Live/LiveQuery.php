<?php

namespace SurrealDB\Live;

use IteratorAggregate;
use Traversable;

/**
 * A closable stream of {@see LiveMessage}s. Iterate to consume notifications;
 * call {@see close()} to release the underlying subscription.
 *
 * @template TRecord
 * @implements \IteratorAggregate<int,LiveMessage<TRecord>>
 */
final class LiveQuery implements \IteratorAggregate
{
    /**
     * @param iterable<LiveMessage<TRecord>> $messages
     */
    public function __construct(
        private readonly iterable $messages,
        private readonly ?\Closure $onClose = null,
    ) {}

    /**
     * @return \Traversable<int,LiveMessage<TRecord>>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->messages;
    }

    public function close(): void
    {
        if ($this->onClose !== null) {
            ($this->onClose)();
        }
    }
}
