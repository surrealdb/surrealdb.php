<?php

namespace SurrealDB\SDK\Support;

/**
 * What var_dump(), print_r() and debuggers show in place of a password, key
 * or token, so that one does not end up in a log by accident. The values
 * themselves are unchanged.
 */
final class Redacted
{
    public const string MARKER = '<redacted>';

    /**
     * The marker for a secret that is set, and null for one that is not, so
     * whether there is one still shows.
     */
    public static function value(mixed $secret): ?string
    {
        return $secret === null ? null : self::MARKER;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, ?string>
     */
    public static function values(array $values): array
    {
        return array_map(self::value(...), $values);
    }
}
