<?php

namespace SurrealDB\Tests\Unit\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SurrealDB\Exceptions\HttpConnectionException;
use SurrealDB\Http\HttpConnection;
use SurrealDB\Http\PsrHttpClientProvider;

final class HttpConnectionTest extends TestCase
{
    public function testRequestBuildsMethodUrlHeadersAndBody(): void
    {
        $captured = new \stdClass();
        $captured->request = null;
        $client = new class($captured) implements ClientInterface {
            public function __construct(private readonly \stdClass $captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured->request = $request;

                return new Response(200, [], 'ok');
            }
        };
        $factory = new HttpFactory();
        $connection = new HttpConnection(new PsrHttpClientProvider($client, $factory, $factory));

        $response = $connection->request(
            'https://example.com/rpc',
            'POST',
            '{"hello":"world"}',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer t'],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(RequestInterface::class, $captured->request);
        $this->assertSame('POST', $captured->request->getMethod());
        $this->assertSame('https://example.com/rpc', (string) $captured->request->getUri());
        $this->assertSame('application/json', $captured->request->getHeaderLine('Content-Type'));
        $this->assertSame('Bearer t', $captured->request->getHeaderLine('Authorization'));
        $this->assertSame('{"hello":"world"}', (string) $captured->request->getBody());
    }

    public function testNullBodyLeavesRequestBodyEmpty(): void
    {
        $captured = new \stdClass();
        $captured->request = null;
        $client = new class($captured) implements ClientInterface {
            public function __construct(private readonly \stdClass $captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured->request = $request;

                return new Response(204);
            }
        };
        $factory = new HttpFactory();
        $connection = new HttpConnection(new PsrHttpClientProvider($client, $factory, $factory));

        $connection->request('https://example.com/health', 'GET', null, []);

        $this->assertInstanceOf(RequestInterface::class, $captured->request);
        $this->assertSame('', (string) $captured->request->getBody());
    }

    public function testNonSuccessStatusThrowsWithStatusAndBody(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(404, [], 'not found');
            }
        };
        $factory = new HttpFactory();
        $connection = new HttpConnection(new PsrHttpClientProvider($client, $factory, $factory));

        try {
            $connection->request('https://example.com/missing', 'GET', null, []);
            $this->fail('Expected HttpConnectionException to be thrown.');
        } catch (HttpConnectionException $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('not found', $e->body);
        }
    }
}
