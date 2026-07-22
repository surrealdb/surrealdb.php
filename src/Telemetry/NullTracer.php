<?php

namespace SurrealDB\SDK\Telemetry;

use SurrealDB\SDK\Contracts\Span;
use SurrealDB\SDK\Contracts\Tracer;
use SurrealDB\SDK\Enum\SpanKind;

/** The default no-op tracer: tracing is off until one is configured. */
final class NullTracer implements Tracer
{
    public function startSpan(string $name, SpanKind $kind = SpanKind::Client, array $attributes = []): Span
    {
        return new NullSpan();
    }
}
