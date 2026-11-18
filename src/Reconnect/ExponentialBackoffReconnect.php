<?php

namespace SurrealDB\Reconnect;

use SurrealDB\Contracts\ReconnectStrategyInterface;

/**
 * Exponential backoff with jitter. Port of the JS `ReconnectContext`: the
 * caller performs the wait (so it can be cooperative under async runtimes).
 */
final class ExponentialBackoffReconnect implements ReconnectStrategyInterface
{
    private int $attempts = 0;

    public function __construct(
        private readonly bool $enabled = true,
        private readonly int $maxAttempts = 5,
        private readonly float $retryDelay = 1.0,
        private readonly float $retryDelayMax = 60.0,
        private readonly float $retryDelayMultiplier = 2.0,
        private readonly float $retryDelayJitter = 0.1,
    ) {}

    /**
     * @param array<string,mixed>|bool|ReconnectStrategyInterface $option
     */
    public static function fromOption(bool|array|ReconnectStrategyInterface $option): ReconnectStrategyInterface
    {
        if ($option instanceof ReconnectStrategyInterface) {
            return $option;
        }

        if ($option === false) {
            return new self(enabled: false);
        }

        if ($option === true) {
            return new self();
        }

        return new self(
            enabled: (bool) ($option['enabled'] ?? true),
            maxAttempts: (int) ($option['attempts'] ?? 5),
            retryDelay: (float) ($option['retryDelay'] ?? 1.0),
            retryDelayMax: (float) ($option['retryDelayMax'] ?? 60.0),
            retryDelayMultiplier: (float) ($option['retryDelayMultiplier'] ?? 2.0),
            retryDelayJitter: (float) ($option['retryDelayJitter'] ?? 0.1),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function allowed(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->maxAttempts === -1 || $this->attempts < $this->maxAttempts;
    }

    public function reset(): void
    {
        $this->attempts = 0;
    }

    public function nextDelay(): float
    {
        $this->attempts++;

        $delay = $this->retryDelay * ($this->retryDelayMultiplier ** $this->attempts);
        $jitter = $this->randomFloat(-$this->retryDelayJitter, $this->retryDelayJitter);

        return min($delay * (1 + $jitter), $this->retryDelayMax);
    }

    private function randomFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}
