<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Query\BoundQuery;
use SurrealDB\Query\CreateQuery;
use SurrealDB\Query\InsertQuery;
use SurrealDB\Query\QueryBuilder;
use SurrealDB\Query\RelateQuery;
use SurrealDB\Query\UpdateQuery;
use SurrealDB\Query\UpsertQuery;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Table;

/**
 * An empty PHP array is both an empty list and an empty map. As a record's
 * data it means an empty record, so it is sent as an empty object.
 */
final class DataMutationTest extends TestCase
{
    /**
     * @return iterable<string, array{QueryBuilder<mixed>, string}>
     */
    public static function emptyRecords(): iterable
    {
        $one = new RecordId('person', 1);

        yield 'create content' => [new CreateQuery(null, $one)->content([]), 'CREATE ONLY person:1 CONTENT '];
        yield 'update content' => [new UpdateQuery(null, $one)->content([]), 'UPDATE ONLY person:1 CONTENT '];
        yield 'update merge' => [new UpdateQuery(null, $one)->merge([]), 'UPDATE ONLY person:1 MERGE '];
        yield 'update replace' => [new UpdateQuery(null, $one)->replace([]), 'UPDATE ONLY person:1 REPLACE '];
        yield 'upsert content' => [new UpsertQuery(null, $one)->content([]), 'UPSERT ONLY person:1 CONTENT '];
        yield 'upsert merge' => [new UpsertQuery(null, $one)->merge([]), 'UPSERT ONLY person:1 MERGE '];
        yield 'upsert replace' => [new UpsertQuery(null, $one)->replace([]), 'UPSERT ONLY person:1 REPLACE '];
        yield 'relate content' => [
            new RelateQuery(null, $one, new Table('likes'), new RecordId('person', 2))->content([]),
            'RELATE ONLY person:1->likes->person:2 CONTENT ',
        ];
    }

    /**
     * @param QueryBuilder<mixed> $builder
     */
    #[DataProvider('emptyRecords')]
    public function testAnEmptyArrayIsSentAsAnEmptyObject(QueryBuilder $builder, string $sql): void
    {
        $query = $builder->compile();
        $data = $this->only($query);

        $this->assertSame("{$sql}\${$data}", $query->query);
        $this->assertEquals(new \stdClass(), $query->bindings[$data]);
    }

    public function testTheBuilderKeepsTheArrayItWasGiven(): void
    {
        $this->assertSame([], new UpdateQuery(null, new Table('person'))->merge([])->data);
    }

    public function testAnEmptyPatchStaysAList(): void
    {
        $update = new UpdateQuery(null, new RecordId('person', 1))->patch([])->compile();
        $create = new CreateQuery(null, new RecordId('person', 1))->patch([])->compile();

        $this->assertSame([], $update->bindings[$this->only($update)]);
        $this->assertSame([], $create->bindings[$this->only($create)]);
    }

    public function testInsertIsLeftAlone(): void
    {
        $insert = new InsertQuery(null, new Table('person'), [])->compile();

        $this->assertSame([], $insert->bindings[$this->only($insert)]);
    }

    public function testDataIsSentAsGiven(): void
    {
        $object = new \stdClass();
        $merge = new UpdateQuery(null, new RecordId('person', 1))->merge(['a' => 1])->compile();
        $content = new CreateQuery(null, new RecordId('person', 1))->content($object)->compile();

        $this->assertSame(['a' => 1], $merge->bindings[$this->only($merge)]);
        $this->assertSame($object, $content->bindings[$this->only($content)]);
    }

    /**
     * The name of the query's one binding.
     */
    private function only(BoundQuery $query): string
    {
        $this->assertCount(1, $query->bindings);

        return array_key_first($query->bindings);
    }
}
