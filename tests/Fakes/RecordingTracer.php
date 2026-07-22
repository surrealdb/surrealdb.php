<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\SDK\Contracts\Span;
use SurrealDB\SDK\Contracts\Tracer;
use SurrealDB\SDK\Enum\SpanKind;

/** A {@see Tracer} that captures started spans for assertions. */
final class RecordingTracer implements Tracer
{
    /** @var list<RecordingSpan> */
    public array $spans = [];

    public function startSpan(string $name, SpanKind $kind = SpanKind::Client, array $attributes = []): Span
    {
        $span = new RecordingSpan($name, $kind, $attributes);
        $this->spans[] = $span;

        return $span;
    }

    public function lastSpan(): RecordingSpan
    {
        $span = end($this->spans);

        if ($span === false) {
            throw new \RuntimeException('No span was recorded.');
        }

        return $span;
    }
}
