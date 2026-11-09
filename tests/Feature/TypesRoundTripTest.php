<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\Duration;

/**
 * Value types sent to and read back from a real server, over HTTP and
 * WebSocket.
 */
final class TypesRoundTripTest extends TestCase
{
    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testServerDurationsLongerThan292YearsDecode(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            [$durations] = $db->run('RETURN [300y, 600y, 10000000000000000000ns, 292471208677y27w6d15h30m7s999ms999us999ns]');

            self::assertIsArray($durations);
            self::assertContainsOnlyInstancesOf(Duration::class, $durations);
            self::assertSame(
                ['300y', '600y', '317y5w17h46m40s', '292471208677y27w6d15h30m7s999ms999us999ns'],
                array_map(strval(...), $durations),
            );
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testDurationsLongerThan292YearsReachTheServer(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            [$result] = $db->run('RETURN [$d == 300y, <string> $d, $d]', ['d' => Duration::years(300)]);

            self::assertIsArray($result);
            self::assertTrue($result[0], 'The server did not read the duration as 300y');
            self::assertSame('300y', $result[1]);
            self::assertTrue(Duration::years(300)->equals($result[2]));
        } finally {
            $db->close();
        }
    }

    /**
     * @return iterable<string, array{'http'|'ws'}>
     */
    public static function protocols(): iterable
    {
        yield 'HTTP' => ['http'];
        yield 'WebSocket' => ['ws'];
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    private function connect(string $protocol): Surreal
    {
        $url = match ($protocol) {
            'http' => getenv('SURREALDB_HTTP_URL') ?: 'http://127.0.0.1:8000',
            'ws' => getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc',
        };

        $db = new Surreal();
        $namespace = 'types_' . bin2hex(random_bytes(4));

        try {
            $db->connect($url, new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
            ));
            $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
            $db->use($namespace, 'db');
        } catch (\Throwable $error) {
            $this->unavailable($error);
        }

        return $db;
    }

    private function unavailable(\Throwable $error): never
    {
        if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
            self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
        }

        self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
    }
}
