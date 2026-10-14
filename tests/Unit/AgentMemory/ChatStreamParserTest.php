<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
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

    /**
     * @return list<ChatChunk>
     */
    private const string DONE = "event: done\ndata: {\"reply\":\"\"}\n\n";

    private function parse(string $sse, int $bytesPerRead = 8192): array
    {
        return iterator_to_array((new ChatStreamParser())->parse(new TrickleStream($sse, $bytesPerRead)), false);
    }

    public function testReadsTextFromChunkFramesAndCarriesIdsForward(): void
    {
        $chunks = $this->parse(self::SERVER_STREAM);

        $this->assertSame(['meta', 'chunk', 'chunk', 'done'], array_map(static fn (ChatChunk $c): ?string => $c->event, $chunks));
        $this->assertSame(['', 'Alice is ', 'the CTO [S1].', ''], array_map(static fn (ChatChunk $c): string => $c->delta, $chunks));

        foreach ($chunks as $chunk) {
            $this->assertSame('tr-1', $chunk->traceId);
            $this->assertSame('s-1', $chunk->sessionId);
        }

        $done = $chunks[3];
        $this->assertTrue($done->done);
        $this->assertSame('Alice is the CTO [S1].', $done->raw['reply']);
        $this->assertSame([['marker' => 'S1']], $done->raw['citations']);
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

        $this->assertSame('meta', $generator->current()->event);

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
    }

    public function testParsingStopsAtTheDoneFrame(): void
    {
        $chunks = $this->parse(self::SERVER_STREAM . "event: chunk\ndata: {\"text\":\"after done\"}\n\n");

        $this->assertCount(4, $chunks);
    }
}
