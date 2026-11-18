<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function urls(): iterable
    {
        // [url, RPC endpoint, import/export base]
        yield 'trailing slash after rpc' => ['http://h:8000/rpc/', 'http://h:8000/rpc', ''];
        yield 'trailing slash' => ['http://h:8000/', 'http://h:8000/rpc', ''];
        yield 'rpc in the host' => ['ws://rpc.localhost:18400', 'http://rpc.localhost:18400/rpc', ''];
        yield 'rpc in a path segment' => ['https://h/rpcproxy/rpc', 'https://h/rpcproxy/rpc', '/rpcproxy'];
        yield 'mounted under a prefix' => ['https://p/surreal/', 'https://p/surreal/rpc', '/surreal'];
    }

    #[DataProvider('urls')]
    public function testOnlyATrailingRpcSegmentIsTheRpcPath(string $url, string $rpc, string $base): void
    {
        $endpoint = Endpoint::parse($url);

        $this->assertSame($rpc, $endpoint->httpUri());
        $this->assertSame($base, $endpoint->basePath());
    }
}
