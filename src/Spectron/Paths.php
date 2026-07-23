<?php

namespace SurrealDB\Spectron;

/** Builds the API paths shared by the client and its components. */
final class Paths
{
    /** URL-encodes a single path segment (e.g. context id, entity name). */
    public static function encodeSegment(string $value): string
    {
        return rawurlencode($value);
    }

    /** The API path prefix for a Spectron context: `/api/v1/{contextId}`. */
    public static function contextApiPrefix(string $contextId): string
    {
        return '/api/v1/' . self::encodeSegment($contextId);
    }
}
