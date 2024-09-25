<?php

namespace Surreal\Core\Utils;

/**
 * Represents a subscriber for websocket events.
 */
class Subscriber
{
    public int $id;
    public mixed $data = null;

    public function hasData(): bool
    {
        return $this->data !== null;
    }

    public function wait(): void
    {
        while (!$this->hasData()) {
            usleep(1000);
        }
    }
}