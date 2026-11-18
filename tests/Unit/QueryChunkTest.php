<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SurrealDB\Exceptions\InternalException;
use SurrealDB\Exceptions\QueryException;
use SurrealDB\Exceptions\ThrownException;
use SurrealDB\Protocol\QueryChunk;

final class QueryChunkTest extends TestCase
{
    public function testAStatementErrorKeepsTheServerKind(): void
    {
        $chunk = QueryChunk::fromResult(0, ['status' => 'ERR', 'kind' => 'Thrown', 'result' => 'An error occurred: boom']);

        $this->assertInstanceOf(ThrownException::class, $chunk->error);
        $this->assertSame('Thrown', $chunk->error->kind);
    }

    public function testAStatementErrorWithoutAKindIsInternal(): void
    {
        // As SurrealDB 2.x reports every statement error. Not `Query`, which
        // from 3.x on means a timeout or cancellation.
        $chunk = QueryChunk::fromResult(0, ['status' => 'ERR', 'result' => 'Database record `a:1` already exists']);

        $this->assertInstanceOf(InternalException::class, $chunk->error);
        $this->assertSame('Internal', $chunk->error->kind);
        $this->assertSame('Database record `a:1` already exists', $chunk->error->getMessage());
    }

    public function testATimedOutStatementIsAQueryError(): void
    {
        $chunk = QueryChunk::fromResult(0, ['status' => 'ERR', 'kind' => 'Query', 'result' => 'The query was not executed due to a timeout']);

        $this->assertInstanceOf(QueryException::class, $chunk->error);
    }
}
