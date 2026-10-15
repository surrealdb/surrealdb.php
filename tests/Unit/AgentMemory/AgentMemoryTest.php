<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\AgentMemoryRuntime;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Enum\BatchExtractionMode;
use SurrealDB\AgentMemory\Enum\InferMode;
use SurrealDB\AgentMemory\Enum\LookupSection;
use SurrealDB\AgentMemory\Enum\MemoryQueryMode;
use SurrealDB\AgentMemory\Enum\ResultFamily;
use SurrealDB\AgentMemory\Enum\ScopeView;
use SurrealDB\AgentMemory\Enum\TraceKind;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\AuthException;
use SurrealDB\AgentMemory\Exceptions\ConflictException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Exceptions\NotFoundException;
use SurrealDB\AgentMemory\Exceptions\RateLimitException;
use SurrealDB\AgentMemory\Exceptions\ScopeException;
use SurrealDB\AgentMemory\Exceptions\ServerException;
use SurrealDB\AgentMemory\Exceptions\UnsupportedMediaTypeException;
use SurrealDB\AgentMemory\Exceptions\ValidationException;
use SurrealDB\AgentMemory\Message;
use SurrealDB\AgentMemory\Options\AuditOptions;
use SurrealDB\AgentMemory\Options\ChatOptions;
use SurrealDB\AgentMemory\Options\ConsolidateOptions;
use SurrealDB\AgentMemory\Options\ContextOptions;
use SurrealDB\AgentMemory\Options\DocumentQueryOptions;
use SurrealDB\AgentMemory\Options\ElaborateOptions;
use SurrealDB\AgentMemory\Options\ForgetOptions;
use SurrealDB\AgentMemory\Options\InspectOptions;
use SurrealDB\AgentMemory\Options\KeywordSearchOptions;
use SurrealDB\AgentMemory\Options\LookupOptions;
use SurrealDB\AgentMemory\Options\RecallOptions;
use SurrealDB\AgentMemory\Options\RememberManyOptions;
use SurrealDB\AgentMemory\Options\RememberOptions;
use SurrealDB\AgentMemory\Options\StateOptions;
use SurrealDB\AgentMemory\Transport;
use SurrealDB\AgentMemory\Triple;
use SurrealDB\AgentMemory\UserAgent;
use SurrealDB\Exceptions\HttpConnectionException;
use SurrealDB\Exceptions\InvalidValueException;
use SurrealDB\Exceptions\SurrealExceptionInterface;
use SurrealDB\Tests\Fakes\RecordingHttpConnection;
use SurrealDB\Tests\Fakes\RecordingScheduler;
use SurrealDB\Tests\Fakes\SequenceHttpConnection;
use SurrealDB\Types\Duration;

final class AgentMemoryTest extends TestCase
{
    private const string BASE = 'https://memory.example.com/api/v1/acme_prod';

    private function client(RecordingHttpConnection $connection, string $context = 'acme_prod'): AgentMemory
    {
        return new AgentMemory(
            new AgentMemoryOptions('https://memory.example.com/', $context, 'secret'),
            $connection,
        );
    }

    private function retryingClient(SequenceHttpConnection $connection, int $maxRetries = 3): AgentMemory
    {
        return new AgentMemory(
            new AgentMemoryOptions(
                'https://memory.example.com',
                'acme_prod',
                'secret',
                maxRetries: $maxRetries,
                runtime: new AgentMemoryRuntime(new RecordingScheduler()),
            ),
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

        $this->assertSame(self::BASE . '/facts', $connection->url);
        $this->assertSame('POST', $connection->method);
        $this->assertSame('Bearer secret', $connection->headers['Authorization']);
        $this->assertSame('application/json', $connection->headers['Content-Type']);
        $this->assertSame('application/json', $connection->headers['Accept']);
        $this->assertSame(UserAgent::value(), $connection->headers['User-Agent']);
        $this->assertMatchesRegularExpression('#^surrealdb-memory-php/[0-9A-Za-z.+_-]+$#', $connection->headers['User-Agent']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $connection->headers['Idempotency-Key']);
        $this->assertSame(
            '{"text":"I just got promoted to CTO","infer":"full","session_id":"s-1","scopes":[["user/tobie"]]}',
            $connection->body,
        );
        $this->assertSame(2, $response['factsStored']);
    }

    public function testRememberSendsObservedTimeAddresseesAndTypedTriples(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));

        $this->client($connection)->remember(null, new RememberOptions(
            infer: InferMode::TRIPLES,
            triples: [
                Triple::attribute('person/alice', 'role', 'CTO'),
                ['entity' => ['type' => 'person', 'name' => 'bob'], 'key' => 'city', 'value' => 'Lisbon'],
            ],
            observedAt: '2026-09-01T00:00:00Z',
            addressees: ['Alice'],
        ));

        $this->assertSame(
            '{"infer":"triples","triples":[{"entity":{"type":"person","name":"alice"},"key":"role","value":"CTO"},'
            . '{"entity":{"type":"person","name":"bob"},"key":"city","value":"Lisbon"}],'
            . '"observed_at":"2026-09-01T00:00:00Z","addressees":["Alice"]}',
            $connection->body,
        );
    }

    public function testRememberManyPostsMessagesToFactsBatch(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));

        $this->client($connection)->rememberMany(
            [
                Message::user('I moved to Lisbon', addressees: ['Sam']),
                ['role' => 'assistant', 'content' => 'Congratulations!'],
            ],
            new RememberManyOptions(extract: BatchExtractionMode::PER_MESSAGE),
        );

        $this->assertSame(self::BASE . '/facts/batch', $connection->url);
        $this->assertSame(
            '{"messages":[{"role":"user","content":"I moved to Lisbon","addressees":["Sam"]},'
            . '{"role":"assistant","content":"Congratulations!"}],"extract":"per_message"}',
            $connection->body,
        );
        $this->assertArrayHasKey('Idempotency-Key', $connection->headers);
    }

    public function testRecallPostsToQueryWithCamelCaseFieldsAndNormalisedLens(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"hits":[]}'));

        $this->client($connection)->recall("What is Tobie's role?", new RecallOptions(
            k: 10,
            mode: MemoryQueryMode::GRAPH,
            sessionId: 's-2',
            include: [ResultFamily::FACTS],
            lens: [['team/eng', 'org/acme']],
            scopeView: ScopeView::MERGED,
            includeDuplicates: true,
        ));

        $this->assertSame(self::BASE . '/query', $connection->url);
        $this->assertSame(
            '{"query":"What is Tobie\'s role?","k":10,"mode":"graph","sessionId":"s-2","include":["facts"],'
            . '"lens":[["team/eng","org/acme"]],"scopeView":"merged","includeDuplicates":true}',
            $connection->body,
        );
        // A read-only POST, so it is retried like a read and carries a key, as the JS client sends.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $connection->headers['Idempotency-Key']);
    }

    public function testLookupPostsTheQueryWithSubjectAndSections(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"resolution":{"kind":"entity"}}'));

        $answer = $this->client($connection)->lookup('alice', new LookupOptions(
            subject: new EntityRef('person', 'alice'),
            include: [LookupSection::FACTS, LookupSection::EVENTS, 'uncertainty'],
            ambiguityMargin: 0.2,
            factLimit: 4,
        ));

        $this->assertSame(self::BASE . '/lookup', $connection->url);
        $this->assertSame('POST', $connection->method);
        $this->assertSame(
            '{"query":"alice","subject":"person/alice","include":["facts","events","uncertainty"],"ambiguityMargin":0.2,"factLimit":4}',
            $connection->body,
        );
        $this->assertSame('entity', $answer['resolution']['kind']);
    }

    public function testLookupAcceptsABlankQueryForAKnownSubject(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"resolution":{"kind":"entity"}}'));

        // The server only needs a query to resolve one; a subject skips resolution.
        $this->client($connection)->lookup('', new LookupOptions(subject: 'person/alice'));

        $this->assertSame('{"query":"","subject":"person/alice"}', $connection->body);
    }

    public function testForgetOnlySendsFlagsWhenTrue(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"deleted":0}'));
        $client = $this->client($connection);

        $client->forget('Remove old project notes');
        $this->assertSame('{"query":"Remove old project notes"}', $connection->body);

        $client->forget('Remove old project notes', new ForgetOptions(purge: true));
        $this->assertSame('{"query":"Remove old project notes","purge":true}', $connection->body);

        $client->forget('Remove old project notes', new ForgetOptions(dryRun: true));
        $this->assertSame('{"query":"Remove old project notes","dryRun":true}', $connection->body);
        $this->assertSame(self::BASE . '/forget', $connection->url);
    }

    public function testChatPostsMessageAndOptionalFlags(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"reply":"hi"}'));

        $response = $this->client($connection)->chat(
            'What do you know about me?',
            new ChatOptions(bypassCache: true, suppressMarkers: true),
        );

        $this->assertSame(self::BASE . '/chat', $connection->url);
        $this->assertSame('{"message":"What do you know about me?","bypassCache":true,"suppressMarkers":true}', $connection->body);
        $this->assertSame('hi', $response['reply']);
    }

    public function testContextAndReflectMirrorTheWireFormat(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->context('Summarise preferences', new ContextOptions(k: 5, subject: 'person/alice'));
        $this->assertSame(self::BASE . '/context', $connection->url);
        $this->assertSame('{"query":"Summarise preferences","k":5,"subject":"person/alice"}', $connection->body);

        $client->reflect('What changed this week?');
        $this->assertSame(self::BASE . '/reflect', $connection->url);
        // reflect always sends the persist flag, mirroring the JS client.
        $this->assertSame('{"query":"What changed this week?","persist":false}', $connection->body);
    }

    public function testMaintenanceOperationsPostTheirFlags(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->consolidate(new ConsolidateOptions(dryRun: true, factLimit: 10));
        $this->assertSame(self::BASE . '/consolidate', $connection->url);
        $this->assertSame('{"dryRun":true,"factLimit":10}', $connection->body);

        $client->elaborate(new ElaborateOptions(entityRef: new EntityRef('person', 'tobie')));
        $this->assertSame(self::BASE . '/elaborate', $connection->url);
        $this->assertSame('{"entityRef":"person/tobie"}', $connection->body);

        $client->elaborate(new ElaborateOptions(sweep: true, dryRun: true));
        $this->assertSame('{"sweep":true,"dryRun":true}', $connection->body);

        $client->fsck();
        $this->assertSame(self::BASE . '/fsck', $connection->url);
        $this->assertSame('{}', $connection->body);
    }

    public function testInspectIsAGetWithTheKindTaggedRef(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));

        $this->client($connection)->inspect('entity:person/tobie', new InspectOptions(asOf: '2026-01-01T00:00:00Z'));

        $this->assertSame('GET', $connection->method);
        $this->assertSame(
            self::BASE . '/inspect?ref=entity%3Aperson%2Ftobie&asOf=2026-01-01T00%3A00%3A00Z',
            $connection->url,
        );
        $this->assertNull($connection->body);

        // An EntityRef is sent in its kind-tagged form.
        $this->client($connection)->inspect(new EntityRef('person', 'tobie'));
        $this->assertSame(self::BASE . '/inspect?ref=entity%3Aperson%2Ftobie', $connection->url);
    }

    public function testAuditReturnsAPageOfRows(): void
    {
        $connection = new RecordingHttpConnection(new Response(
            200,
            [],
            '{"rows":[{"traceId":"tr-1","kind":"decision"}],"page":{"hasMore":true,"nextCursor":"next"}}',
        ));

        $page = $this->client($connection)->audit(new AuditOptions(kind: TraceKind::DECISION, limit: 50, cursor: 'c1'));

        $this->assertSame(self::BASE . '/audit?kind=decision&limit=50&cursor=c1', $connection->url);
        $this->assertSame([['traceId' => 'tr-1', 'kind' => 'decision']], $page->items);
        $this->assertSame('next', $page->nextCursor);
    }

    public function testSnapshotEndpointsUseGet(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->state();
        $this->assertSame(self::BASE . '/state', $connection->url);

        $client->state(new StateOptions(limit: 2));
        $this->assertSame(self::BASE . '/state?limit=2', $connection->url);

        $client->profile();
        $this->assertSame(self::BASE . '/profile', $connection->url);

        $client->whoami();
        $this->assertSame(self::BASE . '/me', $connection->url);
        $this->assertSame('GET', $connection->method);
    }

    public function testHealthProbesTheUncontextedEndpoint(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], ''));

        $this->client($connection)->health();

        $this->assertSame('https://memory.example.com/api/v1/health', $connection->url);
        $this->assertSame('GET', $connection->method);
    }

    public function testContextIdIsUrlEncoded(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));

        $this->client($connection, 'acme prod/eu')->state();

        $this->assertSame('https://memory.example.com/api/v1/acme%20prod%2Feu/state', $connection->url);
    }

    public function testOnBehalfOfAddsTheDelegationHeaderAndLeavesTheOriginalUnchanged(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);

        $client->onBehalfOf('principal:alex')->state();
        // The wire header keeps its pre-rebrand name; the server reads exactly this.
        $this->assertSame('principal:alex', $connection->headers['X-Spectron-On-Behalf-Of']);

        $client->state();
        $this->assertArrayNotHasKey('X-Spectron-On-Behalf-Of', $connection->headers);
    }

    public function testTheApiKeyIsRedactedFromDebugOutput(): void
    {
        // No request is sent: the recording fake would otherwise hold the headers.
        $client = new AgentMemory(
            new AgentMemoryOptions('https://memory.example.com', 'acme_prod', 'sp-k1-topsecret'),
            new RecordingHttpConnection(new Response(200, [], '{}')),
        );

        foreach (['client' => $client, 'delegated client' => $client->onBehalfOf('alex')] as $name => $subject) {
            ob_start();
            var_dump($subject);
            $dumps = ['print_r' => print_r($subject, true), 'var_dump' => (string) ob_get_clean()];

            foreach ($dumps as $function => $output) {
                $this->assertStringNotContainsString('topsecret', $output, "{$function} of the {$name}");
                $this->assertStringContainsString('[redacted]', $output, "{$function} of the {$name}");
            }
        }
    }

    public function testTheApiKeyParametersAreSensitive(): void
    {
        // Pinned through reflection, so the check holds whatever zend.exception_ignore_args is set to.
        $parameters = [
            'AgentMemoryOptions::__construct $apiKey' => new \ReflectionParameter([AgentMemoryOptions::class, '__construct'], 'apiKey'),
            'Transport::__construct $apiKey' => new \ReflectionParameter([Transport::class, '__construct'], 'apiKey'),
            'Transport::send $headers' => new \ReflectionParameter([Transport::class, 'send'], 'headers'),
        ];

        foreach ($parameters as $name => $parameter) {
            $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class), $name);
        }
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

        new AgentMemoryOptions('https://memory.example.com', '', 'secret');
    }

    public function testOneCatchCoversEverySdkException(): void
    {
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('nope', 404, 'Nope', '{"message":"not found"}'),
        ]);
        $client = $this->retryingClient($connection, maxRetries: 0);
        $cases = [
            NotFoundException::class => static fn () => $client->state(),
            InvalidArgumentException::class => static fn () => $client->recall(' '),
            InvalidValueException::class => static fn () => Duration::fromString('nope'),
        ];

        foreach ($cases as $exceptionClass => $call) {
            try {
                $call();
                $this->fail("Expected {$exceptionClass}");
            } catch (SurrealExceptionInterface $e) {
                $this->assertSame($exceptionClass, $e::class);
            }
        }

        // Argument errors still satisfy a catch written for PHP's own class.
        $this->assertInstanceOf(\InvalidArgumentException::class, new InvalidArgumentException('x'));
    }

    public function testBlankQueriesAndMessagesAreRejectedBeforeAnyRequest(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);
        $options = new AgentMemoryOptions('https://memory.example.com', 'acme_prod', 'secret');
        $calls = [
            'recall' => static fn () => $client->recall(' '),
            'lookup without a subject' => static fn () => $client->lookup(''),
            'forget' => static fn () => $client->forget("\t"),
            'chat' => static fn () => $client->chat(' '),
            // Raised by the call itself; the generator is never iterated.
            'chatStream' => static fn () => $client->chatStream(' '),
            'context' => static fn () => $client->context(' '),
            'reflect' => static fn () => $client->reflect(' '),
            // The server ignores a blank delegation target and runs the request as the caller.
            'empty onBehalfOf' => static fn () => $client->onBehalfOf(''),
            'blank onBehalfOf' => static fn () => $client->onBehalfOf('  '),
            'bare principal: prefix' => static fn () => $client->onBehalfOf('principal:'),
            'blank principal id' => static fn () => $client->onBehalfOf('principal: '),
            'blank constructor onBehalfOf' => static fn () => new AgentMemory($options, $connection, ' '),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("A blank {$name} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(InvalidArgumentException::class, $e::class, $name);
            }
        }

        $this->assertNull($connection->url, 'no request may be sent');
    }

    public function testControlCharactersInHeaderValuesAreRejectedBeforeAnyRequest(): void
    {
        // PSR-7 would otherwise throw its own \InvalidArgumentException mid-request.
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);
        $calls = [
            'onBehalfOf with CRLF' => static fn () => $client->onBehalfOf("alex\r\nX-Injected: 1"),
            'onBehalfOf with a trailing LF' => static fn () => $client->onBehalfOf("alex\n"),
            'onBehalfOf with NUL' => static fn () => $client->onBehalfOf("al\0ex"),
            'API key with LF' => static fn () => new AgentMemory(
                new AgentMemoryOptions('https://memory.example.com', 'acme_prod', "sp-key\nX-Injected: 1"),
                $connection,
            ),
            'API key with DEL' => static fn () => new AgentMemory(
                new AgentMemoryOptions('https://memory.example.com', 'acme_prod', "sp-key\x7F"),
                $connection,
            ),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(InvalidArgumentException::class, $e::class, $name);
                $this->assertStringNotContainsString('sp-key', $e->getMessage(), 'the key must not leak into the message');
            }
        }

        $this->assertNull($connection->url, 'no request may be sent');
    }

    public function testFailedStatusesMapToTypedExceptions(): void
    {
        $cases = [
            [400, ValidationException::class],
            [401, AuthException::class],
            [403, ScopeException::class],
            [404, NotFoundException::class],
            [409, ConflictException::class],
            [415, UnsupportedMediaTypeException::class],
            [422, ValidationException::class],
            [429, RateLimitException::class],
            [500, ServerException::class],
            [501, ServerException::class],
            [503, ServerException::class],
            [418, AgentMemoryException::class],
        ];

        foreach ($cases as [$status, $exceptionClass]) {
            // The API reports every error as {"message": "..."}.
            $connection = new SequenceHttpConnection([
                new HttpConnectionException('nope', $status, 'Nope', '{"message":"provide entity_ref or set sweep=true"}'),
            ]);

            try {
                $this->retryingClient($connection, maxRetries: 0)->state();
                $this->fail("Expected {$exceptionClass} for status {$status}");
            } catch (AgentMemoryException $e) {
                $this->assertSame($exceptionClass, $e::class, "status {$status}");
                $this->assertSame($status, $e->status);
                $this->assertSame('provide entity_ref or set sweep=true', $e->title);
                $this->assertSame("[{$status}] provide entity_ref or set sweep=true", $e->getMessage());
            }
        }
    }

    public function testProblemDetailsBodiesAreStillParsed(): void
    {
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('nope', 404, 'Nope', '{"title":"Nope","detail":"broken","traceId":"tr-1"}'),
        ]);

        try {
            $this->retryingClient($connection)->state();
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('Nope', $e->title);
            $this->assertSame('broken', $e->detail);
            $this->assertSame(['traceId' => 'tr-1'], $e->extensions);
        }
    }

    public function testThrottlingAndLoadSheddingCarryRetryAfter(): void
    {
        foreach ([429 => RateLimitException::class, 503 => ServerException::class] as $status => $exceptionClass) {
            $connection = new SequenceHttpConnection([
                new HttpConnectionException('slow down', $status, 'Busy', '{"message":"busy"}', ['Retry-After' => ['12']]),
            ]);

            try {
                $this->retryingClient($connection, maxRetries: 0)->state();
                $this->fail("Expected {$exceptionClass}");
            } catch (AgentMemoryException $e) {
                $this->assertSame($exceptionClass, $e::class);
                $this->assertSame(12, $e->retryAfter);
            }
        }
    }

    public function testIdempotentGetIsRetriedOnServerErrors(): void
    {
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('boom', 500, 'Boom', ''),
            new Response(200, [], '{"ok":true}'),
        ]);

        $this->assertTrue($this->retryingClient($connection, maxRetries: 1)->state()['ok']);
        $this->assertCount(2, $connection->requests);
    }

    public function testLoadSheddingIsRetriedForEveryRequest(): void
    {
        // A 503 promises no work was done, so even a non-idempotent write is retried.
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('shed', 503, 'Unavailable', '{"message":"busy"}', ['Retry-After' => ['1']]),
            new Response(200, [], '{"deleted":0}'),
        ]);

        $this->assertSame(['deleted' => 0], $this->retryingClient($connection)->forget('anything'));
        $this->assertSame(['POST', 'POST'], array_column($connection->requests, 'method'));
        $this->assertSame($connection->requests[0]['body'], $connection->requests[1]['body']);
        $this->assertArrayNotHasKey('Idempotency-Key', $connection->requests[0]['headers']);
    }

    public function testReadOnlyPostsAreRetriedWithAStableIdempotencyKey(): void
    {
        $calls = [
            'recall' => static fn (AgentMemory $m) => $m->recall('role?'),
            'lookup' => static fn (AgentMemory $m) => $m->lookup('alice'),
            'context' => static fn (AgentMemory $m) => $m->context('role?'),
            'documents->query' => static fn (AgentMemory $m) => $m->documents->query(new DocumentQueryOptions('rust')),
            'documents->keywords->search' => static fn (AgentMemory $m) => $m->documents->keywords->search(new KeywordSearchOptions('rust')),
            'session->context' => static fn (AgentMemory $m) => $m->sessions->attach('s1')->context('role?'),
        ];

        foreach ($calls as $name => $call) {
            $connection = new SequenceHttpConnection([
                new HttpConnectionException('boom', 500, 'Boom', ''),
                new Response(200, [], '{}'),
            ]);

            $call($this->retryingClient($connection));

            $this->assertSame(['POST', 'POST'], array_column($connection->requests, 'method'), $name);
            $key = $connection->requests[0]['headers']['Idempotency-Key'] ?? '';
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key, $name);
            $this->assertSame($key, $connection->requests[1]['headers']['Idempotency-Key'], $name);
        }
    }

    public function testAnIdempotentWriteWaitsOutItsOwnInFlightAttempt(): void
    {
        $inFlight = '{"message":"A request with this Idempotency-Key is already in progress"}';
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('busy', 409, 'Conflict', $inFlight),
            new Response(200, [], '{"mode":"full","sessionId":"s-1"}'),
        ]);

        $this->assertSame('s-1', $this->retryingClient($connection)->remember('I moved to Lisbon')['sessionId']);
        $this->assertCount(2, $connection->requests);
        // The retry presents the same key, so the server returns the stored result.
        $this->assertSame(
            $connection->requests[0]['headers']['Idempotency-Key'],
            $connection->requests[1]['headers']['Idempotency-Key'],
        );
    }

    public function testARealConflictIsNotRetried(): void
    {
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('dup', 409, 'Conflict', '{"message":"Idempotency-Key already used with a different request body"}'),
        ]);

        try {
            $this->retryingClient($connection)->remember('I moved to Lisbon');
            $this->fail('Expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame(409, $e->status);
            $this->assertCount(1, $connection->requests);
        }
    }

    public function testNotImplementedIsNeverRetried(): void
    {
        $connection = new SequenceHttpConnection([
            new HttpConnectionException('stub', 501, 'Not Implemented', '{"message":"not implemented"}'),
        ]);

        try {
            $this->retryingClient($connection)->state();
            $this->fail('Expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(501, $e->status);
            $this->assertCount(1, $connection->requests);
        }
    }

    public function testNonIdempotentWritesAreNotRetried(): void
    {
        $calls = [
            'forget' => static fn (AgentMemory $m) => $m->forget('anything'),
            'chat' => static fn (AgentMemory $m) => $m->chat('hello'),
            'reflect' => static fn (AgentMemory $m) => $m->reflect('anything'),
        ];

        foreach ($calls as $name => $call) {
            $connection = new SequenceHttpConnection([
                new HttpConnectionException('boom', 500, 'Boom', ''),
            ]);

            try {
                $call($this->retryingClient($connection));
                $this->fail("{$name} should throw a ServerException");
            } catch (ServerException $e) {
                $this->assertSame(500, $e->status, $name);
            }

            $this->assertCount(1, $connection->requests, $name);
            $this->assertArrayNotHasKey('Idempotency-Key', $connection->requests[0]['headers'], $name);
        }
    }

    public function testNonJsonResponseThrowsAgentMemoryException(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], 'not json'));

        $this->expectException(AgentMemoryException::class);

        $this->client($connection)->state();
    }

    public function testChatStreamYieldsTheServersMetaChunkAndDoneFrames(): void
    {
        $sse = "event: meta\ndata: {\"traceId\":\"tr-9\",\"sessionId\":\"s-9\"}\n\n"
            . ": keep-alive\n\n"
            . "event: chunk\ndata: {\"text\":\"Hel\"}\n\n"
            . "event: chunk\ndata: {\"text\":\"lo\"}\n\n"
            . "event: done\ndata: {\"reply\":\"Hello\",\"memoryUpdates\":{},\"citations\":[{\"marker\":\"S1\"}]}\n\n";
        $connection = new RecordingHttpConnection(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $chunks = iterator_to_array($this->client($connection)->chatStream('Tell me a story'), false);

        $this->assertSame('{"message":"Tell me a story","stream":true}', $connection->body);
        $this->assertSame('text/event-stream', $connection->headers['Accept']);
        $this->assertCount(4, $chunks);
        $this->assertSame('Hello', implode('', array_map(static fn ($chunk): string => $chunk->delta, $chunks)));
        $this->assertSame('tr-9', $chunks[1]->traceId);
        $this->assertSame('s-9', $chunks[3]->sessionId);
        $this->assertTrue($chunks[3]->done);
        $this->assertSame('Hello', $chunks[3]->raw['reply']);
    }

    public function testChatStreamSendsNothingUntilIterated(): void
    {
        $sse = "event: done\ndata: {\"reply\":\"Hi\"}\n\n";
        $connection = new RecordingHttpConnection(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $stream = $this->client($connection)->chatStream('Hi');
        $this->assertNull($connection->url, 'validating the message must not send the request');

        iterator_to_array($stream, false);
        $this->assertSame(self::BASE . '/chat', $connection->url);
    }
}
