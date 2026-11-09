<?php

namespace SurrealDB\SDK\Codec\Cbor;

use Beau\CborPHP\classes\FloatHelper;
use Beau\CborPHP\classes\IntHelper;
use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\exceptions\CborException;
use Beau\CborPHP\utils\CborByteString;
use function is_int;
use function is_string;
use function ord;
use function strlen;

/**
 * Decodes CBOR into the shapes {@see CborValueMapper::decode()} expects: maps
 * as arrays, tags as {@see TaggedValue}, text as strings and byte strings as
 * {@see CborByteString}, so that bytes keep their type.
 *
 * It follows `Beau\CborPHP\CborDecoder`, except for half-precision floats:
 * SurrealDB sends a float as 16 bits when that loses nothing (0.5, -0.25),
 * and that decoder fails on every one below 1 and reads subnormals as 0.
 *
 * Input is not trusted: whatever arrives, malformed or hostile, decodes or
 * throws {@see CborException}. Nothing is read past the end of the data,
 * nesting is limited, and so are lengths and integers to what PHP can hold.
 */
final class CborReader
{
    /**
     * Arrays, maps and tags nested deeper than this are refused, rather than
     * exhaust the stack or memory. SurrealDB's own parser stops well before.
     */
    public const int MAX_DEPTH = 512;

    private int $offset = 0;

    private int $depth = 0;

    private readonly int $size;

    private function __construct(
        private readonly string $buffer,
    ) {
        $this->size = strlen($buffer);
    }

    /**
     * @throws CborException
     */
    public static function decode(string $data): mixed
    {
        $reader = new self($data);
        $value = $reader->next();

        if ($reader->offset !== $reader->size) {
            throw new CborException('Unexpected data after the CBOR value');
        }

        return $value;
    }

    /**
     * Decode a half-precision float (IEEE 754 binary16).
     */
    public static function halfToFloat(int $half): float
    {
        $sign = ($half & 0x8000) !== 0 ? -1.0 : 1.0;
        $exponent = ($half >> 10) & 0x1f;
        $mantissa = $half & 0x3ff;

        return $sign * match ($exponent) {
            0 => $mantissa * 2 ** -24,
            0x1f => $mantissa === 0 ? INF : NAN,
            default => (1 + $mantissa / 1024) * 2 ** ($exponent - 15),
        };
    }

    /**
     * @throws CborException
     */
    private function next(): mixed
    {
        $this->need(1);
        $byte = ord($this->buffer[$this->offset++]);
        $additional = $byte & 0x1f;

        return match ($byte >> 5) {
            0 => $this->length($additional),
            // At most -PHP_INT_MAX - 1: PHP_INT_MIN, no overflow.
            1 => -$this->length($additional) - 1,
            2 => new CborByteString($this->string($additional)),
            3 => $this->string($additional),
            4 => $this->array($additional),
            5 => $this->map($additional),
            6 => $this->tagged($additional),
            default => $this->simple($additional),
        };
    }

    /**
     * Read the argument of an initial byte: a length, a tag number, or an
     * integer's magnitude. Indefinite lengths (31) are not supported.
     *
     * @throws CborException
     */
    private function length(int $additional): int
    {
        if ($additional <= 23) {
            return $additional;
        }

        $size = match ($additional) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new CborException("Unsupported additional information: {$additional}"),
        };

        $this->need($size);

        $value = match ($size) {
            1 => IntHelper::getUint8($this->buffer, $this->offset),
            2 => IntHelper::getUint16($this->buffer, $this->offset),
            4 => IntHelper::getUint32($this->buffer, $this->offset),
            default => IntHelper::getUint64($this->buffer, $this->offset),
        };

        $this->offset += $size;

        // An unsigned 64-bit value above PHP_INT_MAX reads back negative.
        if ($value < 0) {
            throw new CborException('CBOR value exceeds the largest PHP integer');
        }

        return $value;
    }

    /**
     * @throws CborException
     */
    private function string(int $additional): string
    {
        $length = $this->length($additional);
        $this->need($length);
        $value = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    /**
     * @return list<mixed>
     *
     * @throws CborException
     */
    private function array(int $additional): array
    {
        $length = $this->length($additional);
        // Every item takes at least a byte.
        $this->need($length);
        $this->enter();
        $items = [];

        for ($i = 0; $i < $length; $i++) {
            $items[] = $this->next();
        }

        $this->depth--;

        return $items;
    }

    /**
     * @return array<array-key,mixed>
     *
     * @throws CborException
     */
    private function map(int $additional): array
    {
        $length = $this->length($additional);
        // Every key and every value takes at least a byte (checked in two
        // steps, so that doubling the length cannot overflow).
        $this->need($length);
        $this->need($length * 2);
        $this->enter();
        $map = [];

        for ($i = 0; $i < $length; $i++) {
            $key = $this->next();

            // A PHP array key; SurrealDB's object keys are strings.
            if (!is_string($key) && !is_int($key)) {
                throw new CborException('Unsupported CBOR map key of type ' . get_debug_type($key));
            }

            $map[$key] = $this->next();
        }

        $this->depth--;

        return $map;
    }

    /**
     * @throws CborException
     */
    private function tagged(int $additional): TaggedValue
    {
        $tag = $this->length($additional);
        $this->enter();
        $value = new TaggedValue($tag, $this->next());
        $this->depth--;

        return $value;
    }

    /**
     * @throws CborException
     */
    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new CborException('CBOR data is nested more than ' . self::MAX_DEPTH . ' levels deep');
        }
    }

    /**
     * @throws CborException
     */
    private function need(int $bytes): void
    {
        if ($bytes > $this->size - $this->offset) {
            throw new CborException('Unexpected end of CBOR data');
        }
    }

    /**
     * @throws CborException
     */
    private function simple(int $additional): bool|float|null
    {
        $this->need(match ($additional) {
            25 => 2,
            26 => 4,
            27 => 8,
            default => 0,
        });

        $value = match ($additional) {
            20 => false,
            21 => true,
            22, 23 => null,
            25 => self::halfToFloat(IntHelper::getUint16($this->buffer, $this->offset)),
            26 => FloatHelper::getFloat32($this->buffer, $this->offset),
            27 => FloatHelper::getFloat64($this->buffer, $this->offset),
            default => throw new CborException("Unsupported simple value: {$additional}"),
        };

        $this->offset += match ($additional) {
            25 => 2,
            26 => 4,
            27 => 8,
            default => 0,
        };

        return $value;
    }
}
