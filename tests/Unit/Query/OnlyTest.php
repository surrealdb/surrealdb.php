<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Contracts\StreamingQueryExecutor;
use SurrealDB\Enum\Output;
use SurrealDB\Exceptions\ExpressionException;
use SurrealDB\Protocol\QueryChunk;
use SurrealDB\Query\BoundQuery;
use SurrealDB\Query\CreateQuery;
use SurrealDB\Query\DeleteQuery;
use SurrealDB\Query\Frame\ValueFrame;
use SurrealDB\Query\QueryBuilder;
use SurrealDB\Query\RelateQuery;
use SurrealDB\Query\SelectQuery;
use SurrealDB\Query\UpdateQuery;
use SurrealDB\Query\UpsertQuery;
use SurrealDB\Surreal;
use SurrealDB\Types\BoundExcluded;
use SurrealDB\Types\BoundIncluded;
use SurrealDB\Types\None;
use SurrealDB\Types\Range;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\StringRecordId;
use SurrealDB\Types\Table;

/**
 * When a statement takes `ONLY`: for one record, but not for a range of
 * records, and not with `RETURN NONE`, which SurrealDB 2.x refuses with it.
 * A range written as text in a {@see StringRecordId} is refused.
 */
final class OnlyTest extends TestCase
{
    public function testARecordIdWhoseIdIsARangeIsARange(): void
    {
        $range = new RecordId('person', new Range(new BoundIncluded(1), new BoundExcluded(3)));

        $this->assertSame('SELECT * FROM person:1..3', new SelectQuery(null, $range)->compile()->query);
        $this->assertSame('UPDATE person:1..3', new UpdateQuery(null, $range)->compile()->query);
        $this->assertSame('UPSERT person:1..3', new UpsertQuery(null, $range)->compile()->query);
        $this->assertSame('DELETE person:1..3 RETURN BEFORE', new DeleteQuery(null, $range)->compile()->query);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringRanges(): iterable
    {
        foreach ([
            'person:1..3', 'person:1..=3', 'person:1>..3', 'person:..', 'person:1..', 'person:..=3',
            'person:[1]..[3]', 'person:{a: 1}..{a: 3}', 'person:⟨a⟩..⟨b⟩', 'person:`a`..`b`',
        ] as $id) {
            yield $id => [$id];
        }
    }

    /**
     * The servers read a range written as text in different ways, so every
     * builder refuses it.
     */
    #[DataProvider('stringRanges')]
    public function testAStringRecordIdWithATopLevelDotDotIsRefused(string $id): void
    {
        $what = new StringRecordId($id);
        $builders = [
            new SelectQuery(null, $what),
            new CreateQuery(null, $what),
            new UpdateQuery(null, $what),
            new UpsertQuery(null, $what),
            new DeleteQuery(null, $what),
            new UpdateQuery(null, $what)->output(Output::NONE),
            new Surreal()->live($what),
        ];

        foreach ($builders as $builder) {
            try {
                $builder->compile();
                $this->fail($builder::class . " should refuse {$id}.");
            } catch (ExpressionException $error) {
                $this->assertStringContainsString('servers read in different ways', $error->getMessage());
                $this->assertStringContainsString('RecordIdRange', $error->getMessage());
            }
        }
    }

    /**
     * A `..` inside quotes, backticks, `⟨⟩` or brackets is part of the id.
     *
     * @return iterable<string, array{string}>
     */
    public static function stringRecords(): iterable
    {
        foreach ([
            'person:tobie', 'person:1', 'person:⟨a..b⟩', 'person:⟨a\⟩..b⟩', 'person:`a..b`', 'person:`a\`..b`',
            'person:"a..b"', "person:'a..b'", 'person:[1..2]', 'person:{a: 1..2}', 'person:[")", 1..2]',
            'person:[[1], (1..2)]', 'person:u"0..0"',
        ] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('stringRecords')]
    public function testAStringRecordIdWithoutATopLevelDotDotIsOneRecord(string $id): void
    {
        $what = new StringRecordId($id);
        $escaped = $what->escape();

        $this->assertSame("SELECT * FROM ONLY {$escaped}", new SelectQuery(null, $what)->compile()->query);
        $this->assertSame("UPDATE ONLY {$escaped}", new UpdateQuery(null, $what)->compile()->query);
        $this->assertSame("DELETE ONLY {$escaped} RETURN BEFORE", new DeleteQuery(null, $what)->compile()->query);
    }

    public function testReturnNoneLeavesOutOnly(): void
    {
        $one = new RecordId('person', 1);

        $this->assertSame('CREATE person:1 RETURN NONE', new CreateQuery(null, $one)->output(Output::NONE)->compile()->query);
        $this->assertSame('UPDATE person:1 RETURN NONE', new UpdateQuery(null, $one)->output(Output::NONE)->compile()->query);
        $this->assertSame('UPSERT person:1 RETURN NONE', new UpsertQuery(null, $one)->output(Output::NONE)->compile()->query);
        $this->assertSame('DELETE person:1 RETURN NONE', new DeleteQuery(null, $one)->output(Output::NONE)->compile()->query);
        $this->assertSame(
            'RELATE person:1->likes->person:2 RETURN NONE',
            new RelateQuery(null, $one, new Table('likes'), new RecordId('person', 2))->output(Output::NONE)->compile()->query,
        );
    }

    public function testOtherOutputsKeepOnly(): void
    {
        $one = new RecordId('person', 1);

        foreach ([Output::DIFF, Output::BEFORE, Output::AFTER, Output::NULL_] as $output) {
            $sql = $output->toSql();

            $this->assertSame("CREATE ONLY person:1 RETURN {$sql}", new CreateQuery(null, $one)->output($output)->compile()->query);
            $this->assertSame("UPDATE ONLY person:1 RETURN {$sql}", new UpdateQuery(null, $one)->output($output)->compile()->query);
            $this->assertSame(
                "RELATE ONLY person:1->likes->person:2 RETURN {$sql}",
                new RelateQuery(null, $one, new Table('likes'), new RecordId('person', 2))->output($output)->compile()->query,
            );
        }
    }

    /**
     * @return iterable<string, array{\Closure(StreamingQueryExecutor): QueryBuilder<mixed>}>
     */
    public static function noneBuilders(): iterable
    {
        $one = new RecordId('person', 1);

        yield 'create' => [static fn (StreamingQueryExecutor $db): QueryBuilder => new CreateQuery($db, $one)->output(Output::NONE)];
        yield 'update' => [static fn (StreamingQueryExecutor $db): QueryBuilder => new UpdateQuery($db, $one)->merge(['a' => 1])->output(Output::NONE)];
        yield 'upsert' => [static fn (StreamingQueryExecutor $db): QueryBuilder => new UpsertQuery($db, $one)->output(Output::NONE)];
        yield 'delete' => [static fn (StreamingQueryExecutor $db): QueryBuilder => new DeleteQuery($db, $one)->output(Output::NONE)];
        yield 'relate' => [static fn (StreamingQueryExecutor $db): QueryBuilder => new RelateQuery($db, $one, new Table('likes'), new RecordId('person', 2))->output(Output::NONE)];
    }

    /**
     * Sent without ONLY, the statement returns an empty list; the builder
     * gives back the NONE that SurrealDB 3.x and later return with ONLY.
     *
     * @param \Closure(StreamingQueryExecutor): QueryBuilder<mixed> $builder
     */
    #[DataProvider('noneBuilders')]
    public function testReturnNoneOnOneRecordGivesNone(\Closure $builder): void
    {
        $db = $this->executor([]);

        $values = [];

        foreach ($builder($db)->stream() as $frame) {
            if ($frame instanceof ValueFrame) {
                $values[] = $frame->value;
            }
        }

        $this->assertSame(None::instance(), $builder($db)->execute());
        $this->assertSame(None::instance(), $builder($db)->responses()[0]->result);
        $this->assertSame([None::instance()], $values);
        $this->assertNull($builder($db)->json()->execute());
        $this->assertNull($builder($db)->json()->responses()[0]->result);
    }

    public function testReturnNoneOnATableStillGivesTheList(): void
    {
        $query = new UpdateQuery($this->executor([]), new Table('person'))->merge(['a' => 1])->output(Output::NONE);

        $this->assertSame([], $query->execute());
    }

    /**
     * An executor whose one statement returns `$result`.
     */
    private function executor(mixed $result): StreamingQueryExecutor
    {
        return new class($result) implements StreamingQueryExecutor {
            public function __construct(private readonly mixed $result) {}

            public function query(BoundQuery $query): array
            {
                return [$this->result];
            }

            public function stream(BoundQuery $query): iterable
            {
                yield new QueryChunk(0, $this->result);
            }
        };
    }
}
