<?php

namespace SurrealDB\Events;

use SurrealDB\Live\LiveMessage;

/** Dispatched when a live query notification is received. */
final readonly class LiveMessageReceived
{
    /**
     * @param LiveMessage<mixed> $message
     */
    public function __construct(public LiveMessage $message) {}
}
