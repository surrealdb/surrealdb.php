<?php

namespace SurrealDB\AgentMemory;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;

/** Builds the API paths shared by the client and its components. */
final class Paths
{
    /**
     * URL-encodes a single path segment (e.g. context id, entity name).
     *
     * @throws InvalidArgumentException when the segment is blank, which would address a different route
     */
    public static function encodeSegment(string $value): string
    {
        return rawurlencode(Guard::notBlank($value, 'Agent Memory path segments (ids and names) must be non-empty.'));
    }

    /** The API path prefix for an Agent Memory context: `/api/v1/{contextId}`. */
    public static function contextApiPrefix(string $contextId): string
    {
        return '/api/v1/' . self::encodeSegment($contextId);
    }
}
