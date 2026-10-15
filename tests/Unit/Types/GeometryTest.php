<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use SurrealDB\Types\Geometry;
use SurrealDB\Types\GeometryCollection;
use SurrealDB\Types\GeometryLine;
use SurrealDB\Types\GeometryMultiPoint;
use SurrealDB\Types\GeometryPoint;
use SurrealDB\Types\GeometryPolygon;

final class GeometryTest extends TestCase
{
    public function testPointGeoJson(): void
    {
        $point = new GeometryPoint(1.0, 2.0);

        $this->assertSame(['type' => 'Point', 'coordinates' => [1.0, 2.0]], $point->toGeoJson());
        $this->assertSame(['$geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]]], $point->jsonSerialize());
    }

    public function testPointEscapeIsObjectLiteral(): void
    {
        $point = new GeometryPoint(1.0, 2.0);

        $this->assertSame('{ "type": s"Point", "coordinates": [ 1, 2 ] }', $point->escape());
    }

    public function testLineGeoJson(): void
    {
        $line = new GeometryLine(new GeometryPoint(0.0, 0.0), new GeometryPoint(1.0, 1.0));

        $this->assertSame(
            ['type' => 'LineString', 'coordinates' => [[0.0, 0.0], [1.0, 1.0]]],
            $line->toGeoJson(),
        );
    }

    public function testPolygonGeoJson(): void
    {
        $ring = new GeometryLine(
            new GeometryPoint(0.0, 0.0),
            new GeometryPoint(1.0, 0.0),
            new GeometryPoint(1.0, 1.0),
            new GeometryPoint(0.0, 0.0),
        );
        $polygon = new GeometryPolygon($ring);

        $this->assertSame('Polygon', $polygon->toGeoJson()['type']);
        $this->assertCount(1, $polygon->toGeoJson()['coordinates']);
    }

    public function testFromGeoJsonRoundTrip(): void
    {
        $original = new GeometryMultiPoint(new GeometryPoint(1.0, 2.0), new GeometryPoint(3.0, 4.0));
        $restored = Geometry::fromGeoJson($original->toGeoJson());

        $this->assertTrue($original->equals($restored));
    }

    public function testCollection(): void
    {
        $collection = new GeometryCollection(
            new GeometryPoint(1.0, 2.0),
            new GeometryLine(new GeometryPoint(0.0, 0.0), new GeometryPoint(1.0, 1.0)),
        );

        $restored = Geometry::fromGeoJson($collection->toGeoJson());

        $this->assertTrue($collection->equals($restored));
    }

    public function testEquality(): void
    {
        $this->assertTrue(new GeometryPoint(1.0, 2.0)->equals(new GeometryPoint(1.0, 2.0)));
        $this->assertFalse(new GeometryPoint(1.0, 2.0)->equals(new GeometryPoint(2.0, 1.0)));
    }
}
