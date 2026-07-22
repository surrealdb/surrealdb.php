<?php

namespace SurrealDB\SDK\Types;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use SurrealDB\SDK\Exceptions\InvalidValueException;
use function sprintf;

/**
 * A SurrealQL `datetime` value with nanosecond precision.
 *
 * PHP's native {@see DateTimeImmutable} only stores microseconds, so this type
 * keeps the timestamp as a `(seconds, nanoseconds)` pair to round-trip the full
 * precision SurrealDB supports while still exposing a native datetime via
 * {@see self::toDateTimeImmutable()}.
 */
final class DateTime extends Value
{
    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;

    /**
     * @param int $seconds     Seconds since the Unix epoch.
     * @param int $nanoseconds Sub-second nanoseconds in the range [0, 1e9).
     */
    public function __construct(
        public readonly int $seconds = 0,
        public readonly int $nanoseconds = 0,
    ) {}

    /**
     * Construct from an ISO 8601 / RFC 3339 string, preserving up to nanosecond
     * precision from the fractional-seconds component.
     */
    public static function fromString(string $iso): self
    {
        [$seconds, $nanoseconds] = self::parse($iso);

        return new self($seconds, $nanoseconds);
    }

    /**
     * Construct from a native datetime (microsecond precision).
     */
    public static function fromDateTime(\DateTimeInterface $dateTime): self
    {
        $microseconds = (int) $dateTime->format('u');

        return new self($dateTime->getTimestamp(), $microseconds * 1_000);
    }

    /**
     * The current time. Limited to microsecond precision on most platforms.
     */
    public static function now(): self
    {
        $now = microtime(true);
        $seconds = (int) floor($now);
        $nanoseconds = (int) round(($now - $seconds) * self::NANOSECONDS_PER_SECOND);

        if ($nanoseconds >= self::NANOSECONDS_PER_SECOND) {
            $seconds += 1;
            $nanoseconds -= self::NANOSECONDS_PER_SECOND;
        }

        return new self($seconds, $nanoseconds);
    }

    /**
     * The Unix epoch (`1970-01-01T00:00:00Z`).
     */
    public static function epoch(): self
    {
        return new self(0, 0);
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

    /**
     * A native {@see DateTimeImmutable} (microsecond precision; nanoseconds are
     * truncated).
     */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        $microseconds = intdiv($this->nanoseconds, 1_000);

        return \DateTimeImmutable::createFromFormat(
            'U u',
            sprintf('%d %06d', $this->seconds, $microseconds),
            new \DateTimeZone('UTC'),
        ) ?: throw new InvalidValueException('Unable to build a DateTimeImmutable from the stored timestamp.');
    }

    /**
     * RFC 3339 string in UTC, with a fractional-seconds component trimmed of
     * trailing zeros when nanoseconds are present.
     */
    public function toIso(): string
    {
        $base = gmdate('Y-m-d\TH:i:s', $this->seconds);

        if ($this->nanoseconds === 0) {
            return $base . 'Z';
        }

        $fraction = rtrim(str_pad((string) $this->nanoseconds, 9, '0', STR_PAD_LEFT), '0');

        return $base . '.' . $fraction . 'Z';
    }

    public function __toString(): string
    {
        return $this->toIso();
    }

    /**
     * Inline SurrealQL literal form: `d"..."`.
     */
    public function escape(): string
    {
        return 'd' . self::encodeString($this->toIso());
    }

    /**
     * @return array{'$datetime': string}
     */
    public function jsonSerialize(): array
    {
        return ['$datetime' => $this->toIso()];
    }

    /**
     * @return array{int, int} `[seconds, nanoseconds]`
     */
    private static function parse(string $input): array
    {
        $pattern = '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:?\d{2})?$/';

        if (preg_match($pattern, trim($input), $matches) === 1) {
            $fraction = $matches[3] ?? '';
            $timezone = $matches[4] ?? '';
            $base = $matches[1] . 'T' . $matches[2] . $timezone;

            $dateTime = new \DateTimeImmutable($base, new \DateTimeZone('UTC'));
            $nanoseconds = $fraction === '' ? 0 : (int) str_pad($fraction, 9, '0');

            return [$dateTime->getTimestamp(), $nanoseconds];
        }

        try {
            $dateTime = new \DateTimeImmutable($input);
        } catch (\Exception $exception) {
            throw new InvalidValueException("Invalid datetime string: {$input}", previous: $exception);
        }

        return [$dateTime->getTimestamp(), (int) $dateTime->format('u') * 1_000];
    }
}
