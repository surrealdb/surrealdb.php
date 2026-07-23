<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use SurrealDB\Types\Bytes;
use SurrealDB\Types\File;
use SurrealDB\Types\Future;
use SurrealDB\Types\None;
use SurrealDB\Types\Set;
use SurrealDB\Types\Table;

final class MiscTypesTest extends TestCase
{
    public function testFile(): void
    {
        $file = new File('bucket', '/path/to.txt');

        $this->assertSame('bucket:/path/to.txt', (string) $file);
        $this->assertSame('f"bucket:/path/to.txt"', $file->escape());
        $this->assertSame(['$file' => ['bucket' => 'bucket', 'key' => '/path/to.txt']], $file->jsonSerialize());
    }

    public function testFileNormalizesKeyLeadingSlash(): void
    {
        $this->assertSame('/avatar.png', new File('media', 'avatar.png')->key);
    }

    public function testFuture(): void
    {
        $future = new Future('{ time::now() }');

        $this->assertSame('<future> { time::now() }', $future->escape());
        $this->assertSame(['$future' => '{ time::now() }'], $future->jsonSerialize());
    }

    public function testBytesRoundTrip(): void
    {
        $bytes = new Bytes('hello');

        $this->assertSame('aGVsbG8', $bytes->toBase64());
        $this->assertSame(['$bytes' => 'aGVsbG8'], $bytes->jsonSerialize());
        $this->assertTrue(Bytes::fromBase64('aGVsbG8')->equals($bytes));
    }

    public function testBytesEscape(): void
    {
        $this->assertSame('encoding::base64::decode("aGVsbG8")', new Bytes('hello')->escape());
    }

    public function testNone(): void
    {
        $none = None::instance();

        $this->assertSame('NONE', $none->escape());
        $this->assertSame(['$none' => true], $none->jsonSerialize());
        $this->assertTrue($none->equals(None::instance()));
    }

    public function testSetDeduplicatesScalars(): void
    {
        $set = new Set([1, 2, 2, 3, 3, 3]);

        $this->assertSame([1, 2, 3], $set->values);
        $this->assertSame('[ 1, 2, 3 ]', $set->escape());
        $this->assertSame(['$set' => [1, 2, 3]], $set->jsonSerialize());
    }

    public function testSetDeduplicatesValueObjects(): void
    {
        $set = new Set([new Table('a'), new Table('a'), new Table('b')]);

        $this->assertCount(2, $set->values);
    }
}
