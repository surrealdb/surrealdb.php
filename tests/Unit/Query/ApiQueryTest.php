<?php

namespace SurrealDB\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Api\SurrealApi;
use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Types\None;

final class ApiQueryTest extends TestCase
{
    public function testCompilesToApiInvokeWithBoundPathAndRequest(): void
    {
        $api = new SurrealApi($this->executor(), '/v1/');
        $api->header('authorization', 'secret');

        $query = $api->post('users//new', ['name' => 'Tobie'])
            ->header('x-trace', 'abc')
            ->query('dry', 'true')
            ->compile();

        [$path, $request] = array_keys($query->bindings);

        $this->assertSame("api::invoke(\${$path}, \${$request})", $query->query);
        $this->assertSame('/v1/users/new', $query->bindings[$path]);
        $this->assertSame([
            'method' => 'post',
            'headers' => ['authorization' => 'secret', 'x-trace' => 'abc'],
            'query' => ['dry' => 'true'],
            'body' => ['name' => 'Tobie'],
        ], $query->bindings[$request]);
    }

    public function testPlainGetSendsOnlyTheMethod(): void
    {
        $query = new SurrealApi($this->executor())->get('health')->compile();
        $request = array_values($query->bindings)[1];

        $this->assertSame(['method' => 'get'], $request);
    }

    public function testExplicitNullBodyIsSentAsNull(): void
    {
        $api = new SurrealApi($this->executor());

        $this->assertSame(['method' => 'post', 'body' => null], array_values($api->post('/x', null)->compile()->bindings)[1]);
        $this->assertSame(['method' => 'put', 'body' => null], array_values($api->invoke('/x', 'put', body: null)->compile()->bindings)[1]);
    }

    public function testOmittedOrNoneBodyIsLeftOut(): void
    {
        $api = new SurrealApi($this->executor());

        $this->assertSame(['method' => 'post'], array_values($api->post('/x')->compile()->bindings)[1]);
        $this->assertSame(['method' => 'post'], array_values($api->post('/x', None::instance())->compile()->bindings)[1]);
        $this->assertSame(['method' => 'put', 'headers' => ['a' => '1']], array_values($api->invoke('/x', 'put', headers: ['a' => '1'])->compile()->bindings)[1]);
    }

    public function testRemovingADefaultHeader(): void
    {
        $api = new SurrealApi($this->executor());
        $api->header('a', '1')->header('a', null);

        $this->assertSame(['method' => 'get'], array_values($api->get('/x')->compile()->bindings)[1]);
    }

    public function testNormalizePath(): void
    {
        $this->assertSame('/', SurrealApi::normalizePath('', ''));
        $this->assertSame('/a/b/c', SurrealApi::normalizePath('a/', '/b//c/'));
    }

    private function executor(): QueryExecutor
    {
        return new class implements QueryExecutor {
            public function query(BoundQuery $query): array
            {
                return [];
            }
        };
    }
}
