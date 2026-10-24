<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\DateTime;
use SurrealDB\SDK\Types\Duration;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Uuid;

/**
 * The SDK sends uuids as CBOR tag 37 (16 raw bytes), datetimes as tag 12 and
 * durations as tag 14, never the text tags SurrealDB 4.0 refuses. A server has
 * to read all three, and a uuid has to still be a uuid on the other side.
 *
 * Local runs skip when no server is available; CI sets SURREALDB_REQUIRE_LOCAL=1
 * so availability is a hard requirement.
 */
final class CborTextTagServerTest extends TestCase
{
    private const string UUID = '09748193-048a-4bfb-b825-8528cf74fdc1';

    /**
     * @return iterable<string,array{protocol: 'http'|'ws'}>
     */
    public static function protocols(): iterable
    {
        yield 'HTTP' => ['protocol' => 'http'];
        yield 'WebSocket' => ['protocol' => 'ws'];
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testTheServerReadsAUuidSentAsTag37(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $uuid = Uuid::fromString(self::UUID);
            $results = $db->run('RETURN $uuid; RETURN <string> $uuid;', ['uuid' => $uuid]);

            self::assertInstanceOf(Uuid::class, $results[0]);
            self::assertTrue($uuid->equals($results[0]));
            self::assertSame(self::UUID, $results[1]);
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testTheServerReadsADateTimeAndADurationSentAsTags12And14(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $at = DateTime::fromString('2024-01-02T03:04:05.123456789Z');
            $for = Duration::fromString('1h30m15s');
            $results = $db->run(
                'RETURN $at; RETURN <string> $at; RETURN $for; RETURN <string> $for;',
                ['at' => $at, 'for' => $for],
            );

            self::assertInstanceOf(DateTime::class, $results[0]);
            self::assertTrue($at->equals($results[0]));
            self::assertSame('2024-01-02T03:04:05.123456789Z', $results[1]);
            self::assertInstanceOf(Duration::class, $results[2]);
            self::assertTrue($for->equals($results[2]));
            self::assertSame('1h30m15s', $results[3]);
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testARecordIdWithAUuidKeyCanBeCreatedAndSelected(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $db->run('DEFINE TABLE item PERMISSIONS FULL;');
            $id = new RecordId('item', Uuid::fromString(self::UUID));

            $created = $db->create($id)->content(['owner' => Uuid::fromString(self::UUID)])->execute();
            $selected = $db->select($id)->execute();

            self::assertIsArray($created);
            self::assertIsArray($selected);
            self::assertInstanceOf(RecordId::class, $selected['id']);
            self::assertTrue($id->equals($selected['id']));
            self::assertInstanceOf(Uuid::class, $selected['owner']);
            self::assertSame(self::UUID, (string) $selected['owner']);
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
        $namespace = 'ns_' . bin2hex(random_bytes(6));

        try {
            $db->connect($this->url($protocol), new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
            ));
            $db->run(sprintf(
                'DEFINE NAMESPACE %s; USE NS %s; DEFINE DATABASE db;',
                $namespace,
                $namespace,
            ));
            $db->use($namespace, 'db');
        } catch (\Throwable $error) {
            if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
                self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
            }

            self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
        }

        return $db;
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
}
