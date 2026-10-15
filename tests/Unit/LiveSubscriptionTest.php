<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Exceptions\ServerException;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Live\LiveMessage;
use SurrealDB\SDK\Live\LiveSubscription;
use SurrealDB\SDK\Surreal;
use SurrealDB\Tests\Fakes\FakeDuplexTransport;
use function count;

/**
 * Live subscriptions over the WebSocket engine, driven by a fake transport.
 */
final class LiveSubscriptionTest extends TestCase
{
    private const string FIRST = '0191b7ec-1a4c-7a7e-a8f2-4a4c4a7d8f3e';
    private const string SECOND = '0191b7ec-1a4c-7a7e-a8f2-4a4c4a7d8f3f';

    /** @var list<string> */
    private array $kills = [];

    private int $failKills = 0;

    private ?FakeDuplexTransport $transport = null;

    public function testKillCanBeRetriedAfterItFails(): void
    {
        $db = $this->connect();
        $live = $db->liveOf(self::FIRST);
        $this->failKills = 1;

        try {
            $live->kill();
            self::fail('Expected the KILL to fail.');
        } catch (ServerException) {
        }

        self::assertTrue($live->isAlive());

        $live->kill();

        self::assertFalse($live->isAlive());
        self::assertCount(2, $this->kills);
    }

    public function testKilledNotificationEndsTheSubscriptionBeforeItIsIterated(): void
    {
        $db = $this->connect();
        $live = $db->liveOf(self::FIRST);

        // Another call pumps the notification before anything iterates.
        $this->notify(self::FIRST, 'KILLED');
        $db->run('RETURN 1');

        self::assertFalse($live->isAlive());

        $live->kill();

        self::assertSame([], $this->kills, 'No KILL is sent for a query the server already ended.');
    }

    public function testAnAbandonedSubscriptionReleasesItsFeed(): void
    {
        $db = $this->connect();
        $live = $db->liveOf(self::FIRST);
        $feed = \WeakReference::create((fn (): ?iterable => $this->feed)->call($live));

        unset($live);

        self::assertNull($feed->get());

        // The feed unsubscribed, so a later notification is held for the next
        // subscriber rather than queued into the abandoned feed.
        $this->notify(self::FIRST, 'CREATE', ['n' => 1]);
        $db->run('RETURN 1');

        self::assertSame([['n' => 1]], $this->values($db->liveOf(self::FIRST), 1));
    }

    public function testUnclaimedNotificationsEvictTheGloballyOldestFirst(): void
    {
        $db = $this->connect();

        // 1,002 notifications for two queries nobody has subscribed to: the
        // two oldest (first:1, then second:1) are dropped.
        $this->notify(self::FIRST, 'CREATE', ['first' => 1]);

        for ($n = 1; $n <= 999; $n++) {
            $this->notify(self::SECOND, 'CREATE', ['second' => $n]);
        }

        $this->notify(self::FIRST, 'CREATE', ['first' => 2]);
        $this->notify(self::FIRST, 'CREATE', ['first' => 3]);
        $db->run('RETURN 1');

        self::assertSame([['first' => 2], ['first' => 3]], $this->values($db->liveOf(self::FIRST), 2));
        self::assertSame([['second' => 2]], $this->values($db->liveOf(self::SECOND), 1));
    }

    public function testLiveSelectInlinesFieldNamesInsteadOfBindingThem(): void
    {
        $db = $this->connect();

        $fields = $db->live('person')->fields('name', 'pet.kind')->fetch('pet')->compile();
        $value = $db->live('person')->value('na"me')->compile();

        self::assertSame('LIVE SELECT type::fields([ s"name", s"pet.kind" ]) FROM person FETCH type::fields([ s"pet" ])', $fields->query);
        self::assertSame([], $fields->bindings);
        self::assertSame('LIVE SELECT VALUE type::field(s"na\"me") FROM person', $value->query);
        self::assertSame([], $value->bindings);
    }

    private function connect(): Surreal
    {
        $responder = function (string $method, array $params, ?string $id): array {
            if ($method === 'version') {
                return ['id' => $id, 'result' => 'surrealdb-3.0.0'];
            }

            if ($method !== 'query') {
                return ['id' => $id, 'result' => null];
            }

            $sql = (string) ($params[0] ?? '');

            if (str_starts_with($sql, 'KILL')) {
                $this->kills[] = $sql;

                if ($this->failKills > 0) {
                    $this->failKills--;

                    return ['id' => $id, 'result' => [['status' => 'ERR', 'result' => 'The connection dropped']]];
                }
            }

            return ['id' => $id, 'result' => [['status' => 'OK', 'result' => null]]];
        };

        $db = new Surreal(new DriverOptions(
            webSocketTransportFactory: fn (): FakeDuplexTransport => $this->transport = new FakeDuplexTransport($responder),
        ));
        $db->connect('ws://localhost:8000/rpc', new ConnectOptions(namespace: 'test', database: 'test'));

        return $db;
    }

    /**
     * @param array<string,mixed>|null $value
     */
    private function notify(string $id, string $action, ?array $value = null): void
    {
        $this->transport?->pushIncoming(['result' => [
            'id' => $id,
            'action' => $action,
            'record' => 'person:1',
            'result' => $value,
        ]]);
    }

    /**
     * Take the first notifications already queued for a subscription.
     *
     * @return list<mixed>
     */
    private function values(LiveSubscription $live, int $count): array
    {
        $values = [];

        foreach ($live as $message) {
            self::assertInstanceOf(LiveMessage::class, $message);
            self::assertSame(LiveAction::Create, $message->action);
            $values[] = $message->value;

            if (count($values) === $count) {
                break;
            }
        }

        return $values;
    }
}
