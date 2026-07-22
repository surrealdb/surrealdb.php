<?php

namespace SurrealDB\SDK\Contracts;

use SurrealDB\SDK\Enum\SpanStatus;

/**
 * A single unit of traced work, created by a {@see Tracer}. Methods are
 * chainable; {@see end()} closes the span (and detaches any activated scope in
 * the OpenTelemetry adapter).
 */
interface Span
{
    /**
     * @param scalar|array<scalar>|null $value
     */
    public function setAttribute(string $key, mixed $value): static;

    public function recordException(\Throwable $exception): static;

    public function setStatus(SpanStatus $status, ?string $description = null): static;

    public function end(): void;
}
