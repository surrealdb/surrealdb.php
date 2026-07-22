<?php

namespace SurrealDB\SDK\Telemetry\Psr;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SurrealDB\SDK\Contracts\Span;
use SurrealDB\SDK\Contracts\Tracer;
use SurrealDB\SDK\Enum\SpanKind;

/**
 * A zero-dependency {@see Tracer} that records spans as structured PSR-3 log
 * lines. Useful for getting span timing into existing logs without pulling in
 * OpenTelemetry, and as a worked example of how little a backend needs.
 */
final class Psr3Tracer implements Tracer
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level = LogLevel::DEBUG,
    ) {}

    public function startSpan(string $name, SpanKind $kind = SpanKind::Client, array $attributes = []): Span
    {
        return new Psr3Span($this->logger, $this->level, $name, $kind, $attributes);
    }
}
