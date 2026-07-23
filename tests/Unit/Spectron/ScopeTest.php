<?php

namespace SurrealDB\Tests\Unit\Spectron;

use PHPUnit\Framework\TestCase;
use SurrealDB\Spectron\Scope;

final class ScopeTest extends TestCase
{
    public function testBareStringBecomesSinglePathClause(): void
    {
        $this->assertSame([['team/eng']], Scope::normalise('team/eng'));
    }

    public function testFlatArrayIsAnOrOfSinglePathClauses(): void
    {
        $this->assertSame([['team/eng'], ['org/acme']], Scope::normalise(['team/eng', 'org/acme']));
    }

    public function testNestedArrayIsAnAndClause(): void
    {
        $this->assertSame([['team/eng', 'org/acme']], Scope::normalise([['team/eng', 'org/acme']]));
    }

    public function testMixedShapesCombine(): void
    {
        $this->assertSame(
            [['team/a'], ['team/b', 'clearance/secret']],
            Scope::normalise(['team/a', ['team/b', 'clearance/secret']]),
        );
    }

    public function testEmptyPathsAreDroppedAndPathsDeduplicated(): void
    {
        $this->assertSame([['a', 'b']], Scope::normalise([['a', '', 'b', 'a']]));
    }

    public function testEmptyClausesAreDropped(): void
    {
        $this->assertSame([['a']], Scope::normalise([['', ''], 'a']));
    }

    public function testNullWhenNothingRemains(): void
    {
        $this->assertNull(Scope::normalise(null));
        $this->assertNull(Scope::normalise(''));
        $this->assertNull(Scope::normalise([]));
        $this->assertNull(Scope::normalise([['']]));
    }
}
