<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Options\AttributeListOptions;
use SurrealDB\AgentMemory\Options\EntityListOptions;
use SurrealDB\AgentMemory\Options\KeywordListOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\Tests\Fakes\SequenceHttpConnection;

final class PaginationTest extends TestCase
{
    private const string BASE = 'https://memory.example.com/api/v1/acme_prod';

    private function client(SequenceHttpConnection $connection): AgentMemory
    {
        return new AgentMemory(
            new AgentMemoryOptions('https://memory.example.com', 'acme_prod', 'secret'),
            $connection,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function page(string $key, array $rows, ?string $nextCursor = null, ?int $totalSize = null): Response
    {
        $page = ['hasMore' => $nextCursor !== null] + ($nextCursor !== null ? ['nextCursor' => $nextCursor] : [])
            + ($totalSize !== null ? ['totalSize' => $totalSize] : []);

        return new Response(200, [], json_encode([$key => $rows, 'page' => $page], JSON_THROW_ON_ERROR));
    }

    public function testFromEnvelopeReadsRowsAndThePageBlock(): void
    {
        $page = Page::fromEnvelope(
            ['scopes' => [['path' => '/'], 'not-a-row'], 'page' => ['hasMore' => true, 'nextCursor' => 'abc', 'totalSize' => 9]],
            'scopes',
        );

        $this->assertSame([['path' => '/']], $page->items);
        $this->assertTrue($page->hasMore);
        $this->assertSame('abc', $page->nextCursor);
        $this->assertSame(9, $page->totalSize);
        $this->assertCount(1, $page);
        $this->assertSame([['path' => '/']], iterator_to_array($page));
    }

    public function testAMissingPageBlockIsTheLastPage(): void
    {
        $page = Page::fromEnvelope(['history' => [['value' => 'CTO']]], 'history');

        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
        $this->assertNull($page->totalSize);
        $this->assertNull($page->next());
    }

    public function testNextReissuesTheRequestWithTheCursorAndTheSameFilters(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('attributes', [['id' => 'a1']], 'cur/1'),
            self::page('attributes', [['id' => 'a2']]),
        ]);

        $first = $this->client($connection)->facts->attributes(new AttributeListOptions(entity: 'person/alice', limit: 1));
        $second = $first->next();

        $this->assertNotNull($second);
        $this->assertSame([['id' => 'a2']], $second->items);
        $this->assertNull($second->next());
        $this->assertSame([
            self::BASE . '/attributes?entity=person%2Falice&limit=1',
            self::BASE . '/attributes?cursor=cur%2F1&entity=person%2Falice&limit=1',
        ], $connection->urls());
    }

    public function testAutoPaginateYieldsEveryRowLazily(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('entities', [['name' => 'a'], ['name' => 'b']], 'c1', totalSize: 5),
            self::page('entities', [['name' => 'c'], ['name' => 'd']], 'c2'),
            self::page('entities', [['name' => 'e']]),
        ]);

        $first = $this->client($connection)->entities->list(new EntityListOptions(type: 'person', limit: 2, count: true));
        $this->assertSame(5, $first->totalSize);

        $names = [];

        foreach ($first->autoPaginate() as $entity) {
            $names[] = $entity['name'];

            if ($entity['name'] === 'b') {
                // Lazy: the second page is not fetched before the first is exhausted.
                $this->assertCount(1, $connection->requests);
            }
        }

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $names);
        $this->assertCount(3, $connection->requests);
        $this->assertStringContainsString('cursor=c2', $connection->requests[2]['url']);
        $this->assertStringContainsString('type=person', $connection->requests[2]['url']);
    }

    public function testAutoPaginateKeysAreSequentialAcrossPages(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('keywords', [['text' => 'a']], 'n'),
            self::page('keywords', [['text' => 'b']]),
        ]);

        $rows = iterator_to_array(
            $this->client($connection)->documents->keywords->list(new KeywordListOptions(sort: 'text'))->autoPaginate(),
        );

        $this->assertSame([['text' => 'a'], ['text' => 'b']], $rows);
    }

    public function testAutoPaginateRejectsACursorThatDoesNotAdvance(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('traces', [['id' => 't1']], 'same'),
            self::page('traces', [['id' => 't1']], 'same'),
        ]);

        $this->expectException(AgentMemoryException::class);
        $this->expectExceptionMessage('Pagination cursor did not advance');

        iterator_to_array($this->client($connection)->traces->list()->autoPaginate());
    }

    public function testAutoPaginateRejectsACursorCycle(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('traces', [['id' => 't1']], 'a'),
            self::page('traces', [['id' => 't2']], 'b'),
            self::page('traces', [['id' => 't3']], 'a'),
        ]);

        try {
            iterator_to_array($this->client($connection)->traces->list()->autoPaginate(), false);
            $this->fail('Expected the a -> b -> a cycle to be rejected');
        } catch (AgentMemoryException $e) {
            $this->assertSame('cursor a was already followed', $e->detail);
            $this->assertCount(3, $connection->requests);
        }
    }

    public function testFollowUpPagesDoNotRepeatTheCount(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('keys', [['name' => 'a']], 'n1', totalSize: 2),
            self::page('keys', [['name' => 'b']]),
        ]);

        $first = $this->client($connection)->keys->list(new PageOptions(limit: 1, count: true));
        $second = $first->next();

        $this->assertSame(2, $first->totalSize);
        $this->assertNotNull($second);
        $this->assertNull($second->totalSize);
        $this->assertSame([
            self::BASE . '/keys?limit=1&count=true',
            self::BASE . '/keys?cursor=n1&limit=1',
        ], $connection->urls());
    }

    public function testAPageClaimingMoreRowsWithoutACursorIsAnError(): void
    {
        $connection = new SequenceHttpConnection([
            new Response(200, [], '{"principals":[{"id":"admin"}],"page":{"hasMore":true}}'),
        ]);

        $page = $this->client($connection)->principals->list();

        $this->expectException(AgentMemoryException::class);
        $this->expectExceptionMessage('the server reported more rows but sent no cursor to fetch them');

        $page->next();
    }

    public function testAResponseWithoutItsRowsMemberIsAnError(): void
    {
        $connection = new SequenceHttpConnection([
            new Response(200, [], '{"items":[{"id":"x"}],"page":{"hasMore":false}}'),
        ]);

        $this->expectException(AgentMemoryException::class);
        $this->expectExceptionMessage('the response has no `entities` member');

        $this->client($connection)->entities->list();
    }

    public function testEveryCollectionReadsItsOwnEnvelopeKey(): void
    {
        $collections = [
            'audit' => ['rows', fn (AgentMemory $m): Page => $m->audit(), '/audit'],
            'actions' => ['actions', fn (AgentMemory $m): Page => $m->facts->actions(), '/actions'],
            'attributes' => ['attributes', fn (AgentMemory $m): Page => $m->facts->attributes(), '/attributes'],
            'relations' => ['relations', fn (AgentMemory $m): Page => $m->facts->relations(), '/relations'],
            'documents' => ['documents', fn (AgentMemory $m): Page => $m->documents->list(), '/documents'],
            'chunks' => ['chunks', fn (AgentMemory $m): Page => $m->documents->chunks('d1'), '/documents/d1/chunks'],
            'keywords' => ['keywords', fn (AgentMemory $m): Page => $m->documents->keywords->list(), '/documents/keywords'],
            'entities' => ['entities', fn (AgentMemory $m): Page => $m->entities->list(), '/entities'],
            'history' => ['history', fn (AgentMemory $m): Page => $m->entities->historyAll('person', 'alice'), '/entities/person/alice/history'],
            'neighbours' => ['neighbours', fn (AgentMemory $m): Page => $m->entities->neighbourhood('person', 'alice'), '/entities/person/alice/neighbourhood'],
            'keys' => ['keys', fn (AgentMemory $m): Page => $m->keys->list(), '/keys'],
            'principals' => ['principals', fn (AgentMemory $m): Page => $m->principals->list(), '/principals'],
            'scopes' => ['scopes', fn (AgentMemory $m): Page => $m->scopes->list(), '/scopes'],
            'turns' => ['turns', fn (AgentMemory $m): Page => $m->sessions->attach('s1')->turns(), '/sessions/s1/turns'],
            'traces' => ['traces', fn (AgentMemory $m): Page => $m->traces->list(), '/traces'],
            'unknowns' => ['unknowns', fn (AgentMemory $m): Page => $m->uncertainty->list(), '/uncertainty'],
        ];

        foreach ($collections as $name => [$key, $call, $path]) {
            $connection = new SequenceHttpConnection([self::page($key, [['row' => $name]])]);

            $page = $call($this->client($connection));

            $this->assertSame([['row' => $name]], $page->items, $name);
            $this->assertSame(self::BASE . $path, $connection->requests[0]['url'], $name);
            $this->assertSame('GET', $connection->requests[0]['method'], $name);
        }
    }
}
