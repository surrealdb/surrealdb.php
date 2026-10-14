<?php

namespace SurrealDB\AgentMemory;

use function is_array;
use function preg_match;

/**
 * Reads the delta-seconds form of a `Retry-After` response header, which the
 * API sends with `429` throttling and `503` load shedding. The HTTP-date form
 * is treated as absent, so callers fall back to their own back-off.
 */
final class RetryAfter
{
    /**
     * @param array<string,string|list<string>> $headers response headers (PSR-7 shape or flat)
     *
     * @return int|null whole seconds to wait, or `null` when the header is absent or not delta-seconds
     */
    public static function seconds(array $headers): ?int
    {
        foreach ($headers as $name => $values) {
            if (strcasecmp($name, 'Retry-After') !== 0) {
                continue;
            }

            $raw = trim(is_array($values) ? ($values[0] ?? '') : $values);

            return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
        }

        return null;
    }
}
