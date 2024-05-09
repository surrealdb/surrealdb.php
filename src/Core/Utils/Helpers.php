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
        if (!array_key_exists("namespace", $target)) {
            $target["namespace"] = null;
        }

        if (!array_key_exists("database", $target)) {
            $target["database"] = null;
        }

        return [$target["namespace"], $target["database"]];
    }

    public static function processAuthVariables(array $auth): array
    {
        $temp = $auth;

        if(array_key_exists("namespace", $auth)) {
            $temp["NS"] = $auth["namespace"];
            unset($temp["namespace"]);
        }

        if(array_key_exists("database", $auth)) {
            $temp["DB"] = $auth["database"];
            unset($temp["database"]);
        }

        if(array_key_exists("scope", $auth)) {
            $temp["SC"] = $auth["scope"];
            unset($temp["scope"]);
        }

        return $temp;
    }

    public static function escapeIdent(string $ident): string
    {
        $len = strlen($ident);

        for ($i = 0; $i < $len; $i++) {
            $code = ord($ident[$i]);
            if (
                !($code > 47 && $code < 58) && // numeric (0-9)
                !($code > 64 && $code < 91) && // upper alpha (A-Z)
                !($code > 96 && $code < 123) && // lower alpha (a-z)
                !($code === 95) // underscore (_)
            ) {
                $str = str_replace("⟩", "\⟩", $ident[$i]);
                return "⟨" . $str . "⟩";
            }
        }

        return $ident;
    }
}