<?php

namespace SurrealDB\Tests\Unit\Transport;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Exceptions\HttpConnectionException;
use SurrealDB\SDK\Transport\SurrealHttp;

final class SurrealHttpBodyTest extends TestCase
{
    public function testABodyCutShortOfItsLengthIsAnError(): void
    {
        // What Guzzle's stream handler hands back when the server breaks off.
        $response = new Response(200, ['Content-Length' => '1000'], 'DEFINE TABLE person;');

        $this->expectException(HttpConnectionException::class);
        $this->expectExceptionMessage('ended after 20 of its 1000 bytes');

        SurrealHttp::body($response);
    }

    public function testACompleteBodyIsReturned(): void
    {
        $this->assertSame('abc', SurrealHttp::body(new Response(200, ['Content-Length' => '3'], 'abc')));
        $this->assertSame('abc', SurrealHttp::body(new Response(200, [], 'abc')));
    }

    public function testADecompressedBodyIsNotMeasuredAgainstTheCompressedLength(): void
    {
        $this->assertSame('a', SurrealHttp::body(new Response(200, ['Content-Length' => '30', 'Content-Encoding' => 'gzip'], 'a')));
        $this->assertSame('a', SurrealHttp::body(new Response(200, ['Content-Length' => '30', 'X-Encoded-Content-Encoding' => 'gzip'], 'a')));
    }
}
