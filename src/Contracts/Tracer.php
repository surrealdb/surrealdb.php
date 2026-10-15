<?php

namespace SurrealDB\Contracts;

use SurrealDB\Enum\SpanKind;

/**
 * The vendor-neutral tracing seam. Mirrors the role PSR-3 plays for logging:
 * the SDK depends only on this contract, and concrete backends (OpenTelemetry,
 * a PSR-3 bridge, or any custom implementation) plug in behind it.
 *
 * The default is a no-op ({@see \SurrealDB\Telemetry\NullTracer}), so the
 * core never depends on any telemetry vendor.
 */
interface Tracer
{
    /**
     * Start (and, in the OpenTelemetry adapter, activate) a span. The caller is
     * responsible for calling {@see Span::end()} — typically in a `finally`.
     *
     * @param array<non-empty-string, scalar|array<scalar>|null> $attributes
     */
    public function startSpan(string $name, SpanKind $kind = SpanKind::Client, array $attributes = []): Span;
}
