<?php

namespace SurrealDB\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\AuthState;
use SurrealDB\SDK\Auth\BearerAuth;
use SurrealDB\SDK\Auth\DatabaseAuth;
use SurrealDB\SDK\Auth\NamespaceAuth;
use SurrealDB\SDK\Auth\RecordAccessAuth;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Auth\Token;
use SurrealDB\SDK\Auth\Tokens;
use SurrealDB\SDK\Connection\SessionState;
use SurrealDB\SDK\Events\AuthChanged;
use SurrealDB\SDK\Rpc\RpcRequest;

/**
 * Debug output ends up in logs, traces and error reports: it must not hold
 * a password, key or token.
 */
final class SecretsStayOutOfDebugOutputTest extends TestCase
{
    private const string SECRET = 'hunter2-s3cret';

    /**
     * @return iterable<string, array{object}>
     */
    public static function holders(): iterable
    {
        $secret = self::SECRET;

        yield 'root' => [new RootAuth('root', $secret)];
        yield 'namespace' => [new NamespaceAuth('ns', 'user', $secret)];
        yield 'database' => [new DatabaseAuth('ns', 'db', 'user', $secret)];
        yield 'bearer' => [new BearerAuth('ns', 'db', 'api', $secret)];
        yield 'record access' => [new RecordAccessAuth('ns', 'db', 'account', ['email' => 'a@b.c', 'pass' => $secret])];
        yield 'token' => [new Token($secret)];
        yield 'tokens' => [new Tokens($secret, $secret)];
        yield 'auth changed event' => [new AuthChanged(new Tokens($secret, $secret))];
        yield 'session state' => [new SessionState(auth: new AuthState($secret, $secret))];
        yield 'signin request' => [new RpcRequest('signin', [['user' => 'root', 'pass' => $secret]])];
        yield 'authenticate request' => [new RpcRequest('authenticate', [$secret])];
    }

    #[DataProvider('holders')]
    public function testDebugOutputHidesTheSecret(object $holder): void
    {
        ob_start();
        var_dump($holder);
        $dumped = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::SECRET, print_r($holder, true));
        $this->assertStringNotContainsString(self::SECRET, $dumped);
        $this->assertStringContainsString('<redacted>', $dumped);
    }

    public function testTheValuesThemselvesAreUnchanged(): void
    {
        $this->assertSame(['user' => 'root', 'pass' => self::SECRET], (new RootAuth('root', self::SECRET))->toArray());
        $this->assertSame(self::SECRET, (string) new Token(self::SECRET));
        $this->assertSame(self::SECRET, (new Tokens(self::SECRET))->access);
    }

    public function testATokenPassedToAuthenticateStaysOutOfStackTraces(): void
    {
        $token = new Token(self::SECRET);

        try {
            (static function (#[\SensitiveParameter] string $token): never {
                throw new \RuntimeException('refused');
            })((string) $token);
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString(self::SECRET, print_r($error->getTrace(), true));
        }

        $parameter = new \ReflectionParameter([\SurrealDB\SDK\SurrealSession::class, 'authenticate'], 'token');
        $this->assertNotSame([], $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testOtherRequestsShowTheirParameters(): void
    {
        $this->assertStringContainsString('RETURN 1', print_r(new RpcRequest('query', ['RETURN 1']), true));
    }
}
