<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Api\ApiResponse;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Exceptions\UnsuccessfulApiException;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\None;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;
use function sprintf;

/**
 * Database-backed coverage for the public SDK surface.
 *
 * These tests exercise a real local SurrealDB instance. Local runs skip when no
 * server is available; CI sets SURREALDB_REQUIRE_LOCAL=1 so availability is a
 * hard requirement.
 */
final class SurrealLocalDatabaseTest extends TestCase
{
    /** @var positive-int */
    private static int $databaseCounter = 1;

    /**
     * @return iterable<string,array{protocol: 'http'|'ws'}>
     */
    public static function protocols(): iterable
    {
        yield 'HTTP' => ['protocol' => 'http'];
        yield 'WebSocket' => ['protocol' => 'ws'];
    }

    #[DataProvider('protocols')]
    public function testConnectionHealthVersionAndRootAuthenticationUseRealDatabase(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            self::assertTrue($db->isConnected());
            self::assertStringStartsWith('surrealdb-', $db->version());

            $db->health();

            $tokens = $db->signin(new RootAuth('root', 'root'));

            self::assertNotNull($tokens->access);
            self::assertNotSame('', $tokens->access);
        } finally {
            $db->close();
        }
    }

    #[DataProvider('protocols')]
    public function testCrudBuildersAndBoundSessionVariablesUseRealDatabase(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $this->defineTables($db);

            $person = new RecordId('person', 'tobie');
            $created = $db->create($person)
                ->content(['name' => 'Tobie', 'visits' => 1])
                ->execute();

            $this->assertRecord($created, 'person:tobie');
            self::assertSame('Tobie', $created['name']);
            self::assertSame(1, $created['visits']);

            $selected = $db->select($person)->execute();

            $this->assertRecord($selected, 'person:tobie');
            self::assertEquals($created, $selected);

            $updated = $db->update($person)
                ->merge(['visits' => 2])
                ->execute();

            $this->assertRecord($updated, 'person:tobie');
            self::assertSame(2, $updated['visits']);

            $db->let('selectedName', 'Tobie');
            self::assertSame(['Tobie'], $db->run('RETURN $selectedName'));

            $deleted = $db->delete($person)->execute();
            $this->assertRecord($deleted, 'person:tobie');

            $afterDelete = $db->select($person)->execute();
            self::assertTrue(
                $afterDelete === null || $afterDelete instanceof None,
                'Expected no record after delete, got ' . get_debug_type($afterDelete),
            );
        } finally {
            $db->close();
        }
    }

    #[DataProvider('protocols')]
    public function testRelateBuilderPersistsGraphEdgesInRealDatabase(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $this->defineTables($db);

            $person = new RecordId('person', 'tobie');
            $article = new RecordId('article', 'surrealdb');

            $db->create($person)->content(['name' => 'Tobie'])->execute();
            $db->create($article)->content(['title' => 'SurrealDB'])->execute();

            $edge = $db->relate($person, new Table('likes'), $article)
                ->content(['strength' => 10])
                ->execute();

            $this->assertRecord($edge);
            self::assertSame('person:tobie', (string) ($edge['in'] ?? ''));
            self::assertSame('article:surrealdb', (string) ($edge['out'] ?? ''));
            self::assertSame(10, $edge['strength'] ?? null);

            $edges = $db->select(new Table('likes'))->execute();

            self::assertIsArray($edges);
            self::assertCount(1, $edges);
            $this->assertRecord($edges[0]);
            self::assertEquals($edge['id'], $edges[0]['id']);
        } finally {
            $db->close();
        }
    }

    #[DataProvider('protocols')]
    public function testApiInvokesUserDefinedEndpoints(string $protocol): void
    {
        $db = $this->connect($protocol);

        if (!$db->isFeatureSupported(Features::api())) {
            $db->close();
            self::markTestSkipped('DEFINE API needs SurrealDB 3.0 or later.');
        }

        try {
            $db->run(<<<'SURQL'
DEFINE API "/v1/hello/:name" FOR get, post THEN {
    RETURN {
        status: 200,
        headers: { "x-greeting": "hi" },
        body: { name: $request.params.name, method: $request.method, body: $request.body, query: $request.query, trace: $request.headers["x-trace"] }
    };
};
DEFINE API "/v1/missing" FOR get THEN { RETURN { status: 404, body: "nope" } };
DEFINE API "/v1/body" FOR post THEN { RETURN { status: 200, body: { none: $request.body IS NONE, null: $request.body IS NULL } } };
SURQL);

            $api = $db->api('/v1');
            $api->header('x-trace', 'abc');

            $response = $api->post('hello/tobie', ['a' => 1])->query('k', 'v')->execute();

            self::assertInstanceOf(ApiResponse::class, $response);
            self::assertSame(200, $response->status);
            self::assertSame('hi', $response->headers['x-greeting'] ?? null);
            self::assertEquals(
                ['name' => 'tobie', 'method' => 'post', 'body' => ['a' => 1], 'query' => ['k' => 'v'], 'trace' => 'abc'],
                $response->body,
            );

            self::assertSame('get', $api->get('/hello/jaime')->value()->execute()['method'] ?? null);

            self::assertSame(['none' => true, 'null' => false], $api->post('body')->value()->execute());
            self::assertSame(['none' => false, 'null' => true], $api->post('body', null)->value()->execute());

            $missing = $api->get('missing')->execute();
            self::assertInstanceOf(ApiResponse::class, $missing);
            self::assertSame(404, $missing->status);

            try {
                $api->get('missing')->value()->execute();
                self::fail('Expected a non-2xx response to throw.');
            } catch (UnsuccessfulApiException $error) {
                self::assertSame('/v1/missing', $error->path);
                self::assertSame('nope', $error->response->body);
            }
        } finally {
            $db->close();
        }
    }

    #[DataProvider('protocols')]
    public function testStringTargetsCannotInjectSurrealQl(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $this->defineTables($db);
            $db->create(new RecordId('person', 'tobie'))->content(['name' => 'Tobie'])->execute();

            $hostile = 'person; DELETE person; SELECT * FROM person';
            // Strict servers only write to defined tables.
            $db->run('DEFINE TABLE ' . Table::escapeIdent($hostile) . '; DEFINE TABLE ' . Table::escapeIdent('trailing\\'));
            $created = $db->create($hostile)->content(['name' => 'Hostile'])->execute();

            self::assertIsArray($created);
            self::assertCount(1, $created);
            self::assertSame('Hostile', $created[0]['name'] ?? null);
            self::assertInstanceOf(RecordId::class, $created[0]['id']);
            self::assertSame($hostile, $created[0]['id']->table);

            $people = $db->select('person')->execute();

            self::assertIsArray($people);
            self::assertCount(1, $people);
            self::assertSame('Tobie', $people[0]['name'] ?? null);

            $backslash = $db->create('trailing\\')->content(['ok' => true])->execute();

            self::assertIsArray($backslash);
            self::assertSame('trailing\\', $backslash[0]['id']->table);
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    private function connect(string $protocol): Surreal
    {
        $db = new Surreal();
        $namespace = $this->namespaceName();
        $database = 'db';

        try {
            $db->connect($this->url($protocol), new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
            ));
            $db->run(sprintf(
                'DEFINE NAMESPACE %s; USE NS %s; DEFINE DATABASE %s;',
                $namespace,
                $namespace,
                $database,
            ));
            $db->use($namespace, $database);
        } catch (\Throwable $error) {
            $this->handleUnavailableDatabase($error);
        }

        return $db;
    }

    private function defineTables(Surreal $db): void
    {
        $db->run(<<<'SURQL'
DEFINE TABLE person PERMISSIONS FULL;
DEFINE TABLE article PERMISSIONS FULL;
DEFINE TABLE likes PERMISSIONS FULL;
SURQL);
    }

    /**
     * @return non-empty-string
     */
    private function namespaceName(): string
    {
        return 'ns_' . self::$databaseCounter++ . '_' . bin2hex(random_bytes(4));
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    private function url(string $protocol): string
    {
        return match ($protocol) {
            'http' => getenv('SURREALDB_HTTP_URL') ?: 'http://127.0.0.1:8000',
            'ws' => getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc',
        };
    }

    private function handleUnavailableDatabase(\Throwable $error): never
    {
        if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
            self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
        }

        self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
    }

    /**
     * @phpstan-assert array{id: mixed} $record
     */
    private function assertRecord(mixed $record, ?string $expectedId = null): void
    {
        self::assertIsArray($record);
        self::assertArrayHasKey('id', $record);

        if ($expectedId !== null) {
            self::assertSame($expectedId, (string) $record['id']);
        }
    }
}
