<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Contracts\StreamingQueryExecutor;
use SurrealDB\SDK\Exceptions\NotFoundException;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Protocol\QueryChunk;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Query\Frame\DoneFrame;
use SurrealDB\SDK\Query\Frame\ErrorFrame;
use SurrealDB\SDK\Query\Frame\ValueFrame;
use SurrealDB\SDK\Query\Query;
use SurrealDB\SDK\Types\RecordId;

final class StreamTest extends TestCase
{
    public function testListResultsStreamOneFramePerElementThenDone(): void
    {
        $query = new Query($this->executor([
            new QueryChunk(0, [['id' => 1], ['id' => 2]], time: '1ms', type: 'other'),
            new QueryChunk(1, 'scalar', time: '2ms'),
        ]), new BoundQuery('SELECT * FROM person; RETURN "scalar"'));

        $frames = iterator_to_array($query->stream(), false);

        $this->assertEquals([
            new ValueFrame(0, ['id' => 1], false),
            new ValueFrame(0, ['id' => 2], false),
            new DoneFrame(0, '1ms', 'other'),
            new ValueFrame(1, 'scalar', true),
            new DoneFrame(1, '2ms'),
        ], $frames);
        $this->assertTrue($frames[0]->isValue());
        $this->assertTrue($frames[2]->isDone());
        $this->assertTrue($frames[3]->isOf(1));
    }

    public function testAFailingStatementYieldsAnErrorFrameAndTheStreamContinues(): void
    {
        $error = new NotFoundException('NotFound', 'The table does not exist');
        $query = new Query($this->executor([
            new QueryChunk(0, null, 'ERR', '1ms', $error),
            new QueryChunk(1, [], time: '1ms'),
        ]), new BoundQuery('SELECT * FROM missing; SELECT * FROM empty'));

        $frames = iterator_to_array($query->stream(), false);

        $this->assertEquals([new ErrorFrame(0, $error, '1ms'), new DoneFrame(1, '1ms')], $frames);
        $this->assertTrue($frames[0]->isError());
    }

    public function testResponsesReportEveryStatementWithoutThrowing(): void
    {
        $error = new NotFoundException('NotFound', 'The table does not exist');
        $query = new Query($this->executor([
            new QueryChunk(0, null, 'ERR', '1ms', $error),
            new QueryChunk(1, [1, 2], time: '2ms', type: 'other'),
        ]), new BoundQuery('...'));

        [$failed, $ok] = $query->responses();

        $this->assertFalse($failed->success);
        $this->assertSame($error, $failed->error);
        $this->assertTrue($ok->success);
        $this->assertSame([1, 2], $ok->result);
        $this->assertSame('other', $ok->type);
        $this->assertSame('2ms', $ok->time);

        $this->expectExceptionObject($error);
        $failed->resultOrThrow();
    }

    public function testJsonAppliesToStreamedValuesAndResponses(): void
    {
        $chunks = [new QueryChunk(0, [new RecordId('person', 'tobie')])];
        $query = (new Query($this->executor($chunks), new BoundQuery('...')))->json();

        $this->assertEquals(new ValueFrame(0, 'person:tobie', false), $query->stream()->current());
        $this->assertSame(['person:tobie'], $query->responses()[0]->result);
    }

    public function testStreamingNeedsAStreamingExecutor(): void
    {
        $query = new Query(new class implements QueryExecutor {
            public function query(BoundQuery $query): array
            {
                return [];
            }
        }, new BoundQuery('RETURN 1'));

        $this->expectException(SurrealException::class);
        $query->stream()->current();
    }

    /**
     * @param list<QueryChunk<mixed>> $chunks
     */
    private function executor(array $chunks): StreamingQueryExecutor
    {
        return new class($chunks) implements StreamingQueryExecutor {
            /**
             * @param list<QueryChunk<mixed>> $chunks
             */
            public function __construct(private readonly array $chunks) {}

            public function query(BoundQuery $query): array
            {
                return array_map(static fn (QueryChunk $chunk): mixed => $chunk->resultOrThrow(), $this->chunks);
            }

            public function stream(BoundQuery $query): iterable
            {
                yield from $this->chunks;
            }
        };
    }
}
