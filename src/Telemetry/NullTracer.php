<?php

namespace SurrealDB\Telemetry;

use SurrealDB\Contracts\Span;
use SurrealDB\Contracts\Tracer;
use SurrealDB\Enum\SpanKind;

/** The default no-op tracer: tracing is off until one is configured. */
final class NullTracer implements Tracer
{
    public function startSpan(string $name, SpanKind $kind = SpanKind::Client, array $attributes = []): Span
    {
        return new NullSpan();
    }
}
