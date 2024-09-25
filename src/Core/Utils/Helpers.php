<?php

namespace Surreal\Core\Utils;

use Composer\Semver\Semver;
use Surreal\Cbor\Types\AbstractGeometry;
use Surreal\Cbor\Types\Decimal;
use Surreal\Cbor\Types\Duration;
use Surreal\Cbor\Types\Future;
use Surreal\Cbor\Types\Range;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\StringRecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Cbor\Types\Uuid;
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

    /**
     * @throws SurrealException
     */
    public static function processAuthVariables(
        array  $auth,
        string $version
    ): array
    {
        $map = ["namespace" => "NS", "database" => "DB"];
        $v1 = Semver::satisfies($version, ">=1.0.0 <2.0.0");

        $map = match (true) {
            $v1 => array_merge($map, ["scope" => "SC"]),
            default => array_merge($map, ["access" => "AC"])
        };

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