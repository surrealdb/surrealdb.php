<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SurrealDB\Connection\Endpoint;

final class EndpointTest extends TestCase
{
    public function testAppendsRpcSuffixForRemoteSchemes(): void
    {
        $endpoint = Endpoint::parse('ws://localhost:8000');

        $this->assertSame('ws', $endpoint->scheme);
        $this->assertSame('/rpc', $endpoint->path);
        $this->assertSame('ws://localhost:8000/rpc', $endpoint->uri);
    }

    public function testDoesNotDuplicateRpcSuffix(): void
    {
        $endpoint = Endpoint::parse('https://db.example.com/rpc');

        $this->assertSame('/rpc', $endpoint->path);
        $this->assertSame('https://db.example.com/rpc', $endpoint->uri);
    }

    public function testMapsWebSocketSchemeToHttpForRequests(): void
    {
        $endpoint = Endpoint::parse('wss://db.example.com:9000/foo');

        $this->assertSame('/foo/rpc', $endpoint->path);
        $this->assertSame('https', $endpoint->httpScheme());
        $this->assertSame('https://db.example.com:9000/foo/rpc', $endpoint->httpUri());
        $this->assertSame('/foo', $endpoint->basePath());
    }
}
