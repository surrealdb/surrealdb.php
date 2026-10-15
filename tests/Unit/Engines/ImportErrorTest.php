<?php

namespace SurrealDB\Tests\Unit\Engines;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Exceptions\AlreadyExistsException;
use SurrealDB\SDK\Exceptions\HttpConnectionException;
use SurrealDB\SDK\Exceptions\ThrownException;
use SurrealDB\SDK\Surreal;

/**
 * The `/import` endpoint reports failing statements in its body. SurrealDB 3.x
 * also answers 422, but older servers answer 200, so the body decides.
 */
final class ImportErrorTest extends TestCase
{
    public function testFailedStatementInA200ResponseIsReported(): void
    {
        $db = $this->connect(new Response(200, [], json_encode([
            ['status' => 'OK', 'result' => [], 'time' => '1ms'],
            ['status' => 'ERR', 'result' => 'An error occurred: boom', 'time' => '1ms'],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(\SurrealDB\SDK\Exceptions\ServerException::class);
        $this->expectExceptionMessage('Import failed at statement 1: An error occurred: boom');

        $db->import('OPTION IMPORT; CREATE a; THROW "boom";');
    }

    public function testStatementErrorKeepsTheServerKindAndDetails(): void
    {
        $db = $this->connect(new Response(422, [], json_encode([
            [
                'status' => 'ERR',
                'kind' => 'AlreadyExists',
                'details' => ['kind' => 'Record', 'details' => ['id' => 'a:1']],
                'result' => 'Database record `a:1` already exists',
            ],
        ], JSON_THROW_ON_ERROR)));

        try {
            $db->import('OPTION IMPORT; CREATE a:1; CREATE a:1;');
            self::fail('Expected the import to fail.');
        } catch (AlreadyExistsException $error) {
            self::assertSame('AlreadyExists', $error->kind);
            self::assertSame(['kind' => 'Record', 'details' => ['id' => 'a:1']], $error->details);
        }
    }

    public function testRejectedImportUsesTheServerExplanation(): void
    {
        $db = $this->connect(new Response(400, [], json_encode([
            'code' => 400,
            'details' => 'Request problems detected',
            'information' => 'Invalid statement: Import requires `OPTION IMPORT;` as the first statement.',
        ], JSON_THROW_ON_ERROR)));

        try {
            $db->import('CREATE a;');
            self::fail('Expected the import to fail.');
        } catch (HttpConnectionException $error) {
            self::assertSame(400, $error->status);
            self::assertStringContainsString('Import requires `OPTION IMPORT;`', $error->getMessage());
            self::assertStringNotContainsString('"code"', $error->getMessage());
        }
    }

    public function testPlainTextFailureKeepsTheBodyOfANonSeekableStream(): void
    {
        $db = $this->connect(new Response(502, [], new NoSeekStream(Utils::streamFor('upstream proxy error'))));

        try {
            $db->import('OPTION IMPORT; CREATE a;');
            self::fail('Expected the import to fail.');
        } catch (HttpConnectionException $error) {
            self::assertSame(502, $error->status);
            self::assertStringContainsString('upstream proxy error', $error->getMessage());
            self::assertSame('upstream proxy error', $error->body);
        }
    }

    public function testSuccessfulImportReturnsQuietly(): void
    {
        $db = $this->connect(new Response(200, [], '[]'));

        $db->import('OPTION IMPORT; CREATE a;');

        $this->addToAssertionCount(1);
    }

    public function testThrownStatementMapsToThrownException(): void
    {
        $db = $this->connect(new Response(422, [], json_encode([
            ['status' => 'ERR', 'kind' => 'Thrown', 'result' => 'An error occurred: boom'],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(ThrownException::class);

        $db->import('OPTION IMPORT; THROW "boom";');
    }

    private function connect(ResponseInterface $importResponse): Surreal
    {
        $client = new class($importResponse) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $importResponse) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (str_ends_with($request->getUri()->getPath(), '/import')) {
                    return $this->importResponse;
                }

                return new Response(200, [], json_encode(['id' => '1', 'result' => 'surrealdb-3.3.4'], JSON_THROW_ON_ERROR));
            }
        };
        $factory = new HttpFactory();

        $db = new Surreal(new DriverOptions(
            format: CodecEnum::JSON,
            httpClient: $client,
            requestFactory: $factory,
            streamFactory: $factory,
        ));
        $db->connect('http://localhost:8000', new ConnectOptions(namespace: 'test', database: 'test'));

        return $db;
    }
}
