<?php

namespace SurrealDB\Telemetry\OpenTelemetry;

use Amp\Http\Client\Psr7\PsrHttpClient;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Http\Client\ClientInterface;
use SurrealDB\Contracts\Meter;
use SurrealDB\Contracts\Tracer;
use SurrealDB\Exceptions\ConfigurationException;
use SurrealDB\Http\AmpPsr18Client;

/**
 * Assembles an OpenTelemetry tracer/meter provider with the span-processor
 * strategy that matches the host runtime, and bridges them onto the SDK's
 * {@see Tracer} / {@see Meter} seams via the existing {@see OtelTracer} /
 * {@see OtelMeter} adapters.
 *
 * Two strategies:
 *
 * - {@see batched()} — a {@see BatchSpanProcessor} buffers spans in memory and
 *   only exports when {@see forceFlush()} / {@see shutdown()} runs. Suited to
 *   PHP-FPM / CLI, where the flush is deferred until after the request (see
 *   {@see \SurrealDB\Runtime\Runtime::sync()}).
 * - {@see direct()} — a {@see SimpleSpanProcessor} exports each span as it ends.
 *   Combined with a non-blocking transport (OpenSwoole runtime hooks, or the
 *   Amp PSR-18 client from {@see ampHttpClient()}) this stays off the critical
 *   path on async runtimes without batching.
 *
 * Requires `open-telemetry/sdk` and `open-telemetry/exporter-otlp`; a helpful
 * {@see ConfigurationException} is thrown when they are absent (mirroring
 * {@see OtelTracer::fromGlobals()}).
 */
final class OtelObservability
{
    private function __construct(
        private readonly TracerProvider $tracerProvider,
        private readonly MeterProviderInterface $meterProvider,
    ) {}

    /**
     * Buffer spans in memory and export in batches. Intended for PHP-FPM / CLI.
     *
     * @param SpanExporterInterface|null $spanExporter override the OTLP span
     *        exporter (primarily for tests / custom backends)
     * @param MetricExporterInterface|null $metricExporter override the OTLP metric
     *        exporter (primarily for tests / custom backends)
     */
    public static function batched(
        ObservabilityOptions $options,
        ?SpanExporterInterface $spanExporter = null,
        ?MetricExporterInterface $metricExporter = null,
    ): self {
        self::ensureSdk();

        $resource = self::resource($options);
        $processors = $options->traces
            ? [new BatchSpanProcessor(
                $spanExporter ?? self::buildSpanExporter($options, null),
                Clock::getDefault(),
                $options->maxQueueSize ?? BatchSpanProcessor::DEFAULT_MAX_QUEUE_SIZE,
                $options->scheduledDelayMillis ?? BatchSpanProcessor::DEFAULT_SCHEDULE_DELAY,
                BatchSpanProcessor::DEFAULT_EXPORT_TIMEOUT,
                $options->maxExportBatchSize ?? BatchSpanProcessor::DEFAULT_MAX_EXPORT_BATCH_SIZE,
            )]
            : [];

        return new self(
            new TracerProvider($processors, self::sampler($options), $resource),
            self::buildMeterProvider($options, $resource, $metricExporter, null),
        );
    }

    /**
     * Export each span directly as it ends, with no batching. Intended for async
     * runtimes; pass {@see ampHttpClient()} as `$httpClient` for the Amp/Revolt
     * runtime, or leave it null under OpenSwoole (its runtime hooks make the
     * discovered client non-blocking).
     *
     * @param SpanExporterInterface|null $spanExporter override the OTLP span
     *        exporter (primarily for tests / custom backends)
     * @param MetricExporterInterface|null $metricExporter override the OTLP metric
     *        exporter (primarily for tests / custom backends)
     */
    public static function direct(
        ObservabilityOptions $options,
        ?ClientInterface $httpClient = null,
        ?SpanExporterInterface $spanExporter = null,
        ?MetricExporterInterface $metricExporter = null,
    ): self {
        self::ensureSdk();

        $resource = self::resource($options);
        $processors = $options->traces
            ? [new SimpleSpanProcessor($spanExporter ?? self::buildSpanExporter($options, $httpClient))]
            : [];

        return new self(
            new TracerProvider($processors, self::sampler($options), $resource),
            self::buildMeterProvider($options, $resource, $metricExporter, $httpClient),
        );
    }

    /**
     * Build a non-blocking PSR-18 client backed by amphp/http-client, for use as
     * the `$httpClient` of {@see direct()} on the Amp/Revolt runtime.
     */
    public static function ampHttpClient(): ClientInterface
    {
        if (!class_exists(PsrHttpClient::class)) {
            throw new ConfigurationException(
                'Telemetry',
                'The "amphp/http-client-psr7" package is required for non-blocking OTLP export on the Amp runtime. Install it with "composer require amphp/http-client-psr7".',
            );
        }

        return AmpPsr18Client::create();
    }

    /** Bridge the configured OpenTelemetry tracer onto the SDK {@see Tracer} seam. */
    public function tracer(): Tracer
    {
        return new OtelTracer($this->tracerProvider->getTracer(OtelTracer::INSTRUMENTATION_NAME));
    }

    /** Bridge the configured OpenTelemetry meter onto the SDK {@see Meter} seam. */
    public function meter(): Meter
    {
        return new OtelMeter($this->meterProvider->getMeter(OtelMeter::INSTRUMENTATION_NAME));
    }

    public function tracerProvider(): TracerProvider
    {
        return $this->tracerProvider;
    }

    public function meterProvider(): MeterProviderInterface
    {
        return $this->meterProvider;
    }

    /** Drain buffered telemetry without tearing the providers down. */
    public function forceFlush(): void
    {
        $this->tracerProvider->forceFlush();
        $this->meterProvider->forceFlush();
    }

    /** Flush and shut down the providers (typically once, at the end of the process). */
    public function shutdown(): void
    {
        $this->tracerProvider->shutdown();
        $this->meterProvider->shutdown();
    }

    private static function buildMeterProvider(
        ObservabilityOptions $options,
        ResourceInfo $resource,
        ?MetricExporterInterface $metricExporter,
        ?ClientInterface $httpClient,
    ): MeterProviderInterface {
        $builder = MeterProvider::builder()->setResource($resource);

        if ($options->metrics) {
            $builder->addReader(new ExportingReader(
                $metricExporter ?? self::buildMetricExporter($options, $httpClient),
            ));
        }

        return $builder->build();
    }

    private static function buildSpanExporter(ObservabilityOptions $options, ?ClientInterface $httpClient): SpanExporterInterface
    {
        self::ensureOtlp();

        return new SpanExporter(self::buildTransport($options->tracesEndpoint(), $options, $httpClient));
    }

    private static function buildMetricExporter(ObservabilityOptions $options, ?ClientInterface $httpClient): MetricExporterInterface
    {
        self::ensureOtlp();

        return new MetricExporter(self::buildTransport($options->metricsEndpoint(), $options, $httpClient));
    }

    /**
     * @return TransportInterface<string>
     */
    private static function buildTransport(string $endpoint, ObservabilityOptions $options, ?ClientInterface $httpClient): TransportInterface
    {
        // The contrib OtlpHttpTransportFactory ignores any injected client, so an
        // explicit (non-blocking) client must go through the base PSR factory.
        if ($httpClient !== null) {
            return (new PsrTransportFactory($httpClient))
                ->create($endpoint, $options->contentType, $options->headers);
        }

        return (new OtlpHttpTransportFactory())
            ->create($endpoint, $options->contentType, $options->headers);
    }

    private static function sampler(ObservabilityOptions $options): SamplerInterface
    {
        return new ParentBased(
            $options->samplerRatio === null
                ? new AlwaysOnSampler()
                : new TraceIdRatioBasedSampler($options->samplerRatio),
        );
    }

    private static function resource(ObservabilityOptions $options): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create(['service.name' => $options->serviceName])),
        );
    }

    private static function ensureSdk(): void
    {
        if (!class_exists(TracerProvider::class)) {
            throw new ConfigurationException(
                'Telemetry',
                'The "open-telemetry/sdk" package is required for the observability presets. Install it with "composer require open-telemetry/sdk".',
            );
        }
    }

    private static function ensureOtlp(): void
    {
        if (!class_exists(SpanExporter::class)) {
            throw new ConfigurationException(
                'Telemetry',
                'The "open-telemetry/exporter-otlp" package is required to export telemetry. Install it with "composer require open-telemetry/exporter-otlp", or pass your own exporter.',
            );
        }
    }
}
