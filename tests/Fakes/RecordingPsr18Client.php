<?php

namespace SurrealDB\Tests\Fakes;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Records every PSR-18 request and returns a canned response. */
final class RecordingPsr18Client implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly ResponseInterface $response) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        $this->requests[] = $request;

        return $this->response;
    }
}
