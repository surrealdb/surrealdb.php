<?php

namespace SurrealDB\Tests\Unit\Spectron;

use Amp\Http\Client\Psr7\PsrHttpClient;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\Exceptions\HttpConnectionException;
use SurrealDB\Scheduler\Amp\RevoltScheduler;
use SurrealDB\Scheduler\Swoole\SwooleScheduler;
use SurrealDB\Scheduler\SyncScheduler;
use SurrealDB\Spectron;
use SurrealDB\Spectron\SpectronOptions;
use SurrealDB\Spectron\SpectronRuntime;
use SurrealDB\Tests\Fakes\FailingHttpConnection;
use SurrealDB\Tests\Fakes\RecordingPsr18Client;
use SurrealDB\Tests\Fakes\RecordingScheduler;

final class SpectronRuntimeTest extends TestCase
{
    public function testSyncProfileUsesTheBlockingSchedulerAndDiscoveredClient(): void
    {
        $runtime = SpectronRuntime::sync();

        $this->assertInstanceOf(SyncScheduler::class, $runtime->scheduler);
        $this->assertNull($runtime->httpClient);
    }

    public function testSwooleProfileUsesTheCoroutineScheduler(): void
    {
        $runtime = SpectronRuntime::swoole();

        $this->assertInstanceOf(SwooleScheduler::class, $runtime->scheduler);
        $this->assertNull($runtime->httpClient);
    }

    public function testAmpProfileUsesTheRevoltSchedulerAndAmpBackedClient(): void
    {
        $runtime = SpectronRuntime::amp();

        $this->assertInstanceOf(RevoltScheduler::class, $runtime->scheduler);
        $this->assertInstanceOf(PsrHttpClient::class, $runtime->httpClient);
    }

    public function testAmpProfileAcceptsAClientOverride(): void
    {
        $client = new RecordingPsr18Client(new Response(200, [], '{}'));

        $this->assertSame($client, SpectronRuntime::amp($client)->httpClient);
    }

    public function testRetryBackOffWaitsOnTheRuntimeScheduler(): void
    {
        $scheduler = new RecordingScheduler();
        $connection = new FailingHttpConnection(
            [new HttpConnectionException('boom', 500, 'Boom', '')],
            new Response(200, [], '{"ok":true}'),
        );
        $client = new Spectron(
            new SpectronOptions(
                'https://spectron.example.com',
                'acme-prod',
                'secret',
                maxRetries: 1,
                runtime: new SpectronRuntime($scheduler),
            ),
            $connection,
        );

        $this->assertTrue($client->state()['ok']);
        $this->assertSame([0.25], $scheduler->delays);
        $this->assertSame(2, $connection->attempts);
    }

    public function testRuntimeHttpClientIsUsedForRequests(): void
    {
        $psrClient = new RecordingPsr18Client(new Response(200, [], '{"ok":true}'));
        $client = new Spectron(new SpectronOptions(
            'https://spectron.example.com',
            'acme-prod',
            'secret',
            runtime: new SpectronRuntime(new SyncScheduler(), $psrClient),
        ));

        $client->state();

        $this->assertNotNull($psrClient->lastRequest);
        $this->assertSame(
            'https://spectron.example.com/api/v1/acme-prod/state',
            (string) $psrClient->lastRequest->getUri(),
        );
        $this->assertSame('Bearer secret', $psrClient->lastRequest->getHeaderLine('Authorization'));
    }

    public function testExplicitHttpClientInOptionsWinsOverTheRuntimeClient(): void
    {
        $runtimeClient = new RecordingPsr18Client(new Response(200, [], '{}'));
        $explicitClient = new RecordingPsr18Client(new Response(200, [], '{}'));
        $client = new Spectron(new SpectronOptions(
            'https://spectron.example.com',
            'acme-prod',
            'secret',
            runtime: new SpectronRuntime(new SyncScheduler(), $runtimeClient),
            httpClient: $explicitClient,
        ));

        $client->state();

        $this->assertNotNull($explicitClient->lastRequest);
        $this->assertNull($runtimeClient->lastRequest);
    }
}
