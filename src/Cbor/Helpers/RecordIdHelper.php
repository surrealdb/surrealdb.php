<?php

namespace Surreal\Cbor\Helpers;

use Surreal\Cbor\Types\Uuid;
use Surreal\Core\Utils\Helpers;

class RecordIdHelper
{
    public static function isValidIdPart(mixed $value): bool
    {
        if ($value instanceof Uuid) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (is_array($value) || Helpers::isAssoc($value)) {
            return true;
        }

        if (!is_null($value)) {
            return true;
        }

        return false;
    }

    public static function escapeIdent(string $ident): string
    {
        if (is_numeric($ident)) {
            return "⟨" . $ident . "⟩";
        }

        for ($i = 0; $i < strlen($ident); $i++) {
            $code = ord($ident[$i]);
            if (
                !($code > 47 && $code < 58) && // numeric (0-9)
                !($code > 64 && $code < 91) && // upper alpha (A-Z)
                !($code > 96 && $code < 123) && // lower alpha (a-z)
                !($code === 95) // underscore (_)
            ) {
                return "⟨" . str_replace("⟩", "⟩", $ident) . "⟩";
            }
        }

        return $ident;
    }

    public static function escapeIdPart(mixed $value): string
    {
        if($value instanceof Uuid) {
            return 'd"' . $value->toString() . '"';
        } else if(is_string($value)) {
            return RecordIdHelper::escapeIdent($value);
        } else if(is_numeric($value) || is_int($value) || is_float($value)) {
            return RecordIdHelper::escapeNumber($value);
        }

        return json_encode($value);
    }

    public static function escapeNumber(int $number): string
    {
        return $number <= PHP_INT_MAX ? strval($number) : "⟨" . $number . "⟩";
    }
}