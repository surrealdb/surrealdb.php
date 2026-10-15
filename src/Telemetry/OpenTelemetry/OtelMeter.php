<?php

namespace SurrealDB\Telemetry\OpenTelemetry;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterInterface;
use SurrealDB\Contracts\Counter;
use SurrealDB\Contracts\Histogram;
use SurrealDB\Contracts\Meter;
use SurrealDB\Exceptions\ConfigurationException;

/**
 * Bridges the SDK {@see Meter} seam onto an OpenTelemetry meter.
 *
 * Requires the optional `open-telemetry/api` package. Construct via
 * {@see fromGlobals()} to use the globally-registered provider, or pass a meter
 * obtained from your own provider.
 */
final class OtelMeter implements Meter
{
    public const INSTRUMENTATION_NAME = 'surrealdb/surrealdb.php';

    public function __construct(private readonly MeterInterface $meter) {}

    /**
     * Build from the globally-registered OpenTelemetry meter provider. When no
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

        return new self(Globals::meterProvider()->getMeter(self::INSTRUMENTATION_NAME, $version));
    }

    public function counter(string $name, ?string $unit = null, ?string $description = null): Counter
    {
        return new OtelCounter($this->meter->createCounter($name, $unit, $description));
    }

    public function histogram(string $name, ?string $unit = null, ?string $description = null): Histogram
    {
        return new OtelHistogram($this->meter->createHistogram($name, $unit, $description));
    }
}
