<?php

namespace SurrealDB\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Surreal;

/**
 * Replies are matched to calls by request id, so every request on a
 * connection needs its own: under an async runtime two calls in flight with
 * the same id receive each other's replies, or one never resolves.
 */
final class RequestIdTest extends TestCase
{
    public function testEveryRequestGetsADistinctId(): void
    {
        $ids = new \ArrayObject();
        $client = new class($ids) implements ClientInterface {
            public function __construct(private readonly \ArrayObject $ids) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $this->ids[] = $body['id'];

                $result = match ($body['method']) {
                    'version' => 'surrealdb-3.3.4',
                    'query' => [['status' => 'OK', 'result' => 1, 'time' => '1ms']],
                    default => null,
                };

                return new Response(200, [], json_encode(['id' => $body['id'], 'result' => $result], JSON_THROW_ON_ERROR));
            }
        };
        $factory = new HttpFactory();

        $db = new Surreal(new DriverOptions(
            format: CodecEnum::JSON,
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
        ));
        $db->connect('http://127.0.0.1:8000');
        $db->run('RETURN 1');
        $db->run('RETURN 1');
        $db->let('a', 1);

        $this->assertGreaterThanOrEqual(3, count($ids));
        $this->assertSame(array_unique($ids->getArrayCopy()), $ids->getArrayCopy());
    }
}
