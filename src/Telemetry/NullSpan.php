<?php

namespace SurrealDB\Telemetry;

use SurrealDB\Contracts\Span;
use SurrealDB\Enum\SpanStatus;

/** A span that discards everything; returned by {@see NullTracer}. */
final class NullSpan implements Span
{
    public function setAttribute(string $key, mixed $value): static
    {
        return $this;
    }

    public function recordException(\Throwable $exception): static
    {
        return $this;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): static
    {
        return $this;
    }

    public function end(): void {}
}
