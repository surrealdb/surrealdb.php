<?php

namespace SurrealDB\Tests\Fakes;

use Psr\Http\Message\ResponseInterface;
use SurrealDB\Contracts\HttpConnectionInterface;

/**
 * Throws a queued exception per request until the queue is drained, then
 * returns the canned response (or keeps rethrowing the last exception when no
 * response was given). Used to exercise retry and error-mapping paths.
 */
final class FailingHttpConnection implements HttpConnectionInterface
{
    public int $attempts = 0;

    /**
     * @param list<\Throwable> $failures
     */
    public function __construct(
        private array $failures,
        private readonly ?ResponseInterface $response = null,
    ) {}

    public function request(string $url, string $method, ?string $body, array $headers): ResponseInterface
    {
        ++$this->attempts;

        $failure = array_shift($this->failures);

        if ($failure !== null) {
            throw $failure;
        }

        if ($this->response === null) {
            throw new \LogicException('FailingHttpConnection has no response to return.');
        }

        return $this->response;
    }
}
