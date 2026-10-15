<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory\Enum\ChatEvent;
use SurrealDB\AgentMemory\Exceptions\StreamException;
use SurrealDB\AgentMemory\Streaming\ChatChunk;
use SurrealDB\AgentMemory\Streaming\ChatStreamParser;
use SurrealDB\Tests\Fakes\TrickleStream;

/** Parses the server's `/chat` SSE frames: `meta`, `chunk`, `done`, and `error`. */
final class ChatStreamParserTest extends TestCase
{
    /** Frames exactly as the server's `build_sse_response` emits them. */
    private const string SERVER_STREAM = "event: meta\ndata: {\"traceId\":\"tr-1\",\"sessionId\":\"s-1\"}\n\n"
        . "event: chunk\ndata: {\"text\":\"Alice is \"}\n\n"
        . ": keep-alive\n\n"
        . "event: chunk\ndata: {\"text\":\"the CTO [S1].\"}\n\n"
        . "event: done\ndata: {\"reply\":\"Alice is the CTO [S1].\",\"memoryUpdates\":{\"attributes\":[]},\"citations\":[{\"marker\":\"S1\"}]}\n\n";

    private const string DONE = "event: done\ndata: {\"reply\":\"\"}\n\n";

    /**
     * @return list<ChatChunk>
     */
    private function parse(string $sse, int $bytesPerRead = 8192): array
    {
        return iterator_to_array((new ChatStreamParser())->parse(new TrickleStream($sse, $bytesPerRead)), false);
    }

    public function testReadsTextFromChunkFramesAndCarriesIdsForward(): void
    {
        $chunks = $this->parse(self::SERVER_STREAM);

        $this->assertSame(
            [ChatEvent::META, ChatEvent::CHUNK, ChatEvent::CHUNK, ChatEvent::DONE],
            array_map(static fn (ChatChunk $c): ?ChatEvent => $c->event, $chunks),
        );
        $this->assertSame(['', 'Alice is ', 'the CTO [S1].', ''], array_map(static fn (ChatChunk $c): string => $c->delta, $chunks));

        foreach ($chunks as $chunk) {
            $this->assertSame('tr-1', $chunk->traceId);
            $this->assertSame('s-1', $chunk->sessionId);
        }

        $done = $chunks[3];
        $this->assertTrue($done->done);
        $this->assertSame('Alice is the CTO [S1].', $done->reply);
        $this->assertSame([['marker' => 'S1']], $done->citations);
        $this->assertSame(['attributes' => []], $done->memoryUpdates);
        $this->assertSame($done->reply, $done->raw['reply'], 'raw keeps the payload');

        foreach (array_slice($chunks, 0, 3) as $chunk) {
            $this->assertNull($chunk->reply);
            $this->assertNull($chunk->memoryUpdates);
            $this->assertNull($chunk->citations);
        }
    }

    public function testUnknownEventNamesMapToNull(): void
    {
        $chunks = $this->parse("event: progress\ndata: {\"text\":\"thinking\"}\n\n" . self::DONE);

        $this->assertNull($chunks[0]->event);
        $this->assertSame('thinking', $chunks[0]->delta);
        $this->assertFalse($chunks[0]->done);
    }

    public function testFinalFieldsAreReadOnlyFromTheTerminalFrame(): void
    {
        $chunks = $this->parse(
            "event: chunk\ndata: {\"text\":\"Hi\",\"reply\":\"early\",\"memoryUpdates\":{\"turnId\":\"t-1\"},\"citations\":[]}\n\n"
            . "data: {\"done\":true,\"reply\":\"Hi\",\"citations\":[]}\n\n",
        );

        $this->assertNull($chunks[0]->reply);
        $this->assertNull($chunks[0]->memoryUpdates);
        $this->assertNull($chunks[0]->citations);
        $this->assertSame('early', $chunks[0]->raw['reply']);

        // An unnamed frame that says `done: true` is terminal too.
        $this->assertNull($chunks[1]->event);
        $this->assertTrue($chunks[1]->done);
        $this->assertSame('Hi', $chunks[1]->reply);
        $this->assertSame([], $chunks[1]->citations);
        $this->assertNull($chunks[1]->memoryUpdates);
    }

    public function testMalformedFinalFieldsAreDropped(): void
    {
        [$done] = $this->parse("event: done\ndata: {\"reply\":5,\"memoryUpdates\":\"x\",\"citations\":{\"a\":1}}\n\n");

        $this->assertTrue($done->done);
        $this->assertNull($done->reply);
        $this->assertNull($done->memoryUpdates);
        $this->assertNull($done->citations);
        $this->assertSame(['reply' => 5, 'memoryUpdates' => 'x', 'citations' => ['a' => 1]], $done->raw);
    }

    public function testFramesSplitAcrossReadsAreReassembled(): void
    {
        foreach ([1, 3, 7] as $bytesPerRead) {
            $text = implode('', array_map(
                static fn (ChatChunk $c): string => $c->delta,
                $this->parse(self::SERVER_STREAM, $bytesPerRead),
            ));

            $this->assertSame('Alice is the CTO [S1].', $text, "{$bytesPerRead} bytes per read");
        }
    }

    public function testCrlfLineEndingsAndMultiLineData(): void
    {
        $sse = "event: chunk\r\ndata: {\"text\":\r\ndata: \"hi\"}\r\n\r\n" . self::DONE;

        $chunks = $this->parse($sse);

        $this->assertCount(2, $chunks);
        $this->assertSame('hi', $chunks[0]->delta);
    }

    public function testOnlyOneSpaceAfterTheDataColonIsSyntax(): void
    {
        $chunks = $this->parse("event: chunk\ndata:   two spaces kept\n\n" . self::DONE);

        $this->assertSame('  two spaces kept', $chunks[0]->delta);
    }

    public function testATruncatedFinalFrameIsDiscardedAndReported(): void
    {
        // The connection dropped mid-frame: the cut-off JSON must not surface as reply text.
        $generator = (new ChatStreamParser())->parse(Utils::streamFor("event: chunk\ndata: {\"text\":\"Hel\"}\n\nevent: chunk\ndata: {\"text\":\"lo"));
        $deltas = [];

        try {
            foreach ($generator as $chunk) {
                $deltas[] = $chunk->delta;
            }

            $this->fail('Expected StreamException');
        } catch (StreamException $e) {
            $this->assertSame(['Hel'], $deltas);
            $this->assertSame('the last frame was cut off mid-way', $e->detail);
        }
    }

    public function testAStreamThatEndsBeforeItsFinalFrameRaises(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Chat stream ended before the final frame: the connection closed without a done event');

        $this->parse("event: meta\ndata: {\"traceId\":\"tr-1\"}\n\nevent: chunk\ndata: {\"text\":\"partial\"}\n\n");
    }

    public function testErrorFramesRaiseAStreamException(): void
    {
        $sse = "event: meta\ndata: {\"traceId\":\"tr-2\",\"sessionId\":\"s-2\"}\n\n"
            . "event: error\ndata: {\"error\":\"provider timed out\"}\n\n"
            . "event: chunk\ndata: {\"text\":\"never reached\"}\n\n";
        $generator = (new ChatStreamParser())->parse(Utils::streamFor($sse));

        $this->assertSame(ChatEvent::META, $generator->current()->event);

        try {
            $generator->next();
            $this->fail('Expected StreamException');
        } catch (StreamException $e) {
            $this->assertSame('provider timed out', $e->detail);
            $this->assertSame(['error' => 'provider timed out'], $e->extensions);
            $this->assertSame('[0] Chat stream failed: provider timed out', $e->getMessage());
        }
    }

    public function testNonJsonErrorDataIsReportedVerbatim(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('serialize: bad float');

        $this->parse("event: error\ndata: serialize: bad float\n\n");
    }

    public function testLegacyDeltaFramesAndTheDoneSentinelStillParse(): void
    {
        $chunks = $this->parse("data: {\"delta\":\"Hel\"}\n\ndata: {\"token\":\"lo\",\"trace_id\":\"tr-9\"}\n\ndata: [DONE]\n\ndata: {\"delta\":\"ignored\"}\n\n");

        $this->assertSame(['Hel', 'lo', ''], array_map(static fn (ChatChunk $c): string => $c->delta, $chunks));
        $this->assertSame('tr-9', $chunks[2]->traceId);
        $this->assertTrue($chunks[2]->done);
        $this->assertNull($chunks[2]->event);
        $this->assertNull($chunks[2]->reply);
        $this->assertSame([], $chunks[2]->raw);
    }

    public function testParsingStopsAtTheDoneFrame(): void
    {
        $chunks = $this->parse(self::SERVER_STREAM . "event: chunk\ndata: {\"text\":\"after done\"}\n\n");

        $this->assertCount(4, $chunks);
    }
}
