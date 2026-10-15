<?php

namespace SurrealDB\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Support\Jsonify;
use SurrealDB\SDK\Types\DateTime;
use SurrealDB\SDK\Types\Decimal;
use SurrealDB\SDK\Types\Duration;
use SurrealDB\SDK\Types\GeometryPoint;
use SurrealDB\SDK\Types\None;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Uuid;

final class JsonifyTest extends TestCase
{
    public function testSurrealTypesBecomePlainJsonValues(): void
    {
        $uuid = Uuid::fromString('0191b7ec-1a4c-7a7e-a8f2-4a4c4a7d8f3e');

        $result = Jsonify::value([
            'id' => new RecordId('person', 'tobie'),
            'table' => new Table('person'),
            'born' => DateTime::fromString('2024-01-02T03:04:05Z'),
            'ttl' => Duration::fromString('1h30m'),
            'price' => new Decimal('9.99'),
            'uuid' => $uuid,
            'missing' => None::instance(),
            'tags' => new Set(['a', new RecordId('tag', 'b')]),
            'at' => new GeometryPoint(1.5, 2.5),
            'nested' => [['ref' => new RecordId('post', 1)]],
            'plain' => 42,
        ]);

        $this->assertSame([
            'id' => 'person:tobie',
            'table' => 'person',
            'born' => '2024-01-02T03:04:05Z',
            'ttl' => '1h30m',
            'price' => '9.99',
            'uuid' => '0191b7ec-1a4c-7a7e-a8f2-4a4c4a7d8f3e',
            'missing' => null,
            'tags' => ['a', 'tag:b'],
            'at' => ['type' => 'Point', 'coordinates' => [1.5, 2.5]],
            'nested' => [['ref' => 'post:1']],
            'plain' => 42,
        ], $result);

        $this->assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));
    }
}
