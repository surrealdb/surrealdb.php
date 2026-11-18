<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use Amp\Http\Client\Psr7\PsrHttpClient;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\Exceptions\HttpConnectionException;
use SurrealDB\Scheduler\Amp\RevoltScheduler;
use SurrealDB\Scheduler\Swoole\SwooleScheduler;
use SurrealDB\Scheduler\SyncScheduler;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\AgentMemoryRuntime;
use SurrealDB\Tests\Fakes\RecordingPsr18Client;
use SurrealDB\Tests\Fakes\RecordingScheduler;
use SurrealDB\Tests\Fakes\SequenceHttpConnection;

final class AgentMemoryRuntimeTest extends TestCase
{
    public function testSyncProfileUsesTheBlockingSchedulerAndDiscoveredClient(): void
    {
        $runtime = AgentMemoryRuntime::sync();

        $this->assertInstanceOf(SyncScheduler::class, $runtime->scheduler);
        $this->assertNull($runtime->httpClient);
    }

    public function testSwooleProfileUsesTheCoroutineScheduler(): void
    {
        // Hooks stay off: where the extension is loaded, enabling them would move
        // every later test in this process onto coroutine I/O.
        $runtime = AgentMemoryRuntime::swoole(enableHooks: false);

        $this->assertInstanceOf(SwooleScheduler::class, $runtime->scheduler);
        $this->assertNull($runtime->httpClient);
    }

    public function testAmpProfileUsesTheRevoltSchedulerAndAmpBackedClient(): void
    {
        $runtime = AgentMemoryRuntime::amp();

        $this->assertInstanceOf(RevoltScheduler::class, $runtime->scheduler);
        $this->assertInstanceOf(PsrHttpClient::class, $runtime->httpClient);
    }

    public function testAmpProfileAcceptsAClientOverride(): void
    {
        $client = new RecordingPsr18Client(new Response(200, [], '{}'));

        $this->assertSame($client, AgentMemoryRuntime::amp($client)->httpClient);
    }

    public function testRetryBackOffWaitsOnTheRuntimeScheduler(): void
    {
        $scheduler = new RecordingScheduler();
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('boom', 500, 'Boom', ''),
            new Response(200, [], '{"ok":true}'),
        ]);
        $client = new AgentMemory(
            new AgentMemoryOptions(
                'https://memory.example.com',
                'acme_prod',
                'secret',
                maxRetries: 1,
                runtime: new AgentMemoryRuntime($scheduler),
            ),
            $connection,
        );

        $this->assertTrue($client->state()['ok']);
        $this->assertSame([0.25], $scheduler->delays);
        $this->assertCount(2, $connection->requests);
    }

    public function testLoadSheddingRetryWaitsForTheServersRetryAfter(): void
    {
        $scheduler = new RecordingScheduler();
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('shed', 503, 'Unavailable', '{"message":"busy"}', ['Retry-After' => ['2']]),
            new Response(200, [], '{"ok":true}'),
        ]);
        $client = new AgentMemory(
            new AgentMemoryOptions(
                'https://memory.example.com',
                'acme_prod',
                'secret',
                maxRetries: 1,
                runtime: new AgentMemoryRuntime($scheduler),
            ),
            $connection,
        );

        $this->assertTrue($client->state()['ok']);
        $this->assertSame([2.0], $scheduler->delays);
        $this->assertCount(2, $connection->requests);
    }

    public function testRuntimeHttpClientIsUsedForRequests(): void
    {
        $psrClient = new RecordingPsr18Client(new Response(200, [], '{"ok":true}'));
        $client = new AgentMemory(new AgentMemoryOptions(
            'https://memory.example.com',
            'acme_prod',
            'secret',
            runtime: new AgentMemoryRuntime(new SyncScheduler(), $psrClient),
        ));

        $client->state();

        $this->assertNotNull($psrClient->lastRequest);
        $this->assertSame(
            'https://memory.example.com/api/v1/acme_prod/state',
            (string) $psrClient->lastRequest->getUri(),
        );
        $this->assertSame('Bearer secret', $psrClient->lastRequest->getHeaderLine('Authorization'));
    }

    public function testExplicitHttpClientInOptionsWinsOverTheRuntimeClient(): void
    {
        $runtimeClient = new RecordingPsr18Client(new Response(200, [], '{}'));
        $explicitClient = new RecordingPsr18Client(new Response(200, [], '{}'));
        $client = new AgentMemory(new AgentMemoryOptions(
            'https://memory.example.com',
            'acme_prod',
            'secret',
            runtime: new AgentMemoryRuntime(new SyncScheduler(), $runtimeClient),
            httpClient: $explicitClient,
        ));

        $client->state();

        $this->assertNotNull($explicitClient->lastRequest);
        $this->assertNull($runtimeClient->lastRequest);
    }
}
