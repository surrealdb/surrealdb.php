<?php

namespace SurrealDB\Types;

use JsonSerializable;
use SurrealDB\Contracts\SurrealType;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Base class for the SurrealQL value types (the PHP counterpart of the JS SDK's
 * `sqon` `Value` hierarchy).
 *
 * Every value type can be rendered in two ways:
 *
 *  - {@see SurrealType::escape()} produces a SurrealQL literal that is safe to
 *    inline directly into query text (used by the query builders for targets
 *    and identifiers, which cannot be sent as parameters).
 *  - {@see JsonSerializable::jsonSerialize()} produces the SQON-J tagged form
 *    (e.g. `{"$uuid": "..."}`) which is the most type-preserving representation
 *    available over the JSON wire protocol, and is what bound parameters emit
 *    through `json_encode`.
 */
abstract class Value implements SurrealType, \JsonSerializable, \Stringable
{
    /**
     * Structurally compare this value with another.
     */
    abstract public function equals(mixed $other): bool;

    /**
     * Render any PHP value as a SurrealQL literal.
     *
     * {@see Value} instances delegate to their own {@see self::escape()}; scalars
     * and arrays are rendered as SurrealQL primitives/objects, mirroring the JS
     * SDK's `toSurqlString` helper.
     */
    public static function toSurql(mixed $value): string
    {
        if ($value instanceof self) {
            return $value->escape();
        }

        if ($value === null) {
            return 'NONE';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return 's' . self::encodeString($value);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[ ' . implode(', ', array_map(self::toSurql(...), $value)) . ' ]';
            }

            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = self::encodeString((string) $key) . ': ' . self::toSurql($item);
            }

            return '{ ' . implode(', ', $parts) . ' }';
        }

        if ($value instanceof \JsonSerializable) {
            return self::toSurql($value->jsonSerialize());
        }

        return (string) $value;
    }

    /**
     * JSON-encode a string into a double-quoted SurrealQL string body, matching
     * JavaScript's `JSON.stringify` (no escaped slashes, raw unicode).
     */
    protected static function encodeString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
