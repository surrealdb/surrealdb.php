<?php

namespace SurrealDB\SDK\Types;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A SurrealQL `decimal` value: an arbitrary-precision decimal number backed by
 * {@see \Brick\Math\BigDecimal}.
 *
 * SurrealDB transports decimals as strings to preserve precision; this type
 * keeps the underlying {@see BigDecimal} so callers can perform exact
 * arithmetic without pulling in a math library of their own.
 */
final class Decimal extends Value
{
    public readonly BigDecimal $value;

    public function __construct(self|BigDecimal|string|int|float $value)
    {
        $this->value = match (true) {
            $value instanceof self => $value->value,
            $value instanceof BigDecimal => $value,
            is_float($value) => BigDecimal::of(self::floatToString($value)),
            default => BigDecimal::of($value),
        };
    }

    public function plus(self|BigDecimal|string|int|float $that): self
    {
        return new self($this->value->plus(self::operand($that)));
    }

    public function minus(self|BigDecimal|string|int|float $that): self
    {
        return new self($this->value->minus(self::operand($that)));
    }

    public function multipliedBy(self|BigDecimal|string|int|float $that): self
    {
        return new self($this->value->multipliedBy(self::operand($that)));
    }

    public function dividedBy(
        self|BigDecimal|string|int|float $that,
        int $scale = 38,
        RoundingMode $roundingMode = RoundingMode::HalfUp,
    ): self {
        return new self($this->value->dividedBy(self::operand($that), $scale, $roundingMode));
    }

    public function abs(): self
    {
        return new self($this->value->abs());
    }

    public function negated(): self
    {
        return new self($this->value->negated());
    }

    public function compareTo(self|BigDecimal|string|int|float $that): int
    {
        return $this->value->compareTo(self::operand($that));
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function toFloat(): float
    {
        return $this->value->toFloat();
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->value->isEqualTo($other->value);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    /**
     * Inline SurrealQL literal form: a `dec`-suffixed number, e.g. `19.99dec`.
     */
    public function escape(): string
    {
        return $this->__toString() . 'dec';
    }

    /**
     * @return array{'$decimal': string}
     */
    public function jsonSerialize(): array
    {
        return ['$decimal' => $this->__toString()];
    }

    private static function operand(self|BigDecimal|string|int|float $value): BigDecimal
    {
        return $value instanceof self ? $value->value : (new self($value))->value;
    }

    /**
     * Convert a float to its shortest round-trippable decimal string, avoiding
     * the binary-noise that fixed-precision formatting would introduce.
     */
    private static function floatToString(float $value): string
    {
        return (string) $value;
    }
}
