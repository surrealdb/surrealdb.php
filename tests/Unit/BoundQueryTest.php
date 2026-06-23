<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Exceptions\ExpressionException;
use SurrealDB\SDK\Query\BoundQuery;

final class BoundQueryTest extends TestCase
{
    public function testBindGeneratesSequentialPlaceholders(): void
    {
        $query = new BoundQuery('SELECT * FROM person WHERE name = ');
        $placeholder = $query->bind('Tobie');

        $this->assertSame('$bind_0', $placeholder);
        $this->assertSame(['bind_0' => 'Tobie'], $query->bindings);
    }

    public function testAppendMergesTextAndBindings(): void
    {
        $query = new BoundQuery('SELECT *');
        $query->append(' FROM person');

        $this->assertSame('SELECT * FROM person', $query->query);
    }

    public function testAppendRejectsDuplicateBindingKeys(): void
    {
        $first = new BoundQuery();
        $first->bind('a');

        $second = new BoundQuery();
        $second->bind('b');

        $this->expectException(ExpressionException::class);
        $first->append($second);
    }
}
