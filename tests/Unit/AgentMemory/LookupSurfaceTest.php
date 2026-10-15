<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Enum\EntityRanking;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\ActionListOptions;
use SurrealDB\AgentMemory\Options\EntityGetOptions;
use SurrealDB\AgentMemory\Options\EntityHistoryOptions;
use SurrealDB\AgentMemory\Options\EntitySearchOptions;
use SurrealDB\AgentMemory\Options\NeighbourhoodOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Options\RelationListOptions;
use SurrealDB\AgentMemory\Options\TopEntitiesOptions;
use SurrealDB\AgentMemory\Options\UncertaintyListOptions;
use SurrealDB\Tests\Fakes\RecordingHttpConnection;

/** The subject-centred read surface: fact families, entity lookup, and uncertainty. */
final class LookupSurfaceTest extends TestCase
{
    private const string BASE = 'https://memory.example.com/api/v1/acme_prod';

    private function client(RecordingHttpConnection $connection): AgentMemory
    {
        return new AgentMemory(
            new AgentMemoryOptions('https://memory.example.com', 'acme_prod', 'secret'),
            $connection,
        );
    }

    public function testFactCollectionsFilterByEntityRefs(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"relations":[],"actions":[],"page":{"hasMore":false}}'));
        $facts = $this->client($connection)->facts;

        $facts->relations(new RelationListOptions(src: new EntityRef('person', 'alice'), dst: 'company/acme', label: 'works_at', count: true));
        $this->assertSame(
            self::BASE . '/relations?src=person%2Falice&dst=company%2Facme&label=works_at&count=true',
            $connection->url,
        );

        $facts->actions(new ActionListOptions(actor: 'person/alice', verb: 'shipped', since: '2026-01-01T00:00:00Z', limit: 5));
        $this->assertSame(
            self::BASE . '/actions?actor=person%2Falice&verb=shipped&since=2026-01-01T00%3A00%3A00Z&limit=5',
            $connection->url,
        );
    }

    public function testEntitySearchAndTopUnwrapTheirMatches(): void
    {
        $connection = new RecordingHttpConnection(new Response(
            200,
            [],
            '{"matches":[{"entity":{"name":"alice"},"score":1.0}],"entities":[{"entity":{"name":"bob"},"score":0.5}]}',
        ));
        $entities = $this->client($connection)->entities;

        $matches = $entities->search('alice', new EntitySearchOptions(type: 'person', limit: 3));
        $this->assertSame(self::BASE . '/entities/search?q=alice&type=person&limit=3', $connection->url);
        $this->assertSame('alice', $matches[0]['entity']['name']);

        $top = $entities->top(new TopEntitiesOptions(by: EntityRanking::RECENCY, limit: 2));
        $this->assertSame(self::BASE . '/entities/top?by=recency&limit=2', $connection->url);
        $this->assertSame('bob', $top[0]['entity']['name']);
    }

    public function testEntitySearchRejectsABlankQuery(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));

        foreach (['empty' => '', 'whitespace-only' => '  '] as $name => $query) {
            try {
                $this->client($connection)->entities->search($query);
                $this->fail("The {$name} search query should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(InvalidArgumentException::class, $e::class, $name);
                $this->assertSame('Entity search requires a non-empty query.', $e->getMessage(), $name);
            }
        }

        $this->assertNull($connection->url);
    }

    public function testEntitySearchTakesItsQueryByName(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"matches":[]}'));

        $this->client($connection)->entities->search(query: 'alice');

        $this->assertSame(self::BASE . '/entities/search?q=alice', $connection->url);
    }

    public function testEnumsCarryTheServersValues(): void
    {
        $this->assertSame(['coverage', 'importance', 'recency'], array_column(EntityRanking::cases(), 'value'));
    }

    public function testEntityReadsCarryTheirTemporalAndPageParameters(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"history":[],"neighbours":[],"page":{"hasMore":false}}'));
        $entities = $this->client($connection)->entities;

        $entities->get('person', 'alice', new EntityGetOptions(limit: 20, asOf: '2026-05-01T00:00:00Z'));
        $this->assertSame(self::BASE . '/entities/person/alice?limit=20&asOf=2026-05-01T00%3A00%3A00Z', $connection->url);

        $entities->history('person', 'alice', 'role', new EntityHistoryOptions(validFrom: '2025-01-01', validUntil: '2026-01-01'));
        $this->assertSame(
            self::BASE . '/entities/person/alice/history/role?validFrom=2025-01-01&validUntil=2026-01-01',
            $connection->url,
        );

        $entities->changes('person', 'alice', new PageOptions(limit: 10, cursor: 'h2'));
        $this->assertSame(self::BASE . '/entities/person/alice/history?limit=10&cursor=h2', $connection->url);

        $entities->neighbours('person', 'alice', new NeighbourhoodOptions(minFacts: 2, limit: 8));
        $this->assertSame(self::BASE . '/entities/person/alice/neighbourhood?minFacts=2&limit=8', $connection->url);
    }

    public function testUncertaintyListsAndResolves(): void
    {
        $connection = new RecordingHttpConnection(new Response(
            200,
            [],
            '{"unknowns":[{"id":"u1","resolvable":true}],"page":{"hasMore":false},"uncertainty":{"id":"u1","resolved":true}}',
        ));
        $uncertainty = $this->client($connection)->uncertainty;

        $open = $uncertainty->list(new UncertaintyListOptions(entity: 'person/alice', resolved: false));
        $this->assertSame(self::BASE . '/uncertainty?entity=person%2Falice&resolved=false', $connection->url);
        $this->assertTrue($open->items[0]['resolvable']);

        $settled = $uncertainty->resolve('u 1', 'CTO', note: 'confirmed by HR');
        $this->assertSame('POST', $connection->method);
        $this->assertSame(self::BASE . '/uncertainty/u%201/resolve', $connection->url);
        $this->assertSame('{"acceptedValue":"CTO","note":"confirmed by HR"}', $connection->body);
        $this->assertSame(['id' => 'u1', 'resolved' => true], $settled);

        $uncertainty->resolve('u1', 'CTO');
        $this->assertSame('{"acceptedValue":"CTO"}', $connection->body);
    }

    public function testResolvingRequiresANonBlankValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client(new RecordingHttpConnection(new Response(200, [], '{}')))->uncertainty->resolve('u1', '   ');
    }
}
