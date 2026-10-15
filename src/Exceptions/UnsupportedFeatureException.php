<?php

namespace SurrealDB\Exceptions;

use SurrealDB\Protocol\Feature;

/** Thrown when the configured engine does not support a requested feature. */
final class UnsupportedFeatureException extends SurrealException
{
    public function __construct(public readonly Feature $feature)
    {
        parent::__construct("The configured engine does not support the feature: {$feature->name}");
    }
}
