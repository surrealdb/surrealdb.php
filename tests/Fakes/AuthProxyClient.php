<?php

namespace SurrealDB\Tests\Fakes;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that stands in for an authenticating proxy in front of the
 * server: it answers a request without a bearer token with a 401 itself, and
 * passes the others on.
 */
final class AuthProxyClient implements ClientInterface
{
    /** @var list<string> the RPC paths of the requests it refused */
    public array $refused = [];

    public function __construct(private readonly ClientInterface $inner) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ')) {
            $this->refused[] = $request->getUri()->getPath();

            return new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');
        }

        return $this->inner->sendRequest($request);
    }
}
