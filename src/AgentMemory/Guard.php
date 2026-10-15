<?php

namespace SurrealDB\AgentMemory;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;

/**
 * Client-side input checks shared by the client, its components and the
 * options classes. Each check runs before a request is built and fails with
 * {@see InvalidArgumentException}, so a bad argument never costs a round trip
 * or reaches a route it was not meant for.
 *
 * @internal
 */
final class Guard
{
    /**
     * Whether `$value` is empty or ASCII whitespace only (what PHP's `trim()`
     * strips). A value of only Unicode spaces is not blank here: it reaches
     * the server, which trims Unicode whitespace and answers 400.
     */
    public static function isBlank(string $value): bool
    {
        return trim($value) === '';
    }

    /**
     * @return string `$value`, unchanged
     *
     * @throws InvalidArgumentException with `$message` when `$value` is blank
     */
    public static function notBlank(string $value, string $message): string
    {
        if (self::isBlank($value)) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * Checks the options of a whole-collection walk (`listAll()`, `all*()`):
     * it starts at the first page and asks for no total, the runtime form of
     * the JS client's `Omit<…, "cursor" | "count">` on those options.
     *
     * @param string $method     the collector, for the message (e.g. `Documents::listAll()`)
     * @param string $pageMethod the page-at-a-time method it walks (e.g. `Documents::list()`)
     *
     * @throws InvalidArgumentException when a cursor or count is set, or `$max` is below 1
     */
    public static function collectable(string $method, string $pageMethod, ?string $cursor, bool $count = false, ?int $max = null): void
    {
        if ($cursor !== null) {
            throw new InvalidArgumentException("{$method} walks the collection from its first page, so it takes no cursor; use {$pageMethod} and next() to resume from one.");
        }

        if ($count) {
            throw new InvalidArgumentException("{$method} returns rows, not a total, so it takes no count; read totalSize from {$pageMethod} with count: true.");
        }

        self::max($method, $max);
    }

    /**
     * @throws InvalidArgumentException when `$max` is set and below 1
     */
    public static function max(string $method, ?int $max): void
    {
        if ($max !== null && $max < 1) {
            throw new InvalidArgumentException("{$method} needs max to be at least 1, got {$max}.");
        }
    }
}
