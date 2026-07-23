<?php

namespace SurrealDB\Tests\Unit\Spectron;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\Exceptions\HttpConnectionException;
use SurrealDB\Spectron\Auth\ApiKey;
use SurrealDB\Spectron\Enum\InferMode;
use SurrealDB\Spectron\Enum\ScopeView;
use SurrealDB\Spectron\Exceptions\AuthException;
use SurrealDB\Spectron\Exceptions\NotFoundException;
use SurrealDB\Spectron\Exceptions\RateLimitException;
use SurrealDB\Spectron\Exceptions\ScopeException;
use SurrealDB\Spectron\Exceptions\ServerException;
use SurrealDB\Spectron\Exceptions\SpectronException;
use SurrealDB\Spectron\Exceptions\ValidationException;
use SurrealDB\Spectron\Options\AuditOptions;
use SurrealDB\Spectron\Options\ChatOptions;
use SurrealDB\Spectron\Options\ConsolidateOptions;
use SurrealDB\Spectron\Options\ContextOptions;
use SurrealDB\Spectron\Options\ElaborateOptions;
use SurrealDB\Spectron\Options\ForgetOptions;
use SurrealDB\Spectron\Options\InspectOptions;
use SurrealDB\Spectron\Options\RecallOptions;
use SurrealDB\Spectron\Options\RememberManyOptions;
use SurrealDB\Spectron\Options\RememberOptions;
use SurrealDB\Spectron;
use SurrealDB\Spectron\SpectronOptions;
use SurrealDB\Tests\Fakes\FailingHttpConnection;
use SurrealDB\Tests\Fakes\RecordingHttpConnection;

final class SpectronTest extends TestCase
{
    private function client(RecordingHttpConnection $connection, string $context = 'acme-prod'): Spectron
    {
        return new Spectron(
            new SpectronOptions('https://spectron.example.com/', $context, 'secret'),
            $connection,
        );
    }

    public function testRememberPostsToFactsWithBearerAuthAndIdempotencyKey(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"traceId":"tr-1","factsStored":2}'));

        $response = $this->client($connection)->remember('I just got promoted to CTO', new RememberOptions(
            infer: InferMode::FULL,
            sessionId: 's-1',
            scopes: 'user/tobie',
        ));

        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/facts', $connection->url);
        $this->assertSame('POST', $connection->method);
        $this->assertSame('Bearer secret', $connection->headers['Authorization']);
        $this->assertSame('application/json', $connection->headers['Content-Type']);
        $this->assertSame('application/json', $connection->headers['Accept']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $connection->headers['Idempotency-Key']);
        $this->assertSame(
            '{"text":"I just got promoted to CTO","infer":"full","session_id":"s-1","scopes":[["user/tobie"]]}',
            $connection->body,
        );
        $this->assertSame(2, $response['factsStored']);
    }

    public function testRememberManyPostsMessagesToFactsBatch(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));

        $this->client($connection)->rememberMany(
            [['role' => 'user', 'content' => 'I moved to Lisbon']],
            new RememberManyOptions(extract: 'whole_conversation'),
        );

        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/facts/batch', $connection->url);
        $this->assertSame(
            '{"messages":[{"role":"user","content":"I moved to Lisbon"}],"extract":"whole_conversation"}',
            $connection->body,
        );
        $this->assertArrayHasKey('Idempotency-Key', $connection->headers);
    }

    public function testRecallPostsToQueryWithCamelCaseFieldsAndNormalisedLens(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"hits":[]}'));

        $this->client($connection)->recall("What is Tobie's role?", new RecallOptions(
            k: 10,
            sessionId: 's-2',
            lens: [['team/eng', 'org/acme']],
            scopeView: ScopeView::MERGED,
        ));

        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/query', $connection->url);
        $this->assertSame(
            '{"query":"What is Tobie\'s role?","k":10,"sessionId":"s-2","lens":[["team/eng","org/acme"]],"scopeView":"merged"}',
            $connection->body,
        );
        $this->assertArrayNotHasKey('Idempotency-Key', $connection->headers);
    }

    public function testForgetOnlySendsPurgeWhenTrue(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->forget('Remove old project notes');
        $this->assertSame('{"query":"Remove old project notes"}', $connection->body);

        $client->forget('Remove old project notes', new ForgetOptions(purge: true));
        $this->assertSame('{"query":"Remove old project notes","purge":true}', $connection->body);
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/forget', $connection->url);
    }

    public function testChatPostsMessageAndOptionalFlags(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"reply":"hi"}'));

        $response = $this->client($connection)->chat(
            'What do you know about me?',
            new ChatOptions(bypassCache: true),
        );

        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/chat', $connection->url);
        $this->assertSame('{"message":"What do you know about me?","bypassCache":true}', $connection->body);
        $this->assertSame('hi', $response['reply']);
    }

    public function testContextAndReflectMirrorTheJsWireFormat(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->context('Summarise preferences', new ContextOptions(k: 5));
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/context', $connection->url);
        $this->assertSame('{"query":"Summarise preferences","k":5}', $connection->body);

        $client->reflect('What changed this week?');
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/reflect', $connection->url);
        // reflect always sends the persist flag, mirroring the JS client.
        $this->assertSame('{"query":"What changed this week?","persist":false}', $connection->body);
    }

    public function testMaintenanceOperationsPostTheirFlags(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->consolidate(new ConsolidateOptions(dryRun: true, factLimit: 10));
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/consolidate', $connection->url);
        $this->assertSame('{"dryRun":true,"factLimit":10}', $connection->body);

        $client->elaborate(new ElaborateOptions(entityRef: 'person:tobie'));
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/elaborate', $connection->url);
        $this->assertSame('{"entityRef":"person:tobie"}', $connection->body);

        $client->fsck();
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/fsck', $connection->url);
        $this->assertSame('{}', $connection->body);
    }

    public function testInspectAndAuditAreGetRequestsWithQueryParameters(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->inspect('person:tobie', new InspectOptions(asOf: '2026-01-01T00:00:00Z'));
        $this->assertSame('GET', $connection->method);
        $this->assertSame(
            'https://spectron.example.com/api/v1/acme-prod/inspect?ref=person%3Atobie&asOf=2026-01-01T00%3A00%3A00Z',
            $connection->url,
        );
        $this->assertNull($connection->body);

        $client->audit(new AuditOptions(limit: 50));
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/audit?limit=50', $connection->url);
    }

    public function testSnapshotEndpointsUseGet(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->state();
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/state', $connection->url);

        $client->profile();
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/profile', $connection->url);

        $client->whoami();
        $this->assertSame('https://spectron.example.com/api/v1/acme-prod/me', $connection->url);
        $this->assertSame('GET', $connection->method);
    }

    public function testHealthProbesTheUncontextedEndpoint(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"status":"ok"}'));

        $this->client($connection)->health();

        $this->assertSame('https://spectron.example.com/api/v1/health', $connection->url);
        $this->assertSame('GET', $connection->method);
    }

    public function testContextIdIsUrlEncoded(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));

        $this->client($connection, 'acme prod/eu')->state();

        $this->assertSame('https://spectron.example.com/api/v1/acme%20prod%2Feu/state', $connection->url);
    }

    public function testOnBehalfOfAddsDelegationHeaderAndLeavesOriginalUnchanged(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->onBehalfOf('principal:alex')->state();
        $this->assertSame('principal:alex', $connection->headers['X-Spectron-On-Behalf-Of']);

        $client->state();
        $this->assertArrayNotHasKey('X-Spectron-On-Behalf-Of', $connection->headers);
    }

    public function testCustomAuthenticationStrategyIsHonoured(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = new Spectron(
            new SpectronOptions('https://spectron.example.com', 'acme-prod', new ApiKey('k-123', 'Surreal-Api-Key')),
            $connection,
        );

        $client->state();

        $this->assertSame('k-123', $connection->headers['Surreal-Api-Key']);
        $this->assertArrayNotHasKey('Authorization', $connection->headers);
    }

    public function testEmptyRememberEncodesAsJsonObject(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));

        $this->client($connection)->remember();

        $this->assertSame('{}', $connection->body);
    }

    public function testMissingOptionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SpectronOptions('https://spectron.example.com', '', 'secret');
    }

    public function testFailedStatusesMapToTypedExceptions(): void
    {
        $cases = [
            [401, AuthException::class],
            [403, ScopeException::class],
            [404, NotFoundException::class],
            [400, ValidationException::class],
            [422, ValidationException::class],
            [418, SpectronException::class],
        ];

        foreach ($cases as [$status, $exceptionClass]) {
            $connection = new FailingHttpConnection([
                new HttpConnectionException('nope', $status, 'Nope', '{"title":"Nope","detail":"broken"}'),
            ]);
            $client = new Spectron(
                new SpectronOptions('https://spectron.example.com', 'acme-prod', 'secret', maxRetries: 0),
                $connection,
            );

            try {
                $client->state();
                $this->fail("Expected {$exceptionClass} for status {$status}");
            } catch (SpectronException $e) {
                $this->assertSame($exceptionClass, $e::class, "status {$status}");
                $this->assertSame($status, $e->status);
                $this->assertSame('Nope', $e->title);
                $this->assertSame('broken', $e->detail);
            }
        }
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $connection = new FailingHttpConnection([
            new HttpConnectionException('slow down', 429, 'Too Many', '{"title":"Too Many"}', ['Retry-After' => ['12']]),
        ]);
        $client = new Spectron(
            new SpectronOptions('https://spectron.example.com', 'acme-prod', 'secret', maxRetries: 0),
            $connection,
        );

        try {
            $client->state();
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(12, $e->retryAfter);
        }
    }

    public function testIdempotentGetIsRetriedOnServerErrors(): void
    {
        $connection = new FailingHttpConnection(
            [new HttpConnectionException('boom', 500, 'Boom', '')],
            new Response(200, [], '{"ok":true}'),
        );
        $client = new Spectron(
            new SpectronOptions('https://spectron.example.com', 'acme-prod', 'secret', maxRetries: 1),
            $connection,
        );

        $this->assertTrue($client->state()['ok']);
        $this->assertSame(2, $connection->attempts);
    }

    public function testNonIdempotentWritesAreNotRetried(): void
    {
        $connection = new FailingHttpConnection(
            [new HttpConnectionException('boom', 500, 'Boom', '')],
            new Response(200, [], '{}'),
        );
        $client = new Spectron(
            new SpectronOptions('https://spectron.example.com', 'acme-prod', 'secret', maxRetries: 3),
            $connection,
        );

        $this->expectException(ServerException::class);

        try {
            $client->forget('anything');
        } finally {
            $this->assertSame(1, $connection->attempts);
        }
    }

    public function testNonJsonResponseThrowsSpectronException(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], 'not json'));

        $this->expectException(SpectronException::class);

        $this->client($connection)->state();
    }

    public function testChatStreamYieldsChunksUntilDone(): void
    {
        $sse = "data: {\"delta\":\"Hel\"}\n\n"
            . ": keep-alive\n\n"
            . "data: {\"delta\":\"lo\",\"traceId\":\"tr-9\"}\n\n"
            . "data: [DONE]\n\n";
        $connection = new RecordingHttpConnection(new Response(200, [], $sse));

        $chunks = iterator_to_array($this->client($connection)->chatStream('Tell me a story'), false);

        $this->assertSame('{"message":"Tell me a story","stream":true}', $connection->body);
        $this->assertSame('text/event-stream', $connection->headers['Accept']);
        $this->assertCount(3, $chunks);
        $this->assertSame('Hel', $chunks[0]->delta);
        $this->assertSame('lo', $chunks[1]->delta);
        $this->assertSame('tr-9', $chunks[1]->traceId);
        $this->assertTrue($chunks[2]->done);
    }
}
