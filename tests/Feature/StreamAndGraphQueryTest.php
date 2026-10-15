<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Exceptions\GraphQlException;
use SurrealDB\SDK\Exceptions\ServerException;
use SurrealDB\SDK\Query\Frame\DoneFrame;
use SurrealDB\SDK\Query\Frame\ErrorFrame;
use SurrealDB\SDK\Query\Frame\ValueFrame;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;

/**
 * Streamed query frames, ISO GQL and GraphQL against a real server, over
 * both WebSocket and HTTP.
 */
final class StreamAndGraphQueryTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function protocols(): iterable
    {
        yield 'ws' => [getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc'];
        yield 'http' => [getenv('SURREALDB_HTTP_URL') ?: 'http://127.0.0.1:8000'];
    }

    #[DataProvider('protocols')]
    public function testStatementsStreamAsFrames(string $url): void
    {
        $db = $this->connect($url);

        try {
            $frames = iterator_to_array($db->statements(
                'CREATE person:a SET n = 1; CREATE person:b SET n = 2; SELECT n FROM person ORDER BY n; SELECT * FROM missing_table; RETURN $x',
                ['x' => 'last'],
            )->stream(), false);

            $summary = array_map(static fn ($frame): string => match (true) {
                $frame instanceof ValueFrame => "value {$frame->query}" . ($frame->single ? ' single' : ''),
                $frame instanceof ErrorFrame => "error {$frame->query}",
                $frame instanceof DoneFrame => "done {$frame->query}",
            }, $frames);

            // CREATE returns a list of one record; SELECT a row per record.
            self::assertSame([
                'value 0', 'done 0',
                'value 1', 'done 1',
                'value 2', 'value 2', 'done 2',
                'error 3',
                'value 4 single', 'done 4',
            ], $summary);
            self::assertSame(['n' => 2], $frames[5]->value);
            self::assertInstanceOf(ServerException::class, $frames[7]->error);
            self::assertSame('last', $frames[8]->value);
            self::assertNotNull($frames[9]->time);
        } finally {
            $db->close();
        }
    }

    #[DataProvider('protocols')]
    public function testBuildersStreamAndReportResponses(string $url): void
    {
        $db = $this->connect($url);

        try {
            $db->create(new RecordId('person', 'tobie'))->content(['name' => 'Tobie'])->execute();

            $values = [];

            foreach ($db->select(new Table('person'))->json()->stream() as $frame) {
                if ($frame instanceof ValueFrame) {
                    $values[] = $frame->value;
                }
            }

            self::assertSame([['id' => 'person:tobie', 'name' => 'Tobie']], $values);

            [$ok, $failed] = $db->statements('RETURN 1; THROW "boom"')->responses();

            self::assertTrue($ok->success);
            self::assertSame(1, $ok->result);
            self::assertFalse($failed->success);
            self::assertStringContainsString('boom', $failed->error?->getMessage() ?? '');
        } finally {
            $db->close();
        }
    }

    #[DataProvider('protocols')]
    public function testGqlRunsGraphPatternQueries(string $url): void
    {
        $db = $this->connect($url);

        try {
            $db->run('CREATE person:a SET name = "A"; CREATE person:b SET name = "B"; RELATE person:a->knows->person:b');

            self::assertSame(
                [[['friend' => 'B']]],
                $db->gql('MATCH (a:person)-[:knows]->(b:person) WHERE a.name = $name RETURN b.name AS friend', ['name' => 'A']),
            );
        } finally {
            $db->close();
        }
    }

    public function testGqlRunsInsideATransaction(): void
    {
        $db = $this->connect(getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc');

        try {
            $db->run('DEFINE TABLE person SCHEMALESS');
            $txn = $db->beginTransaction();
            $txn->run('CREATE person:t SET name = "T"');

            self::assertSame([[['name' => 'T']]], $txn->gql('MATCH (p:person) RETURN p.name AS name'));
            self::assertSame([[]], $db->gql('MATCH (p:person) RETURN p.name AS name'));

            $txn->cancel();
        } finally {
            $db->close();
        }
    }

    #[DataProvider('protocols')]
    public function testGraphqlReturnsTheResponseEnvelope(string $url): void
    {
        $db = $this->connect($url);

        try {
            $db->run('DEFINE CONFIG GRAPHQL AUTO; DEFINE TABLE person SCHEMAFULL; DEFINE FIELD name ON person TYPE string; CREATE person:a SET name = "A"');

            $response = $db->graphql('query Person($id: ID!) { person(id: $id) { name } }', ['id' => 'a'], 'Person');

            self::assertFalse($response->hasErrors());
            self::assertSame(['person' => ['name' => 'A']], $response->dataOrThrow());

            $invalid = $db->graphql('{ nonexistent }');

            self::assertTrue($invalid->hasErrors());
            $this->expectException(GraphQlException::class);
            $invalid->dataOrThrow();
        } finally {
            $db->close();
        }
    }

    private function connect(string $url): Surreal
    {
        $db = new Surreal();
        $namespace = 'stream_' . bin2hex(random_bytes(4));

        try {
            $db->connect($url, new ConnectOptions(authentication: new RootAuth('root', 'root')));
            $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
            $db->use($namespace, 'db');
        } catch (\Throwable $error) {
            if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
                self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
            }

            self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
        }

        return $db;
    }
}
