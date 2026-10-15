<?php

namespace SurrealDB\Tests\Integration\AgentMemory;

use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Enum\InferMode;
use SurrealDB\AgentMemory\Enum\LookupSection;
use SurrealDB\AgentMemory\Enum\EntityRanking;
use SurrealDB\AgentMemory\Enum\Verb;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\AuthException;
use SurrealDB\AgentMemory\Exceptions\NotFoundException;
use SurrealDB\AgentMemory\Exceptions\ScopeException;
use SurrealDB\AgentMemory\Exceptions\ValidationException;
use SurrealDB\AgentMemory\Message;
use SurrealDB\AgentMemory\Options\ActionListOptions;
use SurrealDB\AgentMemory\Options\AttributeListOptions;
use SurrealDB\AgentMemory\Options\AuditOptions;
use SurrealDB\AgentMemory\Options\DocumentListOptions;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use SurrealDB\AgentMemory\Options\EffectiveGrantsOptions;
use SurrealDB\AgentMemory\Options\EntityGetOptions;
use SurrealDB\AgentMemory\Options\EntityListOptions;
use SurrealDB\AgentMemory\Options\EntitySearchOptions;
use SurrealDB\AgentMemory\Options\KeyCreateOptions;
use SurrealDB\AgentMemory\Options\LookupOptions;
use SurrealDB\AgentMemory\Options\NeighbourhoodOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Options\RecallOptions;
use SurrealDB\AgentMemory\Options\RelationListOptions;
use SurrealDB\AgentMemory\Options\RememberManyOptions;
use SurrealDB\AgentMemory\Options\RememberOptions;
use SurrealDB\AgentMemory\Options\ScopeListOptions;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;
use SurrealDB\AgentMemory\Options\SessionCreateOptions;
use SurrealDB\AgentMemory\Options\StateOptions;
use SurrealDB\AgentMemory\Options\TopEntitiesOptions;
use SurrealDB\AgentMemory\Options\UncertaintyListOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Triple;

/**
 * The deterministic Agent Memory surface against a real server: everything
 * that runs without a language model or an embedder, so the suite passes on a
 * server booted with placeholder provider keys. Facts are written as
 * caller-supplied triples, which store verbatim with no extraction.
 */
final class AgentMemoryIntegrationTest extends AgentMemoryServerTestCase
{
    /** @var array<int|string,mixed> */
    private static array $remembered = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $alice = self::entity('person', 'alice');

        self::$remembered = self::memory()->remember(null, new RememberOptions(
            infer: InferMode::TRIPLES,
            scopes: self::$scope,
            labels: ['suite=php-sdk'],
            triples: [
                Triple::attribute($alice, 'role', 'CTO', confidence: 0.9),
                Triple::attribute($alice, 'city', 'Lisbon'),
                Triple::attribute($alice, 'team', 'Platform'),
                Triple::relation($alice, 'works_at', self::entity('organisation', 'acme')),
                Triple::action($alice, 'shipped', object: 'the v2 release', occurredAt: '2026-09-01T10:00:00Z'),
                Triple::attribute(self::entity('person', 'bob'), 'role', 'Engineer'),
            ],
        ));
    }

    public function testHealthAndWhoami(): void
    {
        $memory = self::memory();
        $memory->health();

        $me = $memory->whoami();

        $this->assertNotEmpty($me['principalId']);
        $this->assertArrayHasKey(Verb::MEMORY_READ->value, $me['grants']);
    }

    public function testRememberStoresAllThreeFactFamiliesFromTriples(): void
    {
        $extraction = self::$remembered['extraction'];

        $this->assertSame('triples', self::$remembered['mode']);
        $this->assertNotEmpty(self::$remembered['sessionId']);
        $this->assertCount(4, $extraction['attributes']);
        $this->assertCount(1, $extraction['relations']);
        $this->assertCount(1, $extraction['actions']);
        $this->assertSame('shipped', $extraction['actions'][0]['verb']);
    }

    public function testAnEntityOnlyTripleUpsertsTheEntity(): void
    {
        $memory = self::memory();

        $memory->remember(null, new RememberOptions(
            infer: InferMode::TRIPLES,
            scopes: self::$scope,
            triples: [Triple::entity(self::entity('person', 'dave'))],
        ));

        $dave = $memory->entities->get('person', 'dave' . self::$run);
        $this->assertSame('dave' . self::$run, $dave['entity']['name']);
        $this->assertSame([], $dave['attributes']);
    }

    public function testScopesRegisterPaginateAndTombstone(): void
    {
        $scopes = self::memory()->scopes;
        $child = self::$scope . '/tmp';

        $node = $scopes->register(new ScopeRegisterOptions($child));
        $this->assertSame($child . '/', $node['path']);

        $first = $scopes->list(new ScopeListOptions(limit: 1));
        $this->assertCount(1, $first);
        $this->assertTrue($first->hasMore);

        $paths = array_column(iterator_to_array($first->autoPaginate(), false), 'path');
        $this->assertContains(self::$scope . '/', $paths);
        $this->assertContains($child . '/', $paths);
        $this->assertSame($paths, array_column($scopes->listAll(new ScopeListOptions(limit: 1)), 'path'));

        $scopes->delete($child);

        $tombstoned = array_values(array_filter(
            iterator_to_array($scopes->list()->autoPaginate(), false),
            static fn (array $scope): bool => $scope['path'] === $child . '/',
        ));
        $this->assertNotNull($tombstoned[0]['tombstonedAt'] ?? null);
    }

    public function testFactCollectionsFilterByEntityAndWalkEveryPage(): void
    {
        $facts = self::memory()->facts;
        $alice = self::entity('person', 'alice');

        $first = $facts->attributes(new AttributeListOptions(entity: $alice, limit: 1, count: true));
        $this->assertCount(1, $first);
        $this->assertSame(3, $first->totalSize);
        $this->assertNotNull($first->nextCursor);

        $attributes = iterator_to_array($first->autoPaginate(), false);
        $this->assertEqualsCanonicalizing(['role', 'city', 'team'], array_column($attributes, 'key'));
        $this->assertSame(['suite=php-sdk'], $attributes[0]['labels']);
        $this->assertSame([[self::$scope . '/']], $attributes[0]['scope']);

        $tagged = $facts->attributes(new AttributeListOptions(entity: $attributes[0]['entity'], limit: 10));
        $this->assertEqualsCanonicalizing(['role', 'city', 'team'], array_column($tagged->items, 'key'));

        $collected = $facts->allAttributes(new AttributeListOptions(entity: $alice, limit: 1));
        $this->assertEqualsCanonicalizing(['role', 'city', 'team'], array_column($collected, 'key'));
        $this->assertCount(2, $facts->allAttributes(new AttributeListOptions(entity: $alice, limit: 1), max: 2));

        $relations = $facts->relations(new RelationListOptions(src: $alice));
        $this->assertSame(['works_at'], array_column($relations->items, 'label'));

        $edges = $facts->allEdgesOf($alice);
        $this->assertSame(['works_at'], array_column($edges, 'label'));
        $this->assertSame($edges, $facts->allEdgesOf(self::entity('organisation', 'acme')), 'the one edge is inbound to acme');
        $this->assertSame($relations->items, $facts->allRelations(new RelationListOptions(src: $alice)));
        $this->assertSame(['shipped'], array_column($facts->allActions(new ActionListOptions(actor: $alice)), 'verb'));

        $actions = $facts->actions(new ActionListOptions(actor: $alice, since: '2026-08-01T00:00:00Z'));
        $this->assertSame(['shipped'], array_column($actions->items, 'verb'));
        $this->assertSame([], $facts->actions(new ActionListOptions(actor: $alice, since: '2026-10-01T00:00:00Z'))->items);
    }

    public function testEntitiesSearchRankReadAndWalk(): void
    {
        $entities = self::memory()->entities;
        $alice = 'alice' . self::$run;

        $people = $entities->list(new EntityListOptions(type: 'person', limit: 1, count: true));
        $this->assertCount(1, $people);
        $this->assertGreaterThanOrEqual(2, $people->totalSize);
        $this->assertContains($alice, array_column(iterator_to_array($people->autoPaginate(), false), 'name'));
        $this->assertContains($alice, array_column($entities->listAll(new EntityListOptions(type: 'person')), 'name'));
        $this->assertSame($people->totalSize, $entities->count(new EntityListOptions(type: 'person')));

        $matches = $entities->search(query: $alice, options: new EntitySearchOptions(type: 'person'));
        $this->assertSame($alice, $matches[0]['entity']['name']);
        $this->assertSame(1.0, (float) $matches[0]['score']);
        $this->assertSame(5, $matches[0]['factCount']);

        $top = $entities->top(new TopEntitiesOptions(by: EntityRanking::COVERAGE, type: 'person', limit: 3));
        $this->assertNotEmpty($top);
        $this->assertArrayHasKey('factCount', $top[0]);

        $entity = $entities->get('person', $alice, new EntityGetOptions(limit: 2));
        $this->assertSame($alice, $entity['entity']['name']);
        $this->assertCount(2, $entity['attributes']);
        $this->assertTrue($entity['truncated']['attributes']);

        $history = $entities->history('person', $alice, 'role');
        $this->assertSame(['CTO'], array_column($history, 'value'));

        $all = $entities->changes('person', $alice, new PageOptions(limit: 2));
        $this->assertCount(3, iterator_to_array($all->autoPaginate(), false));
        $this->assertCount(3, $entities->allChanges('person', $alice, new PageOptions(limit: 2)));
        // max stops after the page that reaches it: one page of two rows.
        $this->assertCount(2, $entities->allChanges('person', $alice, new PageOptions(limit: 2), max: 1));

        $neighbours = $entities->neighbours('person', $alice, new NeighbourhoodOptions(count: true));
        $this->assertSame(1, $neighbours->totalSize);
        $this->assertSame('works_at', $neighbours->items[0]['label']);
        $this->assertTrue($neighbours->items[0]['outbound']);
        $this->assertSame('acme' . self::$run, $neighbours->items[0]['far']['entity']['name']);
        $this->assertSame($neighbours->items, $entities->allNeighbours('person', $alice));
    }

    public function testEntityDeleteRetiresItsFacts(): void
    {
        $entities = self::memory()->entities;
        $bob = 'bob' . self::$run;

        $this->assertCount(1, $entities->get('person', $bob)['attributes']);

        $entities->delete('person', $bob);

        $this->assertSame([], $entities->get('person', $bob)['attributes']);
    }

    public function testLookupResolvesTheSubjectDeterministically(): void
    {
        $memory = self::memory();
        $alice = 'alice' . self::$run;

        $answer = $memory->lookup($alice);
        $this->assertSame('entity', $answer['resolution']['kind']);
        $this->assertSame($alice, $answer['resolution']['subject']['entity']['name']);
        $this->assertEqualsCanonicalizing(['role', 'city', 'team'], array_column($answer['facts']['items'], 'key'));
        $this->assertSame(['shipped'], array_column($answer['events']['items'], 'verb'));
        $this->assertSame($answer['facts'], $memory->lookup($alice)['facts'], 'lookup answers identically on repeat');

        $narrowed = $memory->lookup('who is she', new LookupOptions(
            subject: self::entity('person', 'alice'),
            include: [LookupSection::FACTS],
            factLimit: 1,
        ));
        $this->assertCount(1, $narrowed['facts']['items']);
        $this->assertTrue($narrowed['facts']['truncated']);
        $this->assertSame(['items' => [], 'truncated' => false], $narrowed['relations']);

        $this->assertSame('empty', $memory->lookup('nobody' . self::$run)['resolution']['kind']);
    }

    public function testInspectReadsKindTaggedRefs(): void
    {
        $memory = self::memory();

        $entity = $memory->inspect('entity:' . self::entity('person', 'alice'));
        $this->assertSame('entity', $entity['kind']);
        $this->assertSame($entity, $memory->inspect(EntityRef::parse(self::entity('person', 'alice'))));

        $attribute = $memory->inspect('attribute:' . self::entity('person', 'alice') . '/role');
        $this->assertSame('attribute', $attribute['kind']);
        $this->assertSame('CTO', $attribute['current']['value']);
    }

    public function testRememberManyRecordsTurnsOnTheSessionItCreates(): void
    {
        $memory = self::memory();

        $batch = $memory->rememberMany(
            [Message::user('Planning the offsite for May'), Message::assistant('Noted, May it is.')],
            new RememberManyOptions(infer: InferMode::NONE, scopes: self::$scope),
        );
        $this->assertCount(2, $batch['turnIds']);

        $turns = $memory->sessions->attach($batch['sessionId'])->turns(new PageOptions(limit: 1, count: true));
        $this->assertSame(2, $turns->totalSize);
        $this->assertSame(
            ['Planning the offsite for May', 'Noted, May it is.'],
            array_column(iterator_to_array($turns->autoPaginate(), false), 'content'),
        );
        $this->assertSame(
            ['Planning the offsite for May', 'Noted, May it is.'],
            array_column($memory->sessions->attach($batch['sessionId'])->allTurns(new PageOptions(limit: 1)), 'content'),
        );
    }

    public function testRememberManyAcceptsSystemAndToolTurns(): void
    {
        $memory = self::memory();

        $batch = $memory->rememberMany(
            [Message::system('Answer in one sentence.'), Message::user('What did the build return?'), Message::tool('{"status":"green"}')],
            new RememberManyOptions(infer: InferMode::NONE, scopes: self::$scope),
        );
        $this->assertCount(3, $batch['turnIds']);

        $session = $memory->sessions->attach($batch['sessionId']);
        $this->assertSame(['system', 'user', 'tool'], array_column(iterator_to_array($session->turns()->autoPaginate(), false), 'role'));
        $this->assertSame(['system', 'user', 'tool'], array_column($session->allTurns(), 'role'));
    }

    public function testSessionLifecycle(): void
    {
        $session = self::memory()->sessions->create(new SessionCreateOptions(scopes: self::$scope, metadata: ['suite' => 'php']));

        $this->assertNotSame('', $session->id);
        $this->assertSame([[self::$scope]], $session->scopes);
        $this->assertSame([], $session->turns()->items);

        $session->close();

        $this->expectException(NotFoundException::class);
        $session->turns();
    }

    public function testAContradictionIsFlaggedAndCanBeSettled(): void
    {
        $memory = self::memory();
        $carol = self::entity('person', 'carol');

        // Two low-confidence values for one attribute fall below the reconciler's
        // floor, so the second is held as an open question instead of winning.
        foreach (['CTO', 'CEO'] as $role) {
            $memory->remember(null, new RememberOptions(
                infer: InferMode::TRIPLES,
                scopes: self::$scope,
                triples: [Triple::attribute($carol, 'role', $role, confidence: 0.6)],
            ));
        }

        $uncertainty = $memory->uncertainty;
        $open = $uncertainty->list(new UncertaintyListOptions(entity: $carol, resolved: false, count: true));
        $this->assertSame(1, $open->totalSize);
        $this->assertSame(1, $uncertainty->count(new UncertaintyListOptions(entity: $carol, resolved: false)));
        $flag = $open->items[0];
        $this->assertSame('role', $flag['key']);
        $this->assertTrue($flag['resolvable']);
        $this->assertSame([$flag['id']], array_column($uncertainty->listAll(new UncertaintyListOptions(entity: $carol, resolved: false)), 'id'));

        $settled = $uncertainty->resolve($flag['id'], 'CEO', note: 'confirmed by HR');
        $this->assertSame($flag['id'], $settled['id']);
        $this->assertTrue($settled['resolved']);

        $this->assertSame([], $uncertainty->list(new UncertaintyListOptions(entity: $carol, resolved: false))->items);
        $this->assertCount(1, $uncertainty->list(new UncertaintyListOptions(entity: $carol, resolved: true))->items);
        $this->assertSame(0, $uncertainty->count(new UncertaintyListOptions(entity: $carol, resolved: false)));
        $this->assertSame(1, $uncertainty->count(new UncertaintyListOptions(entity: $carol, resolved: true)));
    }

    public function testUncertaintyListsAndRejectsUnknownFlags(): void
    {
        $uncertainty = self::memory()->uncertainty;

        $open = $uncertainty->list(new UncertaintyListOptions(entity: self::entity('person', 'alice'), resolved: false));
        $this->assertInstanceOf(Page::class, $open);
        $this->assertFalse($open->hasMore);

        $this->expectException(NotFoundException::class);
        $uncertainty->resolve('missing' . self::$run, 'CTO');
    }

    public function testAuditStateProfileAndTraces(): void
    {
        $memory = self::memory();

        $audit = $memory->audit(new AuditOptions(limit: 2));
        $this->assertNotEmpty($audit->items);
        $this->assertArrayHasKey('traceId', $audit->items[0]);

        $state = $memory->state(new StateOptions(limit: 1));
        $this->assertArrayHasKey('truncated', $state);
        $this->assertTrue($state['truncated']['attributes']);

        $this->assertArrayHasKey('preferences', $memory->profile());

        $traces = $memory->traces->list(new PageOptions(limit: 1));
        $this->assertCount(1, $traces);
        $this->assertSame($traces->items[0]['id'], $memory->traces->get($traces->items[0]['id'])['id']);
        $this->assertIsInt($memory->traces->stats()['windowHours']);
    }

    public function testDocumentsUploadReadListAndDelete(): void
    {
        $documents = self::memory()->documents;
        $text = '# Runbook ' . self::$run . "\n\nThe deploy pipeline has four stages.\n";

        // No filename and no content type: the server sniffs the text instead of refusing it.
        $upload = $documents->upload(new DocumentUploadOptions(file: $text, title: 'Runbook ' . self::$run, scopes: self::$scope));
        $this->assertSame('queued', $upload['status']);

        $this->assertSame('text/plain', $documents->get($upload['id'])['mimeType']);
        $this->assertSame($text, $documents->raw($upload['id']));
        $this->assertContains($upload['id'], array_column(iterator_to_array($documents->list()->autoPaginate(), false), 'id'));
        $this->assertContains($upload['id'], array_column($documents->listAll(), 'id'));
        $this->assertInstanceOf(Page::class, $documents->chunks($upload['id'], new PageOptions(count: true)));

        $documents->delete($upload['id']);

        $this->expectException(NotFoundException::class);
        $documents->get($upload['id']);
    }

    public function testDocumentCollectionsPage(): void
    {
        $documents = self::memory()->documents;

        $list = $documents->list(new DocumentListOptions(count: true));
        $this->assertIsInt($list->totalSize);
        $this->assertSame($list->totalSize, $documents->count());

        $this->assertInstanceOf(Page::class, $documents->keywords->list());
        $this->assertIsList($documents->keywords->listAll());
        $this->assertArrayHasKey('linksEmitted', $documents->recomputeLinks());
    }

    public function testPrincipalsAndEffectiveGrants(): void
    {
        $principals = self::memory()->principals;
        $me = self::memory()->whoami()['principalId'];

        $this->assertContains($me, array_column(iterator_to_array($principals->list()->autoPaginate(), false), 'id'));
        $this->assertContains($me, array_column($principals->listAll(new PageOptions(limit: 1)), 'id'));
        $this->assertSame($me, $principals->get($me)['id']);

        $effective = $principals->effective($me, new EffectiveGrantsOptions(self::$scope));
        $this->assertContains(Verb::MEMORY_WRITE->value, $effective['verbs']);
    }

    public function testKeysMintRotateAndRevoke(): void
    {
        $keys = self::memory()->keys;
        $name = 'php-sdk-' . self::$run;

        $minted = $keys->create(new KeyCreateOptions(name: $name, ttlSeconds: 600));
        $this->assertStringStartsWith('sp-', $minted['key']);
        $this->assertNotNull($minted['validUntil']);
        $this->assertSame(self::memory()->whoami()['principalId'], self::client($minted['key'])->whoami()['principalId']);
        $this->assertContains($name, array_column(iterator_to_array($keys->list()->autoPaginate(), false), 'name'));
        $this->assertContains($name, array_column($keys->listAll(new PageOptions(limit: 1)), 'name'));

        $rotated = $keys->rotate($name);
        $this->assertNotSame($minted['key'], $rotated['key']);
        self::client($rotated['key'])->whoami();

        try {
            self::client($minted['key'])->whoami();
            $this->fail('The pre-rotation secret should no longer authenticate');
        } catch (AuthException $e) {
            $this->assertSame(401, $e->status);
        }

        $keys->delete($name);
        $this->assertNotContains($name, array_column($keys->listAll(), 'name'));
    }

    public function testLifecycleSweepsAndIntegrityCheck(): void
    {
        $memory = self::memory();

        $this->assertIsInt($memory->lifecycle->expire()['affected']);
        $this->assertIsInt($memory->lifecycle->decay()['affected']);
        $this->assertIsInt($memory->fsck()['total']);
    }

    public function testTheDelegationHeaderReachesTheServer(): void
    {
        // A request the server must refuse can only fail if it read the
        // `X-Spectron-On-Behalf-Of` header the client sends.
        try {
            self::memory()->onBehalfOf('system')->whoami();
            $this->fail('Delegating to a principal that forbids it should be refused');
        } catch (ScopeException $e) {
            $this->assertSame(403, $e->status);
        }

        $this->expectException(NotFoundException::class);
        self::memory()->onBehalfOf('nobody' . self::$run)->whoami();
    }

    public function testServerErrorsMapToTypedExceptions(): void
    {
        $memory = self::memory();
        $cases = [
            'bad key' => [AuthException::class, 401, static fn () => self::client('sp-not-a-real-key')->whoami()],
            'malformed context id' => [ValidationException::class, 422, static fn () => self::client('sp-x', 'not-valid')->whoami()],
            'unknown recall mode' => [ValidationException::class, 400, static fn () => $memory->recall('x', new RecallOptions(mode: 'lexical'))],
            'untagged inspect ref' => [ValidationException::class, 400, static fn () => $memory->inspect(self::entity('person', 'alice'))],
            'unknown entity' => [NotFoundException::class, 404, static fn () => $memory->entities->get('person', 'nobody' . self::$run)],
        ];

        foreach ($cases as $name => [$class, $status, $call]) {
            try {
                $call();
                $this->fail("{$name}: expected {$class}");
            } catch (AgentMemoryException $e) {
                $this->assertSame($class, $e::class, $name);
                $this->assertSame($status, $e->status, $name);
                $this->assertNotSame('', $e->title, $name);
            }
        }
    }
}
