<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Exceptions\ExpressionException;
use SurrealDB\SDK\Query\RunQuery;

final class RunQueryTest extends TestCase
{
    public function testArgumentsAreCommaSeparatedWithoutATrailingComma(): void
    {
        $query = new RunQuery(null, 'fn::greet', null, ['Tobie', 3])->compile();
        [$first, $second] = array_keys($query->bindings);

        $this->assertSame("fn::greet(\${$first}, \${$second})", $query->query);
        $this->assertSame([$first => 'Tobie', $second => 3], $query->bindings);
    }

    public function testCallWithoutArgumentsHasEmptyParentheses(): void
    {
        $query = new RunQuery(null, 'time::now')->compile();

        $this->assertSame('time::now()', $query->query);
        $this->assertSame([], $query->bindings);
    }

    public function testVersionIsEmittedBetweenNameAndArguments(): void
    {
        $query = new RunQuery(null, 'ml::recommend', '1.0.0', [1])->compile();
        [$arg] = array_keys($query->bindings);

        $this->assertSame("ml::recommend<1.0.0>(\${$arg})", $query->query);
    }

    public function testInvalidNameIsRejected(): void
    {
        $this->expectException(ExpressionException::class);

        new RunQuery(null, 'fn::x(); DELETE person; fn::y')->compile();
    }
}
