<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Exceptions\ExpressionException;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Query\SelectQuery;
use SurrealDB\SDK\Types\Table;

final class BoundQueryTest extends TestCase
{
    public function testBindGeneratesAPlaceholderForTheValue(): void
    {
        $query = new BoundQuery('SELECT * FROM person WHERE name = ');
        $placeholder = $query->bind('Tobie');

        $this->assertMatchesRegularExpression('/^\$bind__\d+$/', $placeholder);
        $this->assertSame([substr($placeholder, 1) => 'Tobie'], $query->bindings);
    }

    public function testAppendMergesTextAndBindings(): void
    {
        $query = new BoundQuery('SELECT *');
        $query->append(' FROM person');

        $this->assertSame('SELECT * FROM person', $query->query);
    }

    public function testAppendingSeparatelyBoundFragmentsKeepsEveryBinding(): void
    {
        $first = new BoundQuery('RETURN ');
        $a = $first->bind('a');
        $first->append($a);

        $second = new BoundQuery(' + ');
        $b = $second->bind('b');
        $second->append($b);

        $first->append($second);

        $this->assertNotSame($a, $b);
        $this->assertSame("RETURN {$a} + {$b}", $first->query);
        $this->assertSame([substr($a, 1) => 'a', substr($b, 1) => 'b'], $first->bindings);
    }

    public function testBuilderAcceptsABoundConditionAlongsideItsOwnBindings(): void
    {
        $cond = new BoundQuery('age > ');
        $cond->append($cond->bind(18));

        $compiled = new SelectQuery(null, new Table('person'))
            ->fields('name')
            ->where($cond)
            ->limit(5)
            ->compile();

        $this->assertCount(3, $compiled->bindings);
        $this->assertContains(18, $compiled->bindings);
        $this->assertContains(['name'], $compiled->bindings);
        $this->assertContains(5, $compiled->bindings);
    }

    public function testAppendRejectsDuplicateExplicitBindingKeys(): void
    {
        $first = new BoundQuery('RETURN $a', ['a' => 1]);

        $this->expectException(ExpressionException::class);
        $first->append(' + $a', ['a' => 2]);
    }
}
