<?php

namespace SurrealDB\SDK\Events;

use Psr\EventDispatcher\EventDispatcherInterface;

/** The default no-op dispatcher: diagnostics are off until one is configured. */
final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
