<?php

namespace Surreal\Core\Utils;

use Surreal\Cbor\Abstract\AbstractGeometry;
use Surreal\Cbor\Types\Decimal;
use Surreal\Cbor\Types\Duration;
use Surreal\Cbor\Types\Future;
use Surreal\Cbor\Types\Range;
use Surreal\Cbor\Types\Record\RecordId;
use Surreal\Cbor\Types\Record\StringRecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Cbor\Types\Uuid;

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
        $map = ["namespace" => "NS", "database" => "DB"];

        if (array_key_exists("access", $auth)) {
            $map["access"] = "AC";
        } else if(array_key_exists("scope", $auth)) {
            $map["scope"] = "SC";
        }

        foreach ($map as $key => $value) {
            if (array_key_exists($key, $auth)) {
                $auth[$value] = $auth[$key];
                unset($auth[$key]);
            }
        }

        return $auth;
    }

    public static function toSurrealQLString(mixed $value): string
    {
        if(is_null($value)) {
            return "NULL";
        }

        if(empty($value)) {
            return "NONE";
        }

        if($value instanceof \DateTime) {
            return "d" . json_encode($value);
        }

        if($value instanceof Uuid) {
            return "u" . $value->toString();
        }

        if($value instanceof RecordId || $value instanceof StringRecordId) {
            return "r" . json_encode($value);
        }

        if(is_string($value)) {
            return "s" . json_encode($value);
        }

        if($value instanceof AbstractGeometry) {
            return "g" . json_encode($value);
        }

        switch (get_class($value)) {
            case Decimal::class:
            case Duration::class:
            case Future::class:
            case Range::class:
            case Table::class:
                return json_encode($value);
        }

        if(is_array($value)) {
            $output = "[ ";
            foreach ($value as $item) {
                $output .= self::toSurrealQLString($item) . ", ";
            }
            return $output . " ]";
        }

        if(Helpers::isAssoc($value)) {
            $output = "{ ";
            foreach ($value as $key => $item) {
                $output .= $key . ": " . self::toSurrealQLString($item) . ", ";
            }
            return $output . " }";
        }

        return json_encode($value);
    }
}