<?php

namespace SurrealDB\SDK\Contracts;

/**
 * Implemented by engines that hold a persistent connection which can die
 * without the engine noticing (e.g. a WebSocket under the synchronous
 * scheduler, where nothing reads the socket between calls).
 */
interface ConnectionProbeInterface
{
    /**
     * Check the underlying connection and, if it has gone, report the loss
     * through the engine's lifecycle events.
     */
    public function probe(): void;
}
