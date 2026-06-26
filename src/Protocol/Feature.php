<?php

namespace SurrealDB\SDK\Protocol;

/**
 * A capability that may be supported by a specific engine and/or version range
 * of SurrealDB. Mirrors the JS `Feature` class.
 */
final readonly class Feature
{
    public function __construct(
        public string $name,
        public ?string $since = null,
        public ?string $until = null,
    ) {}

    /**
     * Whether the given server version falls within this feature's range.
     */
    public function supports(string $version): bool
    {
        $normalized = self::normalize($version);

        if ($this->since !== null && version_compare($normalized, self::normalize($this->since), '<')) {
            return false;
        }

        if ($this->until !== null && version_compare($normalized, self::normalize($this->until), '>=')) {
            return false;
        }

        return true;
    }

    private static function normalize(string $version): string
    {
        $version = preg_replace('/^[^0-9]*/', '', $version) ?? $version;
        $version = preg_replace('/[+\-].*$/', '', $version) ?? $version;

        return $version === '' ? '0.0.0' : $version;
    }
}
