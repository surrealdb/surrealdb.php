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
}
