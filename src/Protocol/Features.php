<?php

namespace SurrealDB\SDK\Protocol;

/**
 * The catalogue of features known to the SDK, including the SurrealDB version
 * in which each became available. Mirrors the JS `Features` registry.
 */
final class Features
{
    public static function liveQueries(): Feature
    {
        return new Feature('live-queries');
    }

    public static function sessions(): Feature
    {
        return new Feature('sessions', '3.0.0');
    }

    public static function api(): Feature
    {
        return new Feature('api', '3.0.0');
    }

    public static function refreshTokens(): Feature
    {
        return new Feature('refresh-tokens', '3.0.0');
    }

    public static function transactions(): Feature
    {
        return new Feature('transactions', '3.0.0');
    }

    public static function exportImportRaw(): Feature
    {
        return new Feature('export-import-raw');
    }

    public static function surrealMl(): Feature
    {
        return new Feature('surreal-ml');
    }
}
