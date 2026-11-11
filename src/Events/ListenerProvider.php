<?php

namespace SurrealDB\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * A minimal PSR-14 listener provider. Register listeners against an event class
 * (or any of its parents/interfaces) via {@see on()}.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string,list<callable>> */
    private array $listeners = [];

    /**
     * @template TEvent of object
     *
     * @param class-string<TEvent> $eventClass
     * @param callable(TEvent): void $listener
     */
    public function on(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $class => $listeners) {
            if ($event instanceof $class) {
                yield from $listeners;
            }
        }
    }
}
