<?php

namespace SurrealDB\Support;

/**
 * A tiny synchronous publish/subscribe helper used for engine lifecycle
 * signalling. Mirrors the JS SDK `Publisher`: subscribing returns an
 * unsubscribe callable. This is internal plumbing, distinct from the PSR-14
 * event dispatcher used for outward observation.
 */
final class Publisher
{
    /** @var array<string,array<int,callable>> */
    private array $listeners = [];

    private int $nextId = 0;

    public function subscribe(string $event, callable $listener): \Closure
    {
        $id = $this->nextId++;
        $this->listeners[$event][$id] = $listener;

        return function () use ($event, $id): void {
            unset($this->listeners[$event][$id]);

            if (isset($this->listeners[$event]) && $this->listeners[$event] === []) {
                unset($this->listeners[$event]);
            }
        };
    }

    public function publish(string $event, mixed ...$payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$payload);
        }
    }

    public function clear(): void
    {
        $this->listeners = [];
    }
}
