<?php

namespace SurrealDB\Exceptions;

/** Thrown when the connected SurrealDB version is outside the supported range. */
final class UnsupportedVersionException extends SurrealException
{
    public function __construct(
        public readonly string $version,
        public readonly string $minimum,
        public readonly string $maximum,
    ) {
        parent::__construct(
            "The version \"{$version}\" reported by the server is not supported by this library, " .
            "expected a version that satisfies >= {$minimum} < {$maximum}",
        );
    }
}
