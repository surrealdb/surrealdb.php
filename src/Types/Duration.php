<?php

namespace SurrealDB\SDK\Types;

use SurrealDB\SDK\Exceptions\InvalidValueException;
use function strlen;

/**
 * A SurrealQL `duration` value with nanosecond precision.
 *
 * Ported from the JS SDK's `Duration`: durations are stored as a normalized
 * `(seconds, nanoseconds)` pair and rendered using SurrealQL's compact unit
 * syntax (e.g. `1h30m`, `500ms`, `0ns`). There is no suitable native PHP type
 * (`DateInterval` cannot represent sub-second precision or this syntax), so this
 * is a purpose-built value object.
 */
final class Duration extends Value
{
    private const int NANOSECOND = 1;
    private const int MICROSECOND = 1_000;
    private const int MILLISECOND = 1_000_000;
    private const int SECOND = 1_000_000_000;
    private const int MINUTE = 60 * self::SECOND;
    private const int HOUR = 60 * self::MINUTE;
    private const int DAY = 24 * self::HOUR;
    private const int WEEK = 7 * self::DAY;
    private const int YEAR = 365 * self::DAY;

    /**
     * Unit suffixes accepted while parsing, mapped to their size in nanoseconds.
     */
    private const array UNITS = [
        'ns' => self::NANOSECOND,
        "\u{00b5}s" => self::MICROSECOND,
        "\u{03bc}s" => self::MICROSECOND,
        'us' => self::MICROSECOND,
        'ms' => self::MILLISECOND,
        's' => self::SECOND,
        'm' => self::MINUTE,
        'h' => self::HOUR,
        'd' => self::DAY,
        'w' => self::WEEK,
        'y' => self::YEAR,
    ];

    /**
     * Canonical units used while formatting, largest first.
     */
    private const array FORMAT_UNITS = [
        ['y', self::YEAR],
        ['w', self::WEEK],
        ['d', self::DAY],
        ['h', self::HOUR],
        ['m', self::MINUTE],
        ['s', self::SECOND],
        ['ms', self::MILLISECOND],
        ['us', self::MICROSECOND],
        ['ns', self::NANOSECOND],
    ];

    public readonly int $seconds;
    public readonly int $nanoseconds;

    public function __construct(int $seconds = 0, int $nanoseconds = 0)
    {
        $total = $seconds * self::SECOND + $nanoseconds;
        $this->seconds = intdiv($total, self::SECOND);
        $this->nanoseconds = $total % self::SECOND;
    }

    /**
     * Parse a SurrealQL duration string such as `1h30m` or `500ms`.
     */
    public static function fromString(string $input): self
    {
        [$seconds, $nanoseconds] = self::parse($input);

        return new self($seconds, $nanoseconds);
    }

    public static function nanoseconds(int $value): self
    {
        return new self(0, $value);
    }

    public static function microseconds(int $value): self
    {
        return new self(0, $value * self::MICROSECOND);
    }

    public static function milliseconds(int $value): self
    {
        return new self(0, $value * self::MILLISECOND);
    }

    public static function seconds(int $value): self
    {
        return new self($value, 0);
    }

    public static function minutes(int $value): self
    {
        return new self($value * intdiv(self::MINUTE, self::SECOND), 0);
    }

    public static function hours(int $value): self
    {
        return new self($value * intdiv(self::HOUR, self::SECOND), 0);
    }

    public static function days(int $value): self
    {
        return new self($value * intdiv(self::DAY, self::SECOND), 0);
    }

    public static function weeks(int $value): self
    {
        return new self($value * intdiv(self::WEEK, self::SECOND), 0);
    }

    public static function years(int $value): self
    {
        return new self($value * intdiv(self::YEAR, self::SECOND), 0);
    }

    public function add(self $other): self
    {
        return new self($this->seconds + $other->seconds, $this->nanoseconds + $other->nanoseconds);
    }

    public function sub(self $other): self
    {
        return new self($this->seconds - $other->seconds, $this->nanoseconds - $other->nanoseconds);
    }

    public function mul(int $factor): self
    {
        $totalNs = ($this->seconds * self::SECOND + $this->nanoseconds) * $factor;

        return new self(0, $totalNs);
    }

    public function div(int $divisor): self
    {
        if ($divisor === 0) {
            throw new InvalidValueException('Division by zero.');
        }

        $totalNs = intdiv($this->seconds * self::SECOND + $this->nanoseconds, $divisor);

        return new self(0, $totalNs);
    }

    /** Total nanoseconds in this duration. */
    public function totalNanoseconds(): int
    {
        return $this->seconds * self::SECOND + $this->nanoseconds;
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->seconds === $other->seconds
            && $this->nanoseconds === $other->nanoseconds;
    }

    /**
     * The `(seconds, nanoseconds)` tuple, matching the JS SDK's `toCompact()`.
     *
     * @return array{int, int}
     */
    public function toCompact(): array
    {
        return [$this->seconds, $this->nanoseconds];
    }

    public function __toString(): string
    {
        $remainingSeconds = $this->seconds;
        $result = '';

        foreach (self::FORMAT_UNITS as [$unit, $size]) {
            if ($size < self::SECOND) {
                continue;
            }

            $perUnit = intdiv($size, self::SECOND);
            $amount = intdiv($remainingSeconds, $perUnit);

            if ($amount > 0) {
                $remainingSeconds %= $perUnit;
                $result .= $amount . $unit;
            }
        }

        $remainingNanoseconds = $remainingSeconds * self::SECOND + $this->nanoseconds;

        foreach (self::FORMAT_UNITS as [$unit, $size]) {
            if ($size >= self::SECOND) {
                continue;
            }

            $amount = intdiv($remainingNanoseconds, $size);

            if ($amount > 0) {
                $remainingNanoseconds %= $size;
                $result .= $amount . $unit;
            }
        }

        return $result === '' ? '0ns' : $result;
    }

    /**
     * Inline SurrealQL literal form: the bare compact syntax (e.g. `1h30m`).
     */
    public function escape(): string
    {
        return $this->__toString();
    }

    /**
     * @return array{'$duration': string}
     */
    public function jsonSerialize(): array
    {
        return ['$duration' => $this->__toString()];
    }

    /**
     * @return array{int, int} `[seconds, nanoseconds]`
     */
    private static function parse(string $input): array
    {
        $seconds = 0;
        $nanoseconds = 0;
        $left = $input;
        $pattern = '/^(\d+)\.?\d*(' . self::unitAlternation() . ')/u';

        while ($left !== '') {
            if (preg_match($pattern, $left, $matches) !== 1) {
                throw new InvalidValueException("Invalid duration string: {$input}");
            }

            $amount = (int) $matches[1];
            $factor = self::UNITS[$matches[2]];

            if ($factor >= self::SECOND) {
                $seconds += $amount * intdiv($factor, self::SECOND);
            } else {
                $nanoseconds += $amount * $factor;
            }

            $left = substr($left, strlen($matches[0]));
        }

        $seconds += intdiv($nanoseconds, self::SECOND);
        $nanoseconds %= self::SECOND;

        return [$seconds, $nanoseconds];
    }

    private static function unitAlternation(): string
    {
        return implode('|', array_map(
            static fn (string $unit): string => preg_quote($unit, '/'),
            array_keys(self::UNITS),
        ));
    }
}
