<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\ActionListOptions;
use SurrealDB\AgentMemory\Options\AttributeListOptions;
use SurrealDB\AgentMemory\Options\AuditOptions;
use SurrealDB\AgentMemory\Options\DocumentListOptions;
use SurrealDB\AgentMemory\Options\EntityListOptions;
use SurrealDB\AgentMemory\Options\KeywordListOptions;
use SurrealDB\AgentMemory\Options\NeighbourhoodOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Options\RelationListOptions;
use SurrealDB\AgentMemory\Options\ScopeListOptions;
use SurrealDB\AgentMemory\Options\UncertaintyListOptions;
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
            'history' => ['history', fn (AgentMemory $m): Page => $m->entities->changes('person', 'alice'), '/entities/person/alice/history'],
            'neighbours' => ['neighbours', fn (AgentMemory $m): Page => $m->entities->neighbours('person', 'alice'), '/entities/person/alice/neighbourhood'],
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

    public function testEveryCollectorWalksItsCollectionToTheEnd(): void
    {
        $collectors = [
            'auditAll' => ['rows', fn (AgentMemory $m): array => $m->auditAll(), '/audit'],
            'allActions' => ['actions', fn (AgentMemory $m): array => $m->facts->allActions(), '/actions'],
            'allAttributes' => ['attributes', fn (AgentMemory $m): array => $m->facts->allAttributes(), '/attributes'],
            'allRelations' => ['relations', fn (AgentMemory $m): array => $m->facts->allRelations(), '/relations'],
            'documents' => ['documents', fn (AgentMemory $m): array => $m->documents->listAll(), '/documents'],
            'allChunks' => ['chunks', fn (AgentMemory $m): array => $m->documents->allChunks('d1'), '/documents/d1/chunks'],
            'keywords' => ['keywords', fn (AgentMemory $m): array => $m->documents->keywords->listAll(), '/documents/keywords'],
            'entities' => ['entities', fn (AgentMemory $m): array => $m->entities->listAll(), '/entities'],
            'allChanges' => ['history', fn (AgentMemory $m): array => $m->entities->allChanges('person', 'alice'), '/entities/person/alice/history'],
            'allNeighbours' => ['neighbours', fn (AgentMemory $m): array => $m->entities->allNeighbours('person', 'alice'), '/entities/person/alice/neighbourhood'],
            'keys' => ['keys', fn (AgentMemory $m): array => $m->keys->listAll(), '/keys'],
            'principals' => ['principals', fn (AgentMemory $m): array => $m->principals->listAll(), '/principals'],
            'scopes' => ['scopes', fn (AgentMemory $m): array => $m->scopes->listAll(), '/scopes'],
            'allTurns' => ['turns', fn (AgentMemory $m): array => $m->sessions->attach('s1')->allTurns(), '/sessions/s1/turns'],
            'traces' => ['traces', fn (AgentMemory $m): array => $m->traces->listAll(), '/traces'],
            'unknowns' => ['unknowns', fn (AgentMemory $m): array => $m->uncertainty->listAll(), '/uncertainty'],
        ];

        foreach ($collectors as $name => [$key, $call, $path]) {
            $connection = new SequenceHttpConnection([
                self::page($key, [['row' => 1]], 'n'),
                self::page($key, [['row' => 2]]),
            ]);

            $this->assertSame([['row' => 1], ['row' => 2]], $call($this->client($connection)), $name);
            $this->assertSame([self::BASE . $path, self::BASE . $path . '?cursor=n'], $connection->urls(), $name);
        }
    }

    public function testCollectorsStopAfterThePageThatReachesMax(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('attributes', [['id' => 'a1'], ['id' => 'a2']], 'n1'),
            self::page('attributes', [['id' => 'a3'], ['id' => 'a4']], 'n2'),
            self::page('attributes', [['id' => 'a5'], ['id' => 'a6']]),
        ]);

        // Pages arrive whole, so the walk can overshoot max by up to one page.
        $rows = $this->client($connection)->facts->allAttributes(new AttributeListOptions(limit: 2), max: 3);

        $this->assertSame(['a1', 'a2', 'a3', 'a4'], array_column($rows, 'id'));
        $this->assertCount(2, $connection->requests);

        $connection = new SequenceHttpConnection([self::page('chunks', [['index' => 0], ['index' => 1]], 'n')]);

        $this->assertSame([['index' => 0], ['index' => 1]], $this->client($connection)->documents->allChunks('d1', max: 1));
        $this->assertCount(1, $connection->requests);
    }

    public function testEveryCollectorThatTakesMaxStopsWithoutFetchingThePageBeyondIt(): void
    {
        $collectors = [
            'allChunks' => ['chunks', fn (AgentMemory $m): array => $m->documents->allChunks('d1', max: 2)],
            'allChanges' => ['history', fn (AgentMemory $m): array => $m->entities->allChanges('person', 'alice', max: 2)],
            'allNeighbours' => ['neighbours', fn (AgentMemory $m): array => $m->entities->allNeighbours('person', 'alice', max: 2)],
            'allAttributes' => ['attributes', fn (AgentMemory $m): array => $m->facts->allAttributes(max: 2)],
            'allRelations' => ['relations', fn (AgentMemory $m): array => $m->facts->allRelations(max: 2)],
            'allActions' => ['actions', fn (AgentMemory $m): array => $m->facts->allActions(max: 2)],
        ];

        foreach ($collectors as $name => [$key, $call]) {
            // One reply only: a request for the page beyond max would find none left.
            $connection = new SequenceHttpConnection([self::page($key, [['row' => 1], ['row' => 2]], 'n')]);

            $this->assertSame([['row' => 1], ['row' => 2]], $call($this->client($connection)), $name);
            $this->assertCount(1, $connection->requests, $name);
        }
    }

    public function testCollectorsRejectACursorCountOrMaxBeforeAnyRequest(): void
    {
        // collector => [the page method it walks, called with a cursor, with count
        // (null when its options have none), with max: 0 (null when it takes no max)]
        $collectors = [
            'AgentMemory::auditAll()' => [
                'AgentMemory::audit()',
                fn (AgentMemory $m): array => $m->auditAll(new AuditOptions(cursor: 'c')),
                null,
                null,
            ],
            'Documents::listAll()' => [
                'Documents::list()',
                fn (AgentMemory $m): array => $m->documents->listAll(new DocumentListOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->documents->listAll(new DocumentListOptions(count: true)),
                null,
            ],
            'Documents::allChunks()' => [
                'Documents::chunks()',
                fn (AgentMemory $m): array => $m->documents->allChunks('d1', new PageOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->documents->allChunks('d1', new PageOptions(count: true)),
                fn (AgentMemory $m): array => $m->documents->allChunks('d1', max: 0),
            ],
            'DocumentKeywords::listAll()' => [
                'DocumentKeywords::list()',
                fn (AgentMemory $m): array => $m->documents->keywords->listAll(new KeywordListOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->documents->keywords->listAll(new KeywordListOptions(count: true)),
                null,
            ],
            'Entities::listAll()' => [
                'Entities::list()',
                fn (AgentMemory $m): array => $m->entities->listAll(new EntityListOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->entities->listAll(new EntityListOptions(count: true)),
                null,
            ],
            'Entities::allChanges()' => [
                'Entities::changes()',
                fn (AgentMemory $m): array => $m->entities->allChanges('person', 'alice', new PageOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->entities->allChanges('person', 'alice', new PageOptions(count: true)),
                fn (AgentMemory $m): array => $m->entities->allChanges('person', 'alice', max: 0),
            ],
            'Entities::allNeighbours()' => [
                'Entities::neighbours()',
                fn (AgentMemory $m): array => $m->entities->allNeighbours('person', 'alice', new NeighbourhoodOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->entities->allNeighbours('person', 'alice', new NeighbourhoodOptions(count: true)),
                fn (AgentMemory $m): array => $m->entities->allNeighbours('person', 'alice', max: 0),
            ],
            'Facts::allAttributes()' => [
                'Facts::attributes()',
                fn (AgentMemory $m): array => $m->facts->allAttributes(new AttributeListOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->facts->allAttributes(new AttributeListOptions(count: true)),
                fn (AgentMemory $m): array => $m->facts->allAttributes(max: 0),
            ],
            'Facts::allRelations()' => [
                'Facts::relations()',
                fn (AgentMemory $m): array => $m->facts->allRelations(new RelationListOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->facts->allRelations(new RelationListOptions(count: true)),
                fn (AgentMemory $m): array => $m->facts->allRelations(max: 0),
            ],
            'Facts::allActions()' => [
                'Facts::actions()',
                fn (AgentMemory $m): array => $m->facts->allActions(new ActionListOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->facts->allActions(new ActionListOptions(count: true)),
                fn (AgentMemory $m): array => $m->facts->allActions(max: 0),
            ],
            'Keys::listAll()' => [
                'Keys::list()',
                fn (AgentMemory $m): array => $m->keys->listAll(new PageOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->keys->listAll(new PageOptions(count: true)),
                null,
            ],
            'Principals::listAll()' => [
                'Principals::list()',
                fn (AgentMemory $m): array => $m->principals->listAll(new PageOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->principals->listAll(new PageOptions(count: true)),
                null,
            ],
            'Scopes::listAll()' => [
                'Scopes::list()',
                fn (AgentMemory $m): array => $m->scopes->listAll(new ScopeListOptions(cursor: 'c')),
                null,
                null,
            ],
            'Session::allTurns()' => [
                'Session::turns()',
                fn (AgentMemory $m): array => $m->sessions->attach('s1')->allTurns(new PageOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->sessions->attach('s1')->allTurns(new PageOptions(count: true)),
                null,
            ],
            'Traces::listAll()' => [
                'Traces::list()',
                fn (AgentMemory $m): array => $m->traces->listAll(new PageOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->traces->listAll(new PageOptions(count: true)),
                null,
            ],
            'Uncertainty::listAll()' => [
                'Uncertainty::list()',
                fn (AgentMemory $m): array => $m->uncertainty->listAll(new UncertaintyListOptions(cursor: 'c')),
                fn (AgentMemory $m): array => $m->uncertainty->listAll(new UncertaintyListOptions(count: true)),
                null,
            ],
        ];

        $this->assertCount(16, $collectors);

        foreach ($collectors as $collector => [$pageMethod, $withCursor, $withCount, $withMax]) {
            $cases = ['cursor' => [$withCursor, "{$collector} walks the collection from its first page, so it takes no cursor; use {$pageMethod} and next() to resume from one."]];

            if ($withCount !== null) {
                $cases['count'] = [$withCount, "{$collector} returns rows, not a total, so it takes no count; read totalSize from {$pageMethod} with count: true."];
            }

            if ($withMax !== null) {
                $cases['max'] = [$withMax, "{$collector} needs max to be at least 1, got 0."];
            }

            foreach ($cases as $case => [$call, $message]) {
                $connection = new SequenceHttpConnection([]);

                try {
                    $call($this->client($connection));
                    $this->fail("{$collector} with a {$case}: expected an InvalidArgumentException");
                } catch (InvalidArgumentException $e) {
                    $this->assertSame(InvalidArgumentException::class, $e::class, "{$collector} {$case}");
                    $this->assertSame($message, $e->getMessage(), "{$collector} {$case}");
                    $this->assertSame([], $connection->requests, "{$collector} {$case}");
                }
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page::collect() needs max to be at least 1, got -1.');

        Page::fromEnvelope(['traces' => [['id' => 't1']]], 'traces')->collect(-1);
    }

    public function testCollectorsKeepTheirFilters(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('relations', [['id' => 'r1']], 'n'),
            self::page('relations', [['id' => 'r2']]),
        ]);

        $rows = $this->client($connection)->facts->allRelations(new RelationListOptions(label: 'works_at', limit: 1));

        $this->assertSame([['id' => 'r1'], ['id' => 'r2']], $rows);
        $this->assertSame([
            self::BASE . '/relations?label=works_at&limit=1',
            self::BASE . '/relations?cursor=n&label=works_at&limit=1',
        ], $connection->urls());
    }

    public function testCollectingAnEmptyCollectionReturnsNoRows(): void
    {
        $connection = new SequenceHttpConnection([new Response(200, [], '{"documents":[],"page":{"hasMore":false}}')]);

        $this->assertSame([], $this->client($connection)->documents->listAll());
        $this->assertCount(1, $connection->requests);
    }

    public function testAnEmptyFollowUpBodyEndsTheWalk(): void
    {
        foreach ([new Response(204), new Response(200, [], '')] as $empty) {
            $connection = new SequenceHttpConnection([self::page('documents', [['id' => 'd1']], 'n'), $empty]);

            $this->assertSame([['id' => 'd1']], $this->client($connection)->documents->listAll(), (string) $empty->getStatusCode());
            $this->assertCount(2, $connection->requests);
        }
    }

    public function testCollectRejectsACursorThatDoesNotAdvance(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('traces', [['id' => 't1']], 'same'),
            self::page('traces', [['id' => 't1']], 'same'),
        ]);

        $this->expectException(AgentMemoryException::class);
        $this->expectExceptionMessage('Pagination cursor did not advance');

        $this->client($connection)->traces->listAll();
    }

    public function testCollectRejectsMoreRowsWithoutACursor(): void
    {
        $connection = new SequenceHttpConnection([
            new Response(200, [], '{"principals":[{"id":"admin"}],"page":{"hasMore":true}}'),
        ]);

        $this->expectException(AgentMemoryException::class);
        $this->expectExceptionMessage('the server reported more rows but sent no cursor to fetch them');

        $this->client($connection)->principals->listAll();
    }

    public function testCollectWithoutAFetchClosureReturnsThisPage(): void
    {
        // A page built by hand (e.g. to stub a collection in a test) has nothing to fetch.
        $page = new Page([['id' => 'a'], ['id' => 'b']], hasMore: true, nextCursor: 'n');

        $this->assertSame([['id' => 'a'], ['id' => 'b']], $page->collect());
        $this->assertSame([['id' => 'a'], ['id' => 'b']], $page->collect(1));
    }

    public function testCollectStartsAtThePageItIsCalledOn(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('entities', [['name' => 'a']], 'c1'),
            self::page('entities', [['name' => 'b']], 'c2'),
            self::page('entities', [['name' => 'c']]),
        ]);

        $second = $this->client($connection)->entities->list()->next();

        $this->assertNotNull($second);
        $this->assertSame([['name' => 'b'], ['name' => 'c']], $second->collect());
        $this->assertCount(3, $connection->requests);
    }

    public function testAllEdgesOfWalksOutboundThenInbound(): void
    {
        $connection = new SequenceHttpConnection([
            self::page('relations', [['id' => 'out']], 'n'),
            self::page('relations', [['id' => 'loop']]),
            self::page('relations', [['id' => 'loop'], ['id' => 'in']]),
        ]);

        $edges = $this->client($connection)->facts->allEdgesOf('person/alice', label: 'works_at');

        // A self-loop is both outbound and inbound; it is kept twice, as in JS.
        $this->assertSame(['out', 'loop', 'loop', 'in'], array_column($edges, 'id'));
        $this->assertSame([
            self::BASE . '/relations?src=person%2Falice&label=works_at',
            self::BASE . '/relations?cursor=n&src=person%2Falice&label=works_at',
            self::BASE . '/relations?dst=person%2Falice&label=works_at',
        ], $connection->urls());

        $connection = new SequenceHttpConnection([self::page('relations', []), self::page('relations', [])]);

        $this->assertSame([], $this->client($connection)->facts->allEdgesOf(new EntityRef('person', 'alice'), limit: 5));
        $this->assertSame([
            self::BASE . '/relations?src=person%2Falice&limit=5',
            self::BASE . '/relations?dst=person%2Falice&limit=5',
        ], $connection->urls());
    }

    public function testAllEdgesOfRejectsAMalformedEntityBeforeAnyRequest(): void
    {
        $connection = new SequenceHttpConnection([]);

        try {
            $this->client($connection)->facts->allEdgesOf('alice');
            $this->fail('Expected a bare name to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(InvalidArgumentException::class, $e::class);
            $this->assertSame([], $connection->requests);
        }
    }
}
