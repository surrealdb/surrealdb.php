<?php

namespace SurrealDB\Tests\Fakes;

use Psr\Http\Message\ResponseInterface;
use SurrealDB\Contracts\HttpConnectionInterface;

/** Records every HTTP exchange and returns a canned response. */
final class RecordingHttpConnection implements HttpConnectionInterface
{
    public ?string $url = null;
    public ?string $method = null;
    public ?string $body = null;

    /** @var array<string,string> */
    public array $headers = [];

    /** @var list<array{url:string,method:string,body:?string,headers:array<string,string>}> */
    public array $requests = [];

    public function __construct(private readonly ResponseInterface $response) {}

    public function request(string $url, string $method, ?string $body, array $headers): ResponseInterface
    {
        $this->url = $url;
        $this->method = $method;
        $this->body = $body;
        $this->headers = $headers;
        $this->requests[] = ['url' => $url, 'method' => $method, 'body' => $body, 'headers' => $headers];

        return $this->response;
    }
}
