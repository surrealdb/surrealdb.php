<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory\UserAgent;

final class UserAgentTest extends TestCase
{
    public function testFormatsComposerVersionsLikeTheJsClient(): void
    {
        $cases = [
            'a release' => ['2.0.0-alpha.3', 'surrealdb-memory-php/2.0.0-alpha.3'],
            'a tag with its v' => ['v2.0.0-alpha.3', 'surrealdb-memory-php/2.0.0-alpha.3'],
            'a branch' => ['dev-feat/spectron', 'surrealdb-memory-php/dev-feat-spectron'],
            'a word starting with v' => ['very', 'surrealdb-memory-php/very'],
            'no version' => [null, 'surrealdb-memory-php/unknown'],
            'an empty version' => ['', 'surrealdb-memory-php/unknown'],
            'a blank version' => [' ', 'surrealdb-memory-php/unknown'],
        ];

        foreach ($cases as $name => [$version, $header]) {
            $this->assertSame($header, UserAgent::format($version), $name);
        }
    }

    public function testReadsTheInstalledPackageVersion(): void
    {
        $this->assertSame(
            UserAgent::format(InstalledVersions::getPrettyVersion('surrealdb/surrealdb.php')),
            UserAgent::value(),
        );
    }
}
