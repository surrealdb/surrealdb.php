<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Query\CreateQuery;
use SurrealDB\SDK\Query\DeleteQuery;
use SurrealDB\SDK\Query\SelectQuery;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

final class TargetEscapingTest extends TestCase
{
    public function testStringTargetIsEscapedAsATableName(): void
    {
        $query = new SelectQuery(null, 'person; REMOVE DATABASE app')->compile();

        $this->assertSame('SELECT * FROM `person; REMOVE DATABASE app`', $query->query);
    }

    public function testPlainStringTargetStaysABareTableName(): void
    {
        $this->assertSame('DELETE person RETURN BEFORE', new DeleteQuery(null, 'person')->compile()->query);
    }

    public function testStringContainingAColonIsATableNotARecord(): void
    {
        $this->assertSame('CREATE `person:tobie`', new CreateQuery(null, 'person:tobie')->compile()->query);
    }

    public function testBackslashCannotEscapeTheClosingBacktick(): void
    {
        $this->assertSame('`a\\\\`', Table::escapeIdent('a\\'));
        $this->assertSame('`a\\`; DELETE b`', Table::escapeIdent('a`; DELETE b'));
        $this->assertSame('`a\\\\\\`; DELETE b`', Table::escapeIdent('a\\`; DELETE b'));
    }

    public function testRecordIdPartsUseTheSameEscaping(): void
    {
        $this->assertSame('person:`x\\\\\\` y`', new RecordId('person', 'x\\` y')->escape());
        $this->assertSame('`we\\`ird`:`1`', new RecordId('we`ird', '1')->escape());
    }

    public function testStringRecordIdTargetsASingleRecord(): void
    {
        $query = new SelectQuery(null, new StringRecordId('person:tobie'))->compile();

        $this->assertSame('SELECT * FROM ONLY r"person:tobie"', $query->query);
    }

    public function testRecordIdRangeTargetsManyRecords(): void
    {
        $range = new RecordIdRange('person', new BoundIncluded(1), new BoundIncluded(10));

        $this->assertSame('SELECT * FROM person:1..=10', new SelectQuery(null, $range)->compile()->query);
    }

    public function testRecordIdRangeIsNotACreateTarget(): void
    {
        // `CREATE person:1..=10` is not valid SurrealQL, so ranges are not CREATE targets.
        $targets = [
            new ReflectionMethod(Surreal::class, 'create')->getParameters()[0],
            new ReflectionMethod(CreateQuery::class, '__construct')->getParameters()[1],
        ];

        foreach ($targets as $target) {
            $type = $target->getType();
            $this->assertInstanceOf(ReflectionUnionType::class, $type);
            $accepted = array_map(static fn (ReflectionNamedType $t): string => $t->getName(), $type->getTypes());
            $this->assertNotContains(RecordIdRange::class, $accepted);
        }
    }
}
