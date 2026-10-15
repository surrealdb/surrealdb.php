<?php

namespace SurrealDB\SDK\Live;

use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\Scheduler;

/**
 * The notifications for one live query id, as delivered by an engine.
 *
 * The engine registers the feed as soon as it is created, so notifications
 * that arrive before anyone iterates (for example while another call waits for
 * its reply) are queued rather than lost. Iterating yields queued messages and
 * then waits for more, pumping the connection under the synchronous scheduler.
 * A `KILLED` message is the last one the feed yields.
 *
 * @implements \IteratorAggregate<int, LiveMessage<mixed>>
 */
final class LiveFeed implements \IteratorAggregate
{
    /** @var list<LiveMessage<mixed>> */
    private array $queue = [];

    /** @var Deferred<bool>|null */
    private ?Deferred $waiter = null;

    private bool $closed = false;

    private ?\Closure $unsubscribe = null;

    /**
     * @param \Closure(): bool $pump reads and dispatches one inbound frame,
     *                               returning false once the connection is gone
     * @param \Closure(): bool $isOpen whether more messages can still arrive
     */
    public function __construct(
        public readonly string $id,
        private readonly Scheduler $scheduler,
        private readonly \Closure $pump,
        private readonly \Closure $isOpen,
    ) {}

    /**
     * @internal called by the engine once the feed is registered
     */
    public function onClose(\Closure $unsubscribe): void
    {
        $this->unsubscribe = $unsubscribe;
    }

    /**
     * @param LiveMessage<mixed> $message
     */
    public function push(LiveMessage $message): void
    {
        if ($this->closed) {
            return;
        }

        $this->queue[] = $message;
        $this->wake();
    }

    /**
     * Stop receiving: deregister from the engine and end any iteration once
     * the messages already queued have been yielded.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->unsubscribe !== null) {
            ($this->unsubscribe)();
            $this->unsubscribe = null;
        }

        $this->wake();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @return \Generator<int, LiveMessage<mixed>>
     */
    public function getIterator(): \Generator
    {
        while (true) {
            while ($this->queue !== []) {
                $message = array_shift($this->queue);

                yield $message;

                if ($message->action === LiveAction::Killed) {
                    $this->close();

                    return;
                }

                // Closed while the consumer handled the message.
                if ($this->closed) {
                    return;
                }
            }

            if ($this->closed || !($this->isOpen)()) {
                return;
            }

            /** @var Deferred<bool> $waiter */
            $waiter = $this->scheduler->defer($this->pump);
            $this->waiter = $waiter;

            try {
                $waiter->await();
            } catch (\Throwable) {
                return;
            } finally {
                $this->waiter = null;
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function wake(): void
    {
        if ($this->waiter?->isPending() === true) {
            $this->waiter->resolve(true);
        }
    }
}
