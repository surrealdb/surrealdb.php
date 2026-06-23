<?php

namespace SurrealDB\SDK\Query\Concerns;

use SurrealDB\SDK\Query\BoundQuery;

/**
 * Adds a `VERSION <datetime>` modifier for use with versioned storage engines.
 */
trait HasVersion
{
    public private(set) ?string $version = null;

    /**
     * Pin the statement to a historical version (ISO-8601 datetime string).
     */
    public function version(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    protected function applyVersion(BoundQuery $query): void
    {
        if ($this->version === null) {
            return;
        }

        $query->append(' VERSION ' . $query->bind($this->version));
    }
}
