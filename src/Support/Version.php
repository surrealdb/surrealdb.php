<?php

namespace SurrealDB\SDK\Support;

/** Version range checking against the SDK's supported SurrealDB versions. */
final class Version
{
    public const string MINIMUM = '1.0.0';
    public const string MAXIMUM = '4.0.0';

    public static function isSupported(
        string $version,
        string $minimum = self::MINIMUM,
        string $maximum = self::MAXIMUM,
    ): bool {
        $normalized = self::normalize($version);

        return version_compare($normalized, self::normalize($minimum), '>=')
            && version_compare($normalized, self::normalize($maximum), '<');
    }

    public static function normalize(string $version): string
    {
        $version = preg_replace('/^[^0-9]*/', '', $version) ?? $version;
        $version = preg_replace('/[+\-].*$/', '', $version) ?? $version;

        return $version === '' ? '0.0.0' : $version;
    }
}
