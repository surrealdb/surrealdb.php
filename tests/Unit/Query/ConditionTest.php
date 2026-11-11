<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SurrealDB\Query\BoundQuery;
use SurrealDB\Query\SelectQuery;
use SurrealDB\Query\UpdateQuery;
use SurrealDB\Query\UpsertQuery;
use SurrealDB\Surreal;
use SurrealDB\Types\Table;
use SurrealDB\Types\Value;

final class ConditionTest extends TestCase
{
    public function testALineCommentEndsBeforeTheClausesThatFollow(): void
    {
        $query = new SelectQuery(null, 'item')->where('n >= 2 -- note')->start(1)->limit(1)->compile();
        [$start, $limit] = array_keys($query->bindings);

        $this->assertSame("SELECT * FROM item WHERE (n >= 2 -- note\n) START \${$start} LIMIT \${$limit}", $query->query);
    }

    public function testASemicolonStaysInsideTheCondition(): void
    {
        $query = new SelectQuery(null, 'item')->where('n >= 2; DELETE item')->compile();

        $this->assertSame("SELECT * FROM item WHERE (n >= 2; DELETE item\n)", $query->query);
    }

    public function testEveryConditionMustHold(): void
    {
        $query = new SelectQuery(null, 'item')->where('tenant = "a"')->where('n >= 2')->compile();

        $this->assertSame("SELECT * FROM item WHERE (tenant = \"a\"\n) AND (n >= 2\n)", $query->query);
    }

    public function testBoundConditionsKeepTheirBindings(): void
    {
        $tenant = new BoundQuery('tenant = ');
        $a = $tenant->bind('a');
        $tenant->append($a);

        $size = new BoundQuery('n >= ');
        $two = $size->bind(2);
        $size->append($two);

        $query = new SelectQuery(null, 'item')->where($tenant)->where('n < 9')->where($size)->compile();

        $this->assertSame("SELECT * FROM item WHERE (tenant = {$a}\n) AND (n < 9\n) AND (n >= {$two}\n)", $query->query);
        $this->assertSame([substr($a, 1) => 'a', substr($two, 1) => 2], $query->bindings);
    }

    public function testUpdateUpsertAndLiveSelectWrapAndAndTheirConditions(): void
    {
        $where = "WHERE (tenant = \"a\"\n) AND (n >= 2 -- note\n)";

        $update = new UpdateQuery(null, new Table('item'))->where('tenant = "a"')->where('n >= 2 -- note')->compile();
        $upsert = new UpsertQuery(null, new Table('item'))->where('tenant = "a"')->where('n >= 2 -- note')->compile();
        $live = new Surreal()->live('item')->where('tenant = "a"')->where('n >= 2 -- note')->fetch('owner')->compile();

        $this->assertSame("UPDATE item {$where}", $update->query);
        $this->assertSame("UPSERT item {$where}", $upsert->query);
        $this->assertSame("LIVE SELECT * FROM item {$where} FETCH type::fields(" . Value::toSurql(['owner']) . ')', $live->query);
    }

    public function testWithoutAConditionThereIsNoWhereClause(): void
    {
        $this->assertSame('SELECT * FROM item', new SelectQuery(null, 'item')->compile()->query);
    }
}
