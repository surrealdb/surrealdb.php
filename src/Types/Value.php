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
use function sprintf;
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

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::floatToSurql($value);
        }

        if (is_string($value)) {
            return 's' . self::encodeString($value);
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? '[ ' . implode(', ', array_map(self::toSurql(...), $value)) . ' ]'
                : self::objectToSurql($value);
        }

        // An object, even one without properties, never a list.
        if ($value instanceof \stdClass) {
            return self::objectToSurql(get_object_vars($value));
        }

        if ($value instanceof \JsonSerializable) {
            return self::toSurql($value->jsonSerialize());
        }

        return (string) $value;
    }

    /**
     * The fewest significant digits that read back as the same float, in
     * scientific notation such as `1.2345e+2` or `1e-7`. A cast to string
     * keeps only 14 significant digits and so loses digits a float holds;
     * this does not depend on the `precision` or `serialize_precision`
     * settings either.
     */
    protected static function shortestFloat(float $value): string
    {
        // 17 significant digits always read back as the same float.
        for ($fractionDigits = 0; $fractionDigits < 16; $fractionDigits++) {
            $text = sprintf("%.{$fractionDigits}e", $value);

            if ((float) $text === $value) {
                return $text;
            }
        }

        return sprintf('%.16e', $value);
    }

    /**
     * A float as a SurrealQL literal that reads back as the same float, and
     * never as an integer: plain notation (`1.0`, `0.000001`, `-0.0`) from
     * 0.000001 up to 1e21, as JavaScript prints numbers, and scientific
     * notation (`1e-7`, `1.5e25`) outside that range.
     *
     * No name for infinity is read alike by every server: SurrealDB 2.x
     * reads `Infinity` as a field, and 3.x and 4.x refuse `math::inf` in a
     * record id given as text. `1e309` overflows to infinity on 2.x, 3.x and
     * 4.x alike, and all three read `NaN`.
     */
    private static function floatToSurql(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '1e309' : '-1e309';
        }

        if ($value === 0.0) {
            return fdiv(1.0, $value) < 0 ? '-0.0' : '0.0';
        }

        [$mantissa, $exponent] = explode('e', self::shortestFloat($value));
        $exponent = (int) $exponent;

        if ($exponent < -6 || $exponent > 20) {
            return $mantissa . 'e' . $exponent;
        }

        $sign = $value < 0 ? '-' : '';
        $digits = str_replace(['-', '.'], '', $mantissa);
        $whole = $exponent + 1;

        return match (true) {
            $whole <= 0 => $sign . '0.' . str_repeat('0', -$whole) . $digits,
            $whole >= strlen($digits) => $sign . $digits . str_repeat('0', $whole - strlen($digits)) . '.0',
            default => $sign . substr($digits, 0, $whole) . '.' . substr($digits, $whole),
        };
    }

    /**
     * @param array<mixed> $entries
     */
    private static function objectToSurql(array $entries): string
    {
        $parts = [];

        foreach ($entries as $key => $item) {
            $parts[] = self::encodeString((string) $key) . ': ' . self::toSurql($item);
        }

        return '{ ' . implode(', ', $parts) . ' }';
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
