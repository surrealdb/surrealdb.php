<?php

namespace SurrealDB\SDK\Exceptions;

use SurrealDB\SDK\Protocol\Feature;

/** Thrown when a feature is not available in the connected version of SurrealDB. */
final class UnavailableFeatureException extends SurrealException
{
    public function __construct(
        public readonly Feature $feature,
        public readonly string $version,
    ) {
        parent::__construct(
            "The version of SurrealDB ({$version}) does not support the feature: {$feature->name}",
        );
    }
}
