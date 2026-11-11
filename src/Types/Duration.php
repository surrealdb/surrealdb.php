<?php

namespace SurrealDB\Types;

use Brick\Math\BigInteger;
use SurrealDB\Exceptions\InvalidValueException;
use function is_int;
use function strlen;

/**
 * A SurrealQL `duration` value with nanosecond precision.
 *
 * Ported from the JS SDK's `Duration`: durations are stored as a normalized
 * `(seconds, nanoseconds)` pair and rendered using SurrealQL's compact unit
 * syntax (e.g. `1h30m`, `500ms`, `0ns`). There is no suitable native PHP type
 * (`DateInterval` cannot represent sub-second precision or this syntax), so this
 * is a purpose-built value object.
 *
 * As in SurrealDB, the seconds and the nanoseconds are kept apart, so a
 * duration is not limited to a 64-bit count of nanoseconds (about 292 years).
 * It holds up to PHP_INT_MAX seconds; anything longer throws
 * {@see InvalidValueException}.
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

    /**
     * Whole seconds are carried out of the nanoseconds, which leaves them
     * under one second and with the same sign as the seconds.
     *
     * @throws InvalidValueException when the duration is over PHP_INT_MAX seconds
     */
    public function __construct(int $seconds = 0, int $nanoseconds = 0)
    {
        $seconds = self::checked($seconds + intdiv($nanoseconds, self::SECOND));
        $nanoseconds %= self::SECOND;

        if ($seconds > 0 && $nanoseconds < 0) {
            $seconds--;
            $nanoseconds += self::SECOND;
        } elseif ($seconds < 0 && $nanoseconds > 0) {
            $seconds++;
            $nanoseconds -= self::SECOND;
        }

        $this->seconds = $seconds;
        $this->nanoseconds = $nanoseconds;
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
        return self::ofUnit($value, self::MICROSECOND);
    }

    public static function milliseconds(int $value): self
    {
        return self::ofUnit($value, self::MILLISECOND);
    }

    public static function seconds(int $value): self
    {
        return new self($value, 0);
    }

    public static function minutes(int $value): self
    {
        return self::ofUnit($value, self::MINUTE);
    }

    public static function hours(int $value): self
    {
        return self::ofUnit($value, self::HOUR);
    }

    public static function days(int $value): self
    {
        return self::ofUnit($value, self::DAY);
    }

    public static function weeks(int $value): self
    {
        return self::ofUnit($value, self::WEEK);
    }

    public static function years(int $value): self
    {
        return self::ofUnit($value, self::YEAR);
    }

    public function add(self $other): self
    {
        return new self(
            self::checked($this->seconds + $other->seconds),
            $this->nanoseconds + $other->nanoseconds,
        );
    }

    public function sub(self $other): self
    {
        return new self(
            self::checked($this->seconds - $other->seconds),
            $this->nanoseconds - $other->nanoseconds,
        );
    }

    public function mul(int $factor): self
    {
        return self::fromTotal($this->total()->multipliedBy($factor));
    }

    /**
     * Divide, rounding towards zero to whole nanoseconds.
     */
    public function div(int $divisor): self
    {
        if ($divisor === 0) {
            throw new InvalidValueException('Division by zero.');
        }

        return self::fromTotal($this->total()->quotient($divisor));
    }

    /**
     * Total nanoseconds in this duration.
     *
     * @throws InvalidValueException when that is more than a PHP integer holds,
     *                               past about 292 years; read the seconds and
     *                               nanoseconds properties instead
     */
    public function totalNanoseconds(): int
    {
        $total = $this->total();

        if ($total->isGreaterThan(PHP_INT_MAX) || $total->isLessThan(PHP_INT_MIN)) {
            throw new InvalidValueException(
                "Duration {$this} has more nanoseconds than a PHP integer holds; read its seconds and nanoseconds instead.",
            );
        }

        return $total->toInt();
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->seconds === $other->seconds
            && $this->nanoseconds === $other->nanoseconds;
    }

    protected function hashKey(): string
    {
        return $this->seconds . ',' . $this->nanoseconds;
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

        // Under a minute is left, so this cannot overflow.
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
     * Whole units are added up in seconds and sub-second units apart, as
     * SurrealDB does, carrying whole seconds out of the nanoseconds.
     *
     * @return array{int, int} `[seconds, nanoseconds]`
     */
    private static function parse(string $input): array
    {
        $seconds = 0;
        $nanoseconds = 0;
        $left = $input;
        $pattern = '/^([0-9]+)\.?[0-9]*(' . self::unitAlternation() . ')/u';

        while ($left !== '') {
            if (preg_match($pattern, $left, $matches) !== 1) {
                throw new InvalidValueException("Invalid duration string: {$input}");
            }

            $amount = (int) $matches[1];
            $unit = self::UNITS[$matches[2]];

            // The cast stops at PHP_INT_MAX: a larger amount, such as
            // 10000000000000000000ns, is counted exactly instead.
            $part = $amount === PHP_INT_MAX && ltrim($matches[1], '0') !== (string) PHP_INT_MAX
                ? self::fromTotal(BigInteger::of($matches[1])->multipliedBy($unit))
                : self::ofUnit($amount, $unit);
            $seconds = self::checked($seconds + $part->seconds);
            $nanoseconds += $part->nanoseconds;

            if ($nanoseconds >= self::SECOND) {
                $seconds = self::checked($seconds + 1);
                $nanoseconds -= self::SECOND;
            }

            $left = substr($left, strlen($matches[0]));
        }

        return [$seconds, $nanoseconds];
    }

    /**
     * `$amount` units of `$unit` nanoseconds each, without first multiplying
     * out a count of nanoseconds that could overflow.
     */
    private static function ofUnit(int $amount, int $unit): self
    {
        if ($unit >= self::SECOND) {
            return new self(self::checked($amount * intdiv($unit, self::SECOND)));
        }

        $perSecond = intdiv(self::SECOND, $unit);

        return new self(intdiv($amount, $perSecond), ($amount % $perSecond) * $unit);
    }

    /**
     * This duration in nanoseconds, as an integer of any size.
     */
    private function total(): BigInteger
    {
        return BigInteger::of($this->seconds)->multipliedBy(self::SECOND)->plus($this->nanoseconds);
    }

    private static function fromTotal(BigInteger $nanoseconds): self
    {
        [$seconds, $remainder] = $nanoseconds->quotientAndRemainder(self::SECOND);

        if ($seconds->isGreaterThan(PHP_INT_MAX) || $seconds->isLessThan(PHP_INT_MIN)) {
            throw self::tooLong();
        }

        return new self($seconds->toInt(), $remainder->toInt());
    }

    /**
     * A count of seconds from integer arithmetic, which PHP turns into a
     * float when it overflows.
     */
    private static function checked(int|float $seconds): int
    {
        if (!is_int($seconds)) {
            throw self::tooLong();
        }

        return $seconds;
    }

    private static function tooLong(): InvalidValueException
    {
        return new InvalidValueException('Duration is longer than ' . PHP_INT_MAX . ' seconds, the most a PHP integer holds.');
    }

    private static function unitAlternation(): string
    {
        return implode('|', array_map(
            static fn (string $unit): string => preg_quote($unit, '/'),
            array_keys(self::UNITS),
        ));
    }
}
