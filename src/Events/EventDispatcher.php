<?php

namespace SurrealDB\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A small, dependency-free PSR-14 dispatcher. Users may substitute their
 * framework's dispatcher (Laravel/Symfony bridge PSR-14) via DriverOptions.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly ListenerProviderInterface $provider = new ListenerProvider(),
    ) {}

    public function dispatch(object $event): object
    {
        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    public function provider(): ListenerProviderInterface
    {
        return $this->provider;
    }
}
