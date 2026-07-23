<?php

namespace SurrealDB\Spectron;

/** Small helpers shared by the options classes when building wire payloads. */
final class Payload
{
    /**
     * Drops `null` entries so optional fields are omitted from the wire payload.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    public static function compact(array $fields): array
    {
        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }

    /** Unwraps a backed enum to its wire value, passing strings through. */
    public static function enum(\BackedEnum|string|null $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : $value;
    }
}
