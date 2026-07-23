<?php

namespace SurrealDB\Query\Concerns;

use SurrealDB\Exceptions\ExpressionException;
use SurrealDB\Query\BoundQuery;

/**
 * Adds a `TIMEOUT <duration>` modifier. Duration literals are emitted inline
 * (they cannot be parameters) after a light validation pass.
 */
trait HasTimeout
{
    private const string DURATION_PATTERN = '/^(\d+(ns|us|ms|s|m|h|d|w|y))+$/';

    public private(set) ?string $timeout = null;

    /**
     * Abort the statement if it runs longer than the given duration (e.g.
     * "5s", "1m30s").
     */
    public function timeout(string $duration): static
    {
        $this->timeout = $duration;

        return $this;
    }

    protected function applyTimeout(BoundQuery $query): void
    {
        if ($this->timeout === null) {
            return;
        }

        if (preg_match(self::DURATION_PATTERN, $this->timeout) !== 1) {
            throw new ExpressionException("Invalid timeout duration: {$this->timeout}");
        }

        $query->append(' TIMEOUT ' . $this->timeout);
    }
}
