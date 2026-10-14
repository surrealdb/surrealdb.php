<?php

namespace SurrealDB\Tests\Integration\AgentMemory;

use SurrealDB\AgentMemory\Enum\InferMode;
use SurrealDB\AgentMemory\Enum\LookupSection;
use SurrealDB\AgentMemory\Enum\ResultFamily;
use SurrealDB\AgentMemory\Options\ChatOptions;
use SurrealDB\AgentMemory\Options\ContextOptions;
use SurrealDB\AgentMemory\Options\DocumentQueryOptions;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use SurrealDB\AgentMemory\Options\ForgetOptions;
use SurrealDB\AgentMemory\Options\LookupOptions;
use SurrealDB\AgentMemory\Options\RecallOptions;
use SurrealDB\AgentMemory\Options\RememberOptions;
use SurrealDB\AgentMemory\Streaming\ChatChunk;

/**
 * The model-backed Agent Memory surface: recall, context, forget, chat (plain
 * and streamed), and document ingestion. These need a server with a working
 * embedder and LLM provider, so they run only when `AGENT_MEMORY_LIVE=1`
 * (`scripts/agent-memory-server.sh` sets it when `GEMINI_API_KEY` is present).
 * Model output is non-deterministic, so the assertions check shape and the
 * presence of a run-unique marker rather than exact text.
 */
final class AgentMemoryLiveTest extends AgentMemoryServerTestCase
{
    private const int INGEST_TIMEOUT_SECONDS = 240;

    private static string $marker = '';

    public static function setUpBeforeClass(): void
    {
        if (getenv('AGENT_MEMORY_LIVE') !== '1') {
            self::markTestSkipped('Set AGENT_MEMORY_LIVE=1 against a server with real model keys to run the live Agent Memory tests.');
        }

        parent::setUpBeforeClass();

        self::$marker = 'beacon' . self::$run;
        self::memory()->remember(
            'The ' . self::$marker . ' lighthouse is painted teal and has 7 floors.',
            new RememberOptions(infer: InferMode::NONE, scopes: self::$scope),
        );
    }

    public function testRememberedTextIsRecalled(): void
    {
        $hits = self::eventually(
            fn (): array => self::memory()->recall(
                'What colour is the ' . self::$marker . ' lighthouse?',
                new RecallOptions(k: 10, include: [ResultFamily::PASSAGES], lens: self::$scope),
            )['hits'],
            fn (array $hits): bool => self::mentionsMarker(array_column($hits, 'text')),
        );

        $this->assertTrue(self::mentionsMarker(array_column($hits, 'text')));
    }

    public function testContextRendersPromptText(): void
    {
        $context = self::memory()->context('the ' . self::$marker . ' lighthouse', new ContextOptions(lens: self::$scope));

        $this->assertIsString($context['context']);
        $this->assertNotSame('', $context['tier']);
    }

    public function testLookupPassagesAreReadOnRequest(): void
    {
        $answer = self::memory()->lookup(self::$marker, new LookupOptions(include: [LookupSection::PASSAGES], passageLimit: 5));

        $this->assertIsArray($answer['passages']['items']);
        $this->assertIsBool($answer['passages']['truncated']);
    }

    public function testForgetPreviewDeletesNothing(): void
    {
        $preview = self::memory()->forget('the ' . self::$marker . ' lighthouse', new ForgetOptions(dryRun: true));
        $this->assertIsInt($preview['deleted']);

        $hits = self::memory()->recall(
            'What colour is the ' . self::$marker . ' lighthouse?',
            new RecallOptions(k: 10, include: [ResultFamily::PASSAGES], lens: self::$scope),
        )['hits'];
        $this->assertTrue(self::mentionsMarker(array_column($hits, 'text')), 'a dry run must not forget anything');
    }

    public function testChatReplies(): void
    {
        $reply = self::memory()->chat(
            'In one short sentence: how many floors does the ' . self::$marker . ' lighthouse have?',
            new ChatOptions(scopes: self::$scope, bypassCache: true, suppressMarkers: true),
        );

        $this->assertNotSame('', trim($reply['reply']));
        $this->assertNotEmpty($reply['traceId']);
        $this->assertNotEmpty($reply['sessionId']);
        $this->assertIsArray($reply['citations']);
    }

    public function testChatStreamsDeltasAndEndsWithDone(): void
    {
        $chunks = iterator_to_array(self::memory()->chatStream(
            'Say hello in five words or fewer.',
            new ChatOptions(scopes: self::$scope, bypassCache: true),
        ), false);

        $this->assertNotEmpty($chunks);
        $this->assertSame(ChatChunk::EVENT_META, $chunks[0]->event);
        $this->assertNotNull($chunks[0]->traceId);

        $last = $chunks[array_key_last($chunks)];
        $this->assertTrue($last->done);
        $this->assertNotSame('', trim(implode('', array_map(static fn (ChatChunk $c): string => $c->delta, $chunks))));
        $this->assertIsString($last->raw['reply']);
    }

    public function testUploadedDocumentIsIngestedAndSearchable(): void
    {
        $documents = self::memory()->documents;
        $upload = $documents->upload(new DocumentUploadOptions(
            file: '# ' . self::$marker . " runbook\n\nThe " . self::$marker . " deploy pipeline has four stages.\n",
            filename: 'runbook-' . self::$run . '.md',
            title: 'Runbook ' . self::$run,
            scopes: self::$scope,
            labels: ['suite=php-sdk'],
        ));
        $this->assertSame('queued', $upload['status']);

        try {
            $status = self::eventually(
                fn (): string => $documents->get($upload['id'])['status'],
                fn (string $status): bool => $status === 'ready' || $status === 'failed',
                self::INGEST_TIMEOUT_SECONDS,
            );
            $this->assertSame('ready', $status, json_encode($documents->get($upload['id'])) ?: '');

            $results = $documents->query(new DocumentQueryOptions(
                'How many stages does the ' . self::$marker . ' deploy pipeline have?',
                lens: self::$scope,
            ))['results'];
            $this->assertNotEmpty($results);
        } finally {
            $documents->delete($upload['id']);
        }
    }

    /**
     * Polls `$read` until `$done` accepts its result or the timeout passes, then returns the last result.
     *
     * @template T
     *
     * @param \Closure():T     $read
     * @param \Closure(T):bool $done
     *
     * @return T
     */
    private static function eventually(\Closure $read, \Closure $done, int $timeoutSeconds = 30): mixed
    {
        $deadline = time() + $timeoutSeconds;

        do {
            $result = $read();

            if ($done($result)) {
                return $result;
            }

            usleep(1_000_000);
        } while (time() < $deadline);

        return $result;
    }

    /**
     * @param list<mixed> $texts
     */
    private static function mentionsMarker(array $texts): bool
    {
        foreach ($texts as $text) {
            if (is_string($text) && str_contains($text, self::$marker)) {
                return true;
            }
        }

        return false;
    }
}
