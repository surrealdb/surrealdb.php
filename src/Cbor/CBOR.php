<?php

namespace Surreal\Cbor;

use Beau\CborPHP\CborDecoder;
use Beau\CborPHP\CborEncoder;
use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\exceptions\CborException;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Ramsey\Uuid\UuidInterface;
use Surreal\Cbor\Enums\CustomTag;
use Surreal\Cbor\Types\DateTime;
use Surreal\Cbor\Types\Duration;
use Surreal\Cbor\Types\GeometryCollection;
use Surreal\Cbor\Types\GeometryLine;
use Surreal\Cbor\Types\GeometryMultiLine;
use Surreal\Cbor\Types\GeometryMultiPoint;
use Surreal\Cbor\Types\GeometryMultiPolygon;
use Surreal\Cbor\Types\GeometryPoint;
use Surreal\Cbor\Types\GeometryPolygon;
use Surreal\Cbor\Types\None;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Cbor\Types\Uuid;
use Brick\Math\BigDecimal;

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

            return match ($value::class) {

                // Tags from spec
                DateTimeInterface::class => new TaggedValue(
                    CustomTag::SPEC_DATETIME->value,
                    $value->format(DateTimeInterface::ATOM)
                ),

                DateTimeImmutable::class => new TaggedValue(
                    CustomTag::CUSTOM_DATETIME->value,
                    $value->format(DateTimeInterface::ATOM)
                ),

                None::class => new TaggedValue(
                    CustomTag::NONE->value,
                    null
                ),

                // Custom classes
                Table::class => new TaggedValue(
                    CustomTag::TABLE->value,
                    $value->getTable()
                ),

                RecordId::class => new TaggedValue(
                    CustomTag::RECORD_ID->value,
                    (string)$value
                ),

                BigDecimal::class => new TaggedValue(
                    CustomTag::STRING_DECIMAL->value,
                    $value->toFloat()
                ),

                UuidInterface::class => new TaggedValue(
                    CustomTag::SPEC_UUID->value,
                    $value
                ),

                GeometryPoint::class => new TaggedValue(
                    CustomTag::GEOMETRY_POINT->value,
                    $value->point
                ),

                GeometryLine::class => new TaggedValue(
                    CustomTag::GEOMETRY_LINE->value,
                    $value->line
                ),

                GeometryPolygon::class => new TaggedValue(
                    CustomTag::GEOMETRY_POLYGON->value,
                    $value->polygon
                ),

                GeometryMultiPoint::class => new TaggedValue(
                    CustomTag::GEOMETRY_MULTIPOINT->value,
                    $value->points
                ),

                GeometryMultiLine::class => new TaggedValue(
                    CustomTag::GEOMETRY_MULTILINE->value,
                    $value->lines
                ),

                GeometryMultiPolygon::class => new TaggedValue(
                    CustomTag::GEOMETRY_MULTIPOLYGON->value,
                    $value->polygons
                ),

                GeometryCollection::class => new TaggedValue(
                    CustomTag::GEOMETRY_COLLECTION->value,
                    $value->collection
                ),

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

            if (!($tagged instanceof TaggedValue)) {
                return $tagged;
            }

            return match (CustomTag::tryFrom($tagged->tag)) {
                CustomTag::SPEC_DATETIME => new \DateTime($tagged->value),
                CustomTag::NONE => new None(),

                CustomTag::TABLE => $tagged->value,
                CustomTag::RECORD_ID => RecordId::fromArray($tagged->value),
                CustomTag::STRING_UUID => Uuid::fromString($tagged->value),

                CustomTag::STRING_DECIMAL => BigDecimal::of($tagged->value),

                CustomTag::CUSTOM_DATETIME => DateTime::fromCborCustomDate($tagged->value),
                CustomTag::STRING_DURATION => new Duration($tagged->value),
                CustomTag::CUSTOM_DURATION => Duration::fromCborCustomDuration([$tagged->value[0], $tagged->value[1]]),

                CustomTag::SPEC_UUID => Uuid::fromCborByteString($tagged->value),

                CustomTag::GEOMETRY_POINT => new GeometryPoint($tagged->value),
                CustomTag::GEOMETRY_LINE => new GeometryLine($tagged->value),
                CustomTag::GEOMETRY_POLYGON => new GeometryPolygon($tagged->value),
                CustomTag::GEOMETRY_MULTIPOINT => new GeometryMultiPoint($tagged->value),
                CustomTag::GEOMETRY_MULTILINE => new GeometryMultiLine($tagged->value),
                CustomTag::GEOMETRY_MULTIPOLYGON => new GeometryMultiPolygon($tagged->value),
                CustomTag::GEOMETRY_COLLECTION => new GeometryCollection($tagged->value),

                default => throw new CborException("Unknown tag: " . $tagged->tag)
            };
        });
    }
}