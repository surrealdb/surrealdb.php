<?php

namespace SurrealDB\Tests\Fakes;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that hands back at most a few bytes per read, the way a slow
 * network delivers a streamed response, so frames arrive split mid-line.
 */
final class TrickleStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public function __construct(string $contents, private readonly int $bytesPerRead)
    {
        $this->stream = Utils::streamFor($contents);
    }

    public function read(int $length): string
    {
        return $this->stream->read(min($length, $this->bytesPerRead));
    }
}
