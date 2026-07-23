<?php

namespace SurrealDB\Telemetry\OpenTelemetry;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use SurrealDB\Contracts\Span;
use SurrealDB\Contracts\Tracer;
use SurrealDB\Enum\SpanKind;
use SurrealDB\Exceptions\ConfigurationException;

/**
 * Bridges the SDK {@see Tracer} seam onto an OpenTelemetry tracer. Every span is
 * started and activated, so SDK spans nest under the caller's ambient span.
 *
 * Requires the optional `open-telemetry/api` package. Construct via
 * {@see fromGlobals()} to use the globally-registered provider, or pass a
 * tracer obtained from your own provider.
 */
final class OtelTracer implements Tracer
{
    public const INSTRUMENTATION_NAME = 'surrealdb/surrealdb.php';

    public function __construct(private readonly TracerInterface $tracer) {}

    /**
     * Build from the globally-registered OpenTelemetry tracer provider. When no
     * SDK is registered the provider is a no-op, so this is always safe to call
     * once the API package is installed.
     */
    public static function fromGlobals(?string $version = null): self
    {
        if (!class_exists(Globals::class)) {
            throw new ConfigurationException(
                'Telemetry',
                'The "open-telemetry/api" package is required for the OpenTelemetry adapter. Install it with "composer require open-telemetry/api".',
            );
        }

        return new self(Globals::tracerProvider()->getTracer(self::INSTRUMENTATION_NAME, $version));
    }

    public function startSpan(string $name, SpanKind $kind = SpanKind::Client, array $attributes = []): Span
    {
        if ($name === '') {
            $name = self::INSTRUMENTATION_NAME;
        }

        $builder = $this->tracer->spanBuilder($name)->setSpanKind($this->mapKind($kind));

        foreach ($attributes as $key => $value) {
            $builder->setAttribute($key, $value);
        }

        $span = $builder->startSpan();

        return new OtelSpan($span, $span->activate());
    }

    /**
     * @return OtelSpanKind::KIND_*
     */
    private function mapKind(SpanKind $kind): int
    {
        return match ($kind) {
            SpanKind::Internal => OtelSpanKind::KIND_INTERNAL,
            SpanKind::Client => OtelSpanKind::KIND_CLIENT,
            SpanKind::Server => OtelSpanKind::KIND_SERVER,
            SpanKind::Producer => OtelSpanKind::KIND_PRODUCER,
            SpanKind::Consumer => OtelSpanKind::KIND_CONSUMER,
        };
    }
}
