<?php

namespace Surreal\Cbor\Types;

final readonly class Duration
{
    public int $seconds;
    public int $nanoseconds;

    public function __construct(array $duration)
    {
        [$seconds, $nanoseconds] = $duration; // [int, int]

        $this->seconds = $seconds ?? 0;
        $this->nanoseconds = $nanoseconds ?? 0;
    }

    public function toSeconds(): float
    {
        return $this->seconds + $this->nanoseconds / 1_000_000_000;
    }

    public function toNanoseconds(): int
    {
        return $this->seconds * 1_000_000_000 + $this->nanoseconds;
    }

    public function __toString(): string
    {
        return sprintf('%d.%09d', $this->seconds, $this->nanoseconds);
    }

    public static function fromCborCustomDuration(array $data): self
    {
        return new self($data);
    }
}