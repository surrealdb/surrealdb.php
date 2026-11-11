<?php

namespace SurrealDB\AgentMemory;

use Composer\InstalledVersions;

/**
 * The `User-Agent` the client sends: `surrealdb-memory-php/<version>`, the PHP
 * form of the JS client's `surrealdb-memory-js/<version>`.
 *
 * @internal
 */
final class UserAgent
{
    public const string PRODUCT = 'surrealdb-memory-php';

    /** The version reported when Composer cannot name one. */
    public const string FALLBACK_VERSION = 'unknown';

    private const string PACKAGE = 'surrealdb/surrealdb.php';

    private static ?string $value = null;

    /** The header value, cached per process: the installed version cannot change under a running worker. */
    public static function value(): string
    {
        return self::$value ??= self::format(self::installedVersion());
    }

    /**
     * Builds the header from a Composer pretty version. A tag's leading `v` is
     * dropped (`v2.0.0-alpha.3` becomes `2.0.0-alpha.3`, as the JS client
     * reports its version), each run of characters an RFC 9110 product token
     * cannot hold (such as a branch's `/`) becomes `-`, and a missing version
     * becomes `unknown`.
     */
    public static function format(?string $version): string
    {
        $version = preg_replace('/^v(?=\d)/', '', trim($version ?? '')) ?? '';
        $token = preg_replace('/[^0-9A-Za-z.+_-]+/', '-', $version) ?? '';

        return self::PRODUCT . '/' . ($token === '' ? self::FALLBACK_VERSION : $token);
    }

    private static function installedVersion(): ?string
    {
        // Guarded for loaders other than Composer's; isInstalled() comes first
        // because getPrettyVersion() throws for a package Composer does not know.
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled(self::PACKAGE)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE);
    }
}
