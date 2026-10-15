<?php

namespace SurrealDB\Tests\Fakes;

use Psr\Http\Message\ResponseInterface;
use SurrealDB\Contracts\HttpConnectionInterface;

/**
 * Answers each request with the next queued response (or throws the next
 * queued exception) and records every exchange. Used where one call issues
 * several requests, such as walking pages or retrying.
 */
final class SequenceHttpConnection implements HttpConnectionInterface
{
    /** @var list<array{url:string,method:string,body:?string,headers:array<string,string>}> */
    public array $requests = [];

    /**
     * @param list<ResponseInterface|\Throwable> $replies
     */
    public function __construct(private array $replies) {}

    public function request(string $url, string $method, ?string $body, array $headers): ResponseInterface
    {
        $this->requests[] = ['url' => $url, 'method' => $method, 'body' => $body, 'headers' => $headers];

        $reply = array_shift($this->replies);

        if ($reply === null) {
            throw new \LogicException("SequenceHttpConnection has no reply left for {$method} {$url}.");
        }

        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply;
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_column($this->requests, 'url');
    }
}
