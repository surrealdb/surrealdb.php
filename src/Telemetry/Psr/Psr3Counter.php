<?php

namespace SurrealDB\Telemetry\Psr;

use Psr\Log\LoggerInterface;
use SurrealDB\Contracts\Counter;

/** A {@see Counter} that logs each increment as a structured PSR-3 line. */
final class Psr3Counter implements Counter
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level,
        private readonly string $name,
        private readonly ?string $unit = null,
    ) {}

    public function add(int|float $value = 1, array $attributes = []): void
    {
        $this->logger->log($this->level, 'counter {metric} += {value}', [
            'metric' => $this->name,
            'value' => $value,
            'unit' => $this->unit,
            'attributes' => $attributes,
        ]);
    }
}
