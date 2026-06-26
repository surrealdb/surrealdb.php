<?php

namespace SurrealDB\SDK\Protocol;

/** SurrealDB version information returned by the `version` RPC. */
final readonly class VersionInfo
{
    public function __construct(public string $version) {}

    public function __toString(): string
    {
        return $this->version;
    }
}
