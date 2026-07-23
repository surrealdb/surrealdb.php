<?php

namespace SurrealDB\Telemetry\Psr;

use Psr\Log\LoggerInterface;
use SurrealDB\Contracts\Histogram;

/** A {@see Histogram} that logs each measurement as a structured PSR-3 line. */
final class Psr3Histogram implements Histogram
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level,
        private readonly string $name,
        private readonly ?string $unit = null,
    ) {}

    public function record(int|float $value, array $attributes = []): void
    {
        $this->logger->log($this->level, 'histogram {metric} = {value}', [
            'metric' => $this->name,
            'value' => $value,
            'unit' => $this->unit,
            'attributes' => $attributes,
        ]);
    }
}
