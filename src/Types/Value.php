<?php

namespace SurrealDB\SDK\Types;

use JsonSerializable;
use SurrealDB\SDK\Contracts\SurrealType;
use function array_key_exists;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function strlen;

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
     * Whether two PHP values are the same SurrealQL value, as record ids and
     * range bounds compare them.
     *
     * The comparison is strict, as on the server: `1` and `'1'`, `'1e3'` and
     * `'1000'`, `true` and `1`, `null` and `false` all differ. An integer and
     * a float are the same when they hold exactly the same number, so `1` and
     * `1.0` are. Lists compare in order; maps (associative arrays and
     * `stdClass` objects) compare without regard to key order, because the
     * server sorts object keys. Value objects compare with their own
     * {@see self::equals()}, and any other object only with itself.
     */
    protected static function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof self) {
            return $a->equals($b);
        }

        if (is_int($a) && is_float($b)) {
            return self::intIsFloat($a, $b);
        }

        if (is_float($a) && is_int($b)) {
            return self::intIsFloat($b, $a);
        }

        $aMap = self::asMap($a);
        $bMap = self::asMap($b);

        if ($aMap !== null || $bMap !== null) {
            if ($aMap === null || $bMap === null || count($aMap) !== count($bMap)) {
                return false;
            }

            foreach ($aMap as $key => $item) {
                if (!array_key_exists($key, $bMap) || !self::same($item, $bMap[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }

            foreach ($a as $index => $item) {
                if (!self::same($item, $b[$index])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    /**
     * A key that two values of this class share exactly when
     * {@see self::equals()} holds between them, or null when there is none.
     * {@see Set} uses it to find duplicates without comparing every pair of
     * members; a value without a key is compared one by one.
     */
    protected function hashKey(): ?string
    {
        return null;
    }

    /**
     * A key that two value objects share exactly when {@see self::equals()}
     * holds between them: the class and its {@see self::hashKey()}.
     */
    protected static function valueKey(self $value): ?string
    {
        $key = $value->hashKey();

        return $key === null ? null : 'V' . strlen($value::class) . ':' . $value::class . strlen($key) . ':' . $key;
    }

    /**
     * A key that two values share exactly when {@see self::same()} holds
     * between them, or null when there is none. Every key reads to its own
     * end, so keys can be joined.
     */
    protected static function sameKey(mixed $value): ?string
    {
        if ($value instanceof self) {
            return self::valueKey($value);
        }

        if (is_float($value)) {
            if (is_nan($value)) {
                return null;
            }

            // A float holding an integer is the same as the integer.
            return $value >= (float) PHP_INT_MIN && $value < -(float) PHP_INT_MIN && floor($value) === $value
                ? self::scalarKey((int) $value)
                : 'f' . pack('E', $value);
        }

        $map = self::asMap($value);

        if ($map !== null) {
            ksort($map, SORT_STRING);
            $key = 'm' . count($map) . '{';

            foreach ($map as $name => $item) {
                $itemKey = self::sameKey($item);

                if ($itemKey === null) {
                    return null;
                }

                $key .= self::scalarKey($name) . $itemKey;
            }

            return $key . '}';
        }

        if (is_array($value)) {
            $key = 'l' . count($value) . '[';

            foreach ($value as $item) {
                $itemKey = self::sameKey($item);

                if ($itemKey === null) {
                    return null;
                }

                $key .= $itemKey;
            }

            return $key . ']';
        }

        return is_object($value) || is_resource($value) ? null : self::scalarKey($value);
    }

    /**
     * The key of a string, an integer, a boolean or null. It reads to its
     * own end.
     */
    protected static function scalarKey(string|int|bool|null $value): string
    {
        return match (true) {
            is_string($value) => 's' . strlen($value) . ':' . $value,
            is_int($value) => 'i' . $value . ';',
            is_bool($value) => $value ? 'T' : 'F',
            default => 'N',
        };
    }

    /**
     * The entries of a map: an associative array or a `stdClass`. A list,
     * including an empty array, is not one.
     *
     * @return array<mixed>|null
     */
    private static function asMap(mixed $value): ?array
    {
        return match (true) {
            $value instanceof \stdClass => get_object_vars($value),
            is_array($value) && !array_is_list($value) => $value,
            default => null,
        };
    }

    /**
     * Whether a float holds exactly the integer's number.
     */
    private static function intIsFloat(int $int, float $float): bool
    {
        // (int) is exact only inside the integer range: [-2^63, 2^63).
        return $float >= (float) PHP_INT_MIN
            && $float < -(float) PHP_INT_MIN
            && (int) $float === $int
            && (float) $int === $float;
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
