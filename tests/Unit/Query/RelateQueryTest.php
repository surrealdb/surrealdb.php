<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Exceptions\ExpressionException;
use SurrealDB\Query\RelateQuery;
use SurrealDB\Types\BoundExcluded;
use SurrealDB\Types\BoundIncluded;
use SurrealDB\Types\Range;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\RecordIdRange;
use SurrealDB\Types\StringRecordId;
use SurrealDB\Types\Table;

final class RelateQueryTest extends TestCase
{
    /**
     * Ends that name a range of records, alone or in a list.
     *
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function rangeEnds(): iterable
    {
        $one = new RecordId('person', 1);
        $range = new RecordId('person', new Range(new BoundIncluded(1), new BoundExcluded(3)));

        yield 'range to' => [$one, $range];
        yield 'range from' => [$range, $one];
        yield 'range in a to list' => [$one, [new RecordId('person', 2), $range]];
        yield 'range in a from list' => [[$range], $one];
        yield 'record id range in a list' => [$one, [new RecordIdRange('person', new BoundIncluded(1), null)]];
    }

    #[DataProvider('rangeEnds')]
    public function testARangeIsRefusedAtEitherEnd(mixed $from, mixed $to): void
    {
        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage('select the records');

        new RelateQuery(null, $from, new Table('likes'), $to)->compile();
    }

    /**
     * A range written as text is refused as at any other target, since the
     * servers read it in different ways.
     *
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function textRangeEnds(): iterable
    {
        $one = new RecordId('person', 1);

        yield 'in a to list' => [$one, [new StringRecordId('person:1..3')]];
        yield 'in a from list' => [[new StringRecordId('person:[1]..[3]')], $one];
    }

    #[DataProvider('textRangeEnds')]
    public function testARangeWrittenAsTextIsRefusedAtEitherEnd(mixed $from, mixed $to): void
    {
        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage('RecordIdRange');

        new RelateQuery(null, $from, new Table('likes'), $to)->compile();
    }

    public function testRecordsAreRelated(): void
    {
        $query = new RelateQuery(
            null,
            new RecordId('person', 1),
            new Table('likes'),
            [new RecordId('person', 2), new RecordId('person', 'a..b')],
        )->compile();

        $this->assertSame('RELATE person:1->likes->[person:2, person:`a..b`]', $query->query);
    }

    public function testAStringRecordIdWithAQuotedDotDotIsOneRecord(): void
    {
        $to = new StringRecordId('person:⟨a..b⟩');
        $query = new RelateQuery(null, new RecordId('person', 1), new Table('likes'), [$to])->compile();

        $this->assertSame('RELATE person:1->likes->[' . $to->escape() . ']', $query->query);
    }
}
