<?php

namespace SurrealDB\Tests\Fakes;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client whose responses on one path break off halfway, keeping the
 * length they declared, as Guzzle's stream handler hands back a body the
 * server stopped sending.
 */
final class CuttingClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly string $path,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);

        if (!str_ends_with($request->getUri()->getPath(), $this->path)) {
            return $response;
        }

        $body = (string) $response->getBody();

        return new Response(
            $response->getStatusCode(),
            ['Content-Type' => $response->getHeaderLine('Content-Type'), 'Content-Length' => (string) strlen($body)],
            substr($body, 0, intdiv(strlen($body), 2)),
        );
    }
}
