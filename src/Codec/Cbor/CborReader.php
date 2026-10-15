<?php

namespace SurrealDB\SDK\Codec\Cbor;

use Beau\CborPHP\classes\FloatHelper;
use Beau\CborPHP\classes\IntHelper;
use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\exceptions\CborException;
use function ord;

/**
 * Decodes CBOR into the shapes {@see CborValueMapper::decode()} expects: maps
 * as arrays, tags as {@see TaggedValue}, text and byte strings as strings.
 *
 * It follows `Beau\CborPHP\CborDecoder`, except for half-precision floats:
 * SurrealDB sends a float as 16 bits when that loses nothing (0.5, -0.25),
 * and that decoder fails on every one below 1 and reads subnormals as 0.
 */
final class CborReader
{
    private int $offset = 0;

    private function __construct(
        private readonly string $buffer,
    ) {}

    /**
     * @throws CborException
     */
    public static function decode(string $data): mixed
    {
        return (new self($data))->next();
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
        if (!isset($this->buffer[$this->offset])) {
            throw new CborException('Unexpected end of CBOR data');
        }

        $byte = ord($this->buffer[$this->offset++]);
        $additional = $byte & 0x1f;

        return match ($byte >> 5) {
            0 => $this->length($additional),
            1 => -$this->length($additional) - 1,
            2, 3 => $this->string($additional),
            4 => $this->array($additional),
            5 => $this->map($additional),
            6 => new TaggedValue($this->length($additional), $this->next()),
            default => $this->simple($additional),
        };
    }

    /**
     * @throws CborException
     */
    private function length(int $additional): int
    {
        if ($additional <= 23) {
            return $additional;
        }

        [$size, $value] = match ($additional) {
            24 => [1, IntHelper::getUint8($this->buffer, $this->offset)],
            25 => [2, IntHelper::getUint16($this->buffer, $this->offset)],
            26 => [4, IntHelper::getUint32($this->buffer, $this->offset)],
            27 => [8, IntHelper::getUint64($this->buffer, $this->offset)],
            default => throw new CborException("Unsupported additional information: {$additional}"),
        };

        $this->offset += $size;

        return $value;
    }

    /**
     * @throws CborException
     */
    private function string(int $additional): string
    {
        $length = $this->length($additional);
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
        $items = [];

        for ($i = 0; $i < $length; $i++) {
            $items[] = $this->next();
        }

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
        $map = [];

        for ($i = 0; $i < $length; $i++) {
            $key = $this->next();
            $map[$key] = $this->next();
        }

        return $map;
    }

    /**
     * @throws CborException
     */
    private function simple(int $additional): bool|float|null
    {
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
