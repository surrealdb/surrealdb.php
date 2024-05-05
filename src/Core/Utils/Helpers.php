<?php

namespace Surreal\Core\Utils;

use Surreal\Exceptions\SurrealException;

final class Helpers
{
    public static function isAssoc(mixed $data): bool
    {
        if ([] === $data || !is_array($data)) {
            return false;
        }

        return array_keys($data) !== range(0, count($data) - 1);
    }

    public static function parseTarget(array $target): array
    {
        if(!array_key_exists("namespace", $target)) {
            $target["namespace"] = null;
        }

        if(!array_key_exists("database", $target)) {
            $target["database"] = null;
        }

        return [$target["namespace"], $target["database"]];
    }
}