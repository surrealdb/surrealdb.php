<?php

namespace SurrealDB\Telemetry\OpenTelemetry;

use function is_string;

/**
 * Declarative configuration for the {@see OtelObservability} factory: where to
 * send OTLP data, how to identify this service, and how aggressively to sample
 * and (for the batched/FPM strategy) buffer.
 *
 * The factory reads these once at construction; mutating the object afterwards
 * has no effect.
 */
final class ObservabilityOptions
{
    /**
     * @param string $endpoint Base OTLP endpoint (no signal path). Empty falls
     *        back to the `OTEL_EXPORTER_OTLP_ENDPOINT` env var, then
     *        `http://localhost:4318`. The `/v1/traces` and `/v1/metrics` paths
     *        are appended automatically.
     * @param string $contentType OTLP payload encoding: `application/x-protobuf`
     *        (default) or `application/json`.
     * @param array<string, string> $headers Extra headers sent on every export
     *        (e.g. an ingest token).
     * @param string $serviceName Value for the `service.name` resource attribute.
     * @param float|null $samplerRatio Head sampling probability in `[0, 1]`.
     *        Null records every span (parent-based AlwaysOn).
     * @param bool $traces Emit spans.
     * @param bool $metrics Emit metrics.
     * @param bool $finishRequestBeforeFlush Batched/FPM strategy only: call
     *        `fastcgi_finish_request()` (when available under FPM) before draining
     *        the buffer, so the client is not kept waiting on export.
     * @param int|null $maxQueueSize Batched strategy: max spans buffered before
     *        new spans are dropped. Null uses the OTel default.
     * @param int|null $scheduledDelayMillis Batched strategy: opportunistic flush
     *        delay. Null uses the OTel default.
     * @param int|null $maxExportBatchSize Batched strategy: max spans per export
     *        batch. Null uses the OTel default.
     */
    public function __construct(
        public string $endpoint = '',
        public string $contentType = 'application/x-protobuf',
        public array $headers = [],
        public string $serviceName = 'surrealdb-php',
        public ?float $samplerRatio = null,
        public bool $traces = true,
        public bool $metrics = true,
        public bool $finishRequestBeforeFlush = true,
        public ?int $maxQueueSize = null,
        public ?int $scheduledDelayMillis = null,
        public ?int $maxExportBatchSize = null,
    ) {}

    /** Resolve the base endpoint, applying the env var and localhost fallbacks. */
    public function resolvedEndpoint(): string
    {
        if ($this->endpoint !== '') {
            return rtrim($this->endpoint, '/');
        }

        $env = getenv('OTEL_EXPORTER_OTLP_ENDPOINT');

        return rtrim(is_string($env) && $env !== '' ? $env : 'http://localhost:4318', '/');
    }

    public function tracesEndpoint(): string
    {
        return $this->resolvedEndpoint() . '/v1/traces';
    }

    public function metricsEndpoint(): string
    {
        return $this->resolvedEndpoint() . '/v1/metrics';
    }
}
