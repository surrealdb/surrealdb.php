<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Exceptions\ExpressionException;
use SurrealDB\Query\RunQuery;
use SurrealDB\Types\Table;

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function functionNames(): iterable
    {
        foreach ([
            'count', 'not', 'rand', 'sleep', 'string::len', 'string::is::email', 'fn::greet', 'fn::a::b',
            'mod::x::y', 'ml::model', 'math::max', 'rand::uuid', 'fn::_private', 'fn::v2',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('functionNames')]
    public function testAFunctionNameIsCalled(string $name): void
    {
        $this->assertSame("{$name}()", new RunQuery(null, $name)->compile()->query);
    }

    /**
     * Statement keywords and other words that are not functions. A bare word
     * is read as whatever it names, so `DELETE($arg)` deletes `$arg`.
     *
     * @return iterable<string, array{string}>
     */
    public static function notFunctionNames(): iterable
    {
        foreach ([
            'DELETE', 'delete', 'Delete', 'UPDATE', 'update', 'UPSERT', 'CREATE', 'INSERT', 'RELATE',
            'SELECT', 'LET', 'DEFINE', 'REMOVE', 'KILL', 'LIVE', 'INFO', 'RETURN', 'IF', 'fn', 'len',
            'COUNT', 'Count', '', 'fn::', '::', '::fn', 'fn:::a', 'fn::::a', 'a:b', 'fn::a::',
            'fn::greet(); DELETE user; fn::x', 'fn::a b', 'fn::a-b', 'fn::a.b', "fn::greet\n", "count\n",
        ] as $name) {
            yield json_encode($name, \JSON_THROW_ON_ERROR) => [$name];
        }
    }

    #[DataProvider('notFunctionNames')]
    public function testAnythingElseIsRefusedAsAName(string $name): void
    {
        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage('Invalid function name');

        new RunQuery(null, $name, null, [new Table('user')])->compile();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notVersions(): iterable
    {
        foreach (['', '.', '1', '1.0', '1..0', '1.0.0.0', 'a.b.c', '1.0.0>(); DELETE user; //', "1.0.0\n", '١.٠.٠'] as $version) {
            yield json_encode($version, \JSON_THROW_ON_ERROR) => [$version];
        }
    }

    #[DataProvider('notVersions')]
    public function testAVersionMustBeMajorMinorPatch(string $version): void
    {
        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage('Invalid function version');

        new RunQuery(null, 'ml::model', $version, [1])->compile();
    }
}
