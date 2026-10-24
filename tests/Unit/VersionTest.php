<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Support\Version;

final class VersionTest extends TestCase
{
    /**
     * @return iterable<string,array{string,bool}>
     */
    public static function versions(): iterable
    {
        yield '2.x' => ['surrealdb-2.6.2', true];
        yield 'latest 3.x' => ['surrealdb-3.3.4', true];
        yield '3.x nightly' => ['surrealdb-3.4.0-nightly+20261014.4f7fa16', true];
        yield '4.0.0' => ['surrealdb-4.0.0', true];
        yield '4.0 pre-release' => ['surrealdb-4.0.0-beta.1', true];
        yield 'latest 4.x' => ['4.9.12', true];
        yield '5.0.0' => ['surrealdb-5.0.0', false];
        yield 'below minimum' => ['0.9.0', false];
    }

    #[DataProvider('versions')]
    public function testSupportedRangeAcceptsEvery4xRelease(string $version, bool $supported): void
    {
        $this->assertSame($supported, Version::isSupported($version));
    }
}
