<?php

namespace Surreal\Cbor;

use Beau\CborPHP\CborDecoder;
use Beau\CborPHP\CborEncoder;
use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\exceptions\CborException;
use Exception;
use Ramsey\Uuid\UuidInterface;
use Surreal\Cbor\Types\DateTime;
use Surreal\Cbor\Types\Duration;
use Surreal\Cbor\Types\GeometryCollection;
use Surreal\Cbor\Types\GeometryLine;
use Surreal\Cbor\Types\GeometryMultiLine;
use Surreal\Cbor\Types\GeometryMultiPoint;
use Surreal\Cbor\Types\GeometryMultiPolygon;
use Surreal\Cbor\Types\GeometryPoint;
use Surreal\Cbor\Types\GeometryPolygon;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Cbor\Types\Uuid;

class CBOR
{
    /**
     * Encodes data to CBOR
     * @param mixed $data
     * @return string|null
     * @throws CborException
     */
    public static function encode(mixed $data): ?string
    {
        return CborEncoder::encode($data, function ($key, $value) {

            return match($value::class) {

                // Tags from spec
                \DateTime::class => new TaggedValue(0, $value->format(\DateTimeInterface::ATOM)),
                \DateTimeImmutable::class => new TaggedValue(12, $value->format(\DateTimeInterface::ATOM)),

                // Custom classes
                Table::class => new TaggedValue(7, $value->getTable()),
                RecordId::class => new TaggedValue(8, (string)$value),
                UuidInterface::class => new TaggedValue(37, $value),

                GeometryPoint::class => new TaggedValue(88, $value->point),
                GeometryLine::class => new TaggedValue(89, $value->line),
                GeometryPolygon::class => new TaggedValue(90, $value->polygon),
                GeometryMultiPoint::class => new TaggedValue(91, $value->points),
                GeometryMultiLine::class => new TaggedValue(92, $value->lines),
                GeometryMultiPolygon::class => new TaggedValue(93, $value->polygons),
                GeometryCollection::class => new TaggedValue(94, $value->collection),

                default => $value
            };
        });
    }

    /**
     * Decodes CBOR data
     * @param string $data
     * @return mixed
     * @throws Exception
     */
    public static function decode(string $data): mixed
    {
        return CborDecoder::decode($data, function ($key, $tagged) {

            if(!($tagged instanceof TaggedValue)) {
                return $tagged;
            }

            return match ($tagged->tag) {
                0 => new \DateTime($tagged->value),
                6 => null,

                7 => Table::fromString($tagged->value),
                8 => RecordId::fromArray($tagged->value),
                9 => Uuid::fromString($tagged->value),

                10 => new \Decimal($tagged->value),

                12 => DateTime::fromCborCustomDate($tagged->value),
                13 => new Duration($tagged->value),
                14 => Duration::fromCborCustomDuration([$tagged->value[0], $tagged->value[1]]),

                37 => Uuid::fromCborByteString($tagged->value),

                88 => new GeometryPoint($tagged->value),
                89 => new GeometryLine($tagged->value),
                90 => new GeometryPolygon($tagged->value),
                91 => new GeometryMultiPoint($tagged->value),
                92 => new GeometryMultiLine($tagged->value),
                93 => new GeometryMultiPolygon($tagged->value),
                94 => new GeometryCollection($tagged->value),

                default => throw new CborException("Unknown tag: " . $tagged->tag)
            };
        });
    }
}