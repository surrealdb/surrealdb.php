<?php

namespace SurrealDB\SDK\Codec\Cbor;

use Beau\CborPHP\abstracts\AbstractTaggedValue;
use Beau\CborPHP\utils\CborByteString;
use SurrealDB\SDK\Exceptions\SerializationException;
use function chr;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_nan;
use function is_string;
use function pack;
use function strlen;
use function count;

/**
 * A minimal RFC 8949 CBOR encoder for the values {@see CborValueMapper}
 * produces: integers, floats, text and byte strings, arrays, maps, booleans,
 * null and tagged values.
 *
 * welpie21/cbor.php writes a tag number straight into the initial byte, so any
 * tag above 23 (ranges, bounds, files, sets, geometries) comes out malformed.
 * Here every length and tag uses the shared argument encoding, so tags of any
 * size are written correctly. Decoding still uses welpie21/cbor.php.
 */
final class CborWriter
{
    private const int MAJOR_UNSIGNED = 0;
    private const int MAJOR_NEGATIVE = 1;
    private const int MAJOR_BYTES = 2;
    private const int MAJOR_TEXT = 3;
    private const int MAJOR_ARRAY = 4;
    private const int MAJOR_MAP = 5;
    private const int MAJOR_TAG = 6;

    public static function encode(mixed $value): string
    {
        $buffer = '';
        self::write($buffer, $value);

        return $buffer;
    }

    private static function write(string &$buffer, mixed $value): void
    {
        switch (true) {
            case $value === null:
                $buffer .= "\xF6";

                return;
            case is_bool($value):
                $buffer .= $value ? "\xF5" : "\xF4";

                return;
            case is_int($value):
                if ($value >= 0) {
                    self::head($buffer, self::MAJOR_UNSIGNED, $value);
                } else {
                    // -1 - n, computed without overflowing for PHP_INT_MIN.
                    self::head($buffer, self::MAJOR_NEGATIVE, -($value + 1));
                }

                return;
            case is_float($value):
                $buffer .= is_nan($value) ? "\xF9\x7E\x00" : "\xFB" . pack('E', $value);

                return;
            case is_string($value):
                self::head($buffer, self::MAJOR_TEXT, strlen($value));
                $buffer .= $value;

                return;
            case $value instanceof CborByteString:
                $bytes = $value->getByteString();
                self::head($buffer, self::MAJOR_BYTES, strlen($bytes));
                $buffer .= $bytes;

                return;
            case $value instanceof AbstractTaggedValue:
                self::head($buffer, self::MAJOR_TAG, $value->tag);
                self::write($buffer, $value->value);

                return;
            case $value instanceof CborMap:
                self::head($buffer, self::MAJOR_MAP, count($value->entries));

                foreach ($value->entries as $key => $item) {
                    self::write($buffer, $key);
                    self::write($buffer, $item);
                }

                return;
            case is_array($value):
                if (array_is_list($value)) {
                    self::head($buffer, self::MAJOR_ARRAY, count($value));

                    foreach ($value as $item) {
                        self::write($buffer, $item);
                    }
                } else {
                    self::head($buffer, self::MAJOR_MAP, count($value));

                    foreach ($value as $key => $item) {
                        self::write($buffer, $key);
                        self::write($buffer, $item);
                    }
                }

                return;
        }

        throw new SerializationException(
            'Serialization',
            'Cannot encode a value of type ' . get_debug_type($value) . ' as CBOR.',
        );
    }

    /**
     * Write a major type with its argument (a length, a tag number or an
     * unsigned integer) in the shortest form.
     */
    private static function head(string &$buffer, int $major, int $argument): void
    {
        $type = $major << 5;

        if ($argument < 24) {
            $buffer .= chr($type | $argument);
        } elseif ($argument <= 0xFF) {
            $buffer .= chr($type | 24) . chr($argument);
        } elseif ($argument <= 0xFFFF) {
            $buffer .= chr($type | 25) . pack('n', $argument);
        } elseif ($argument <= 0xFFFFFFFF) {
            $buffer .= chr($type | 26) . pack('N', $argument);
        } else {
            $buffer .= chr($type | 27) . pack('J', $argument);
        }
    }
}
