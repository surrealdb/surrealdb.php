<?php

namespace Surreal\Cbor\Types;

use JsonSerializable;

final class Duration implements JsonSerializable
{
    const MILLISECOND = 1;
    const MICROSECOND = self::MILLISECOND / 1000;
    const NANOSECOND = self::MICROSECOND / 1000;
    const SECOND = self::MILLISECOND * 1000;
    const MINUTE = self::SECOND * 60;
    const HOUR = self::MINUTE * 60;
    const DAY = self::HOUR * 24;
    const WEEK = self::DAY * 7;
    const REGEX = "/^(\d+)(ns|µs|μs|us|ms|s|m|h|d|w)/";

    private const UNITS = [
        "w" => self::WEEK,
        "d" => self::DAY,
        "h" => self::HOUR,
        "m" => self::MINUTE,
        "s" => self::SECOND,
        "ms" => self::MILLISECOND,
        "µs" => self::MICROSECOND,
        "μs" => self::MICROSECOND,
        "us" => self::MICROSECOND,
        "ns" => self::NANOSECOND
    ];

    public int $milliseconds;

    public function __construct(int|Duration|string $milliseconds)
    {
        if ($milliseconds instanceof Duration) {
            $this->milliseconds = $milliseconds->milliseconds;
        } elseif (is_string($milliseconds)) {
            $this->milliseconds = self::parseString($milliseconds);
        } else {
            $this->milliseconds = $milliseconds;
        }
    }

    /**
     * Returns the duration as a compact array
     * @param array $compact
     * @return Duration
     */
    public static function fromCompact(array $compact): Duration
    {
        // It can have a single value or a pair of values or empty.
        $length = count($compact);

        return match ($length) {
            0 => new Duration(0),
            1 => new Duration($compact[0]),
            2 => new Duration($compact[0] * 1000 + $compact[1] / 1000000),
            default => throw new \InvalidArgumentException("Invalid compact duration")
        };
    }

    /**
     * Returns the duration as a compact array
     * @return array
     */
    public function toCompact(): array
    {
        $seconds = floor($this->milliseconds / 1000);
        $nanoseconds = floor(($this->milliseconds - $seconds * 1000) * 1000000);

        return match (true) {
            $nanoseconds > 0 => [$seconds, $nanoseconds],
            $seconds > 0 => [$seconds],
            default => []
        };
    }

    /**
     * Parses the string into an integer that represents the duration in milliseconds
     * @param string $duration
     * @return int - the duration in milliseconds
     */
    public static function parseString(string $duration): int
    {
        $duration = trim($duration);
        $ms = 0;
        $left = $duration;

        while($left !== "") {
            preg_match(self::REGEX, $left, $matches);

            if (count($matches) === 0) {
                throw new \InvalidArgumentException("Invalid duration string");
            }

            $amount = intval($matches[1]);
            $unit = $matches[2];

            if (!array_key_exists($unit, self::UNITS)) {
                throw new \InvalidArgumentException("Invalid duration unit");
            }

            $factor = self::UNITS[$unit];
            $ms += $amount * $factor;
            $left = substr($left, strlen($matches[0]));
        }

        return $ms;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Returns the duration as a string
     * @return string
     */
    public function toString(): string
    {
        $left = $this->milliseconds;
        $result = "";

        $scrap = function (int|float $size) use (&$left): int {
            $num = floor($left / $size);
            if ($num > 0) {
                $left = $left % $size;
            }
            return $num;
        };

        foreach (self::UNITS as $unit => $factor) {
            $scrapped = $scrap($factor);
            if($scrapped > 0) {
                $result .= $scrapped . $unit;
            }
        }

        return $result;
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * Compares two durations and returns true if they are equal
     * @param Duration|int $other
     * @return bool
     */
    public function equals(Duration|int $other): bool
    {
        if ($other instanceof Duration) {
            return $this->milliseconds === $other->milliseconds;
        }

        return $this->milliseconds === $other;
    }

    /**
     * Returns the duration in microseconds
     * @return int
     */
    public function getMicroseconds(): int
    {
        return floor($this->milliseconds / self::MICROSECOND);
    }

    /**
     * Returns the duration in nanoseconds
     * @return int
     */
    public function getNanoseconds(): int
    {
        return floor($this->milliseconds / self::NANOSECOND);
    }

    /**
     * Returns the duration in milliseconds
     * @return int
     */
    public function getMilliseconds(): int
    {
        return $this->milliseconds;
    }

    /**
     * Returns the duration in seconds
     * @return float
     */
    public function getSeconds(): float
    {
        return floor($this->milliseconds / self::SECOND);
    }

    /**
     * Returns the duration in minutes
     * @return float
     */
    public function getMinutes(): float
    {
        return floor($this->milliseconds / self::MINUTE);
    }

    /**
     * Returns the duration in hours
     * @return float
     */
    public function getHours(): float
    {
        return floor($this->milliseconds / self::HOUR);
    }

    /**
     * Returns the duration in days
     * @return float
     */
    public function getDays(): float
    {
        return floor($this->milliseconds / self::DAY);
    }

    /**
     * Returns the duration in weeks
     * @return float
     */
    public function getWeeks(): float
    {
        return floor($this->milliseconds / self::WEEK);
    }

    /**
     * Returns the duration class from the specified microseconds
     * @param int $microseconds
     * @return Duration
     */
    public function fromMicroseconds(int $microseconds): Duration
    {
        return new Duration($microseconds * self::MICROSECOND);
    }

    /**
     * Returns the duration class from the specified nanoseconds
     * @param int $nanoseconds
     * @return Duration
     */
    public function fromNanoseconds(int $nanoseconds): Duration
    {
        return new Duration($nanoseconds * self::NANOSECOND);
    }

    /**
     * Returns the duration class from the specified milliseconds
     * @param int $milliseconds
     * @return Duration
     */
    public function fromMilliseconds(int $milliseconds): Duration
    {
        return new Duration($milliseconds);
    }

    /**
     * Returns the duration class from the specified seconds
     * @param float $seconds
     * @return Duration
     */
    public function fromSeconds(float $seconds): Duration
    {
        return new Duration($seconds * self::SECOND);
    }

    /**
     * Returns the duration class from the specified minutes
     * @param float $minutes
     * @return Duration
     */
    public function fromMinutes(float $minutes): Duration
    {
        return new Duration($minutes * self::MINUTE);
    }

    /**
     * Returns the duration class from the specified hours
     * @param float $hours
     * @return Duration
     */
    public function fromHours(float $hours): Duration
    {
        return new Duration($hours * self::HOUR);
    }

    /**
     * Returns the duration class from the specified days
     * @param float $days
     * @return Duration
     */
    public function fromDays(float $days): Duration
    {
        return new Duration($days * self::DAY);
    }

    /**
     * Returns the duration class from the specified weeks
     * @param float $weeks
     * @return Duration
     */
    public function fromWeeks(float $weeks): Duration
    {
        return new Duration($weeks * self::WEEK);
    }
}