<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\Contracts\Span;
use SurrealDB\Enum\SpanKind;
use SurrealDB\Enum\SpanStatus;

/** A {@see Span} that records everything it receives for assertions. */
final class RecordingSpan implements Span
{
    /** @var array<string, mixed> */
    public array $attributes;

    public SpanStatus $status = SpanStatus::Unset;
    public ?string $statusDescription = null;

    /** @var list<\Throwable> */
    public array $exceptions = [];

    public bool $ended = false;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly SpanKind $kind,
        array $attributes = [],
    ) {
        $this->attributes = $attributes;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function recordException(\Throwable $exception): static
    {
        $this->exceptions[] = $exception;

        return $this;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): static
    {
        $this->status = $status;
        $this->statusDescription = $description;

        return $this;
    }

    public function end(): void
    {
        $this->ended = true;
    }
}
