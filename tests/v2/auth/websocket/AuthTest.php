<?php

namespace v2\auth\websocket;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\None;
use Surreal\Core\Engines\WsEngine;
use Surreal\Exceptions\SurrealException;
use Surreal\Surreal;

class AuthTest extends TestCase
{
    private function getDb(): Surreal
    {
        $db = new Surreal();

        $db->connect("ws://127.0.0.1:8000/rpc", [
            "namespace" => "test",
            "database" => "test"
        ]);

        self::assertTrue($db->status() === 200);

        $jwt = $db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        self::assertIsString($jwt);
        $db->authenticate($jwt);

        return $db;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testInfo(): void
    {
        $db = $this->getDb();

        try {
            $db->info();
        } catch (SurrealException $exception) {
            $this->assertInstanceOf(SurrealException::class, $exception);
        }

        $token = $db->signup([
            "email" => "mario2",
            "pass" => "supermario",
            "namespace" => "test",
            "database" => "test",
            "access" => "account"
        ]);

        $this->assertIsString($token, "The token is not a string");

        $token = $db->signin([
            "email" => "mario2",
            "pass" => "supermario",
            "namespace" => "test",
            "database" => "test",
            "access" => "account"
        ]);

        $this->assertIsString($token, "The token is not a string");

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testScopeAuth(): void
    {
        $db = $this->getDb();

        $token = $db->signup([
            "email" => "mario",
            "pass" => "supermario",
            "namespace" => "test",
            "database" => "test",
            "access" => "account"
        ]);

        $this->assertIsString($token);

        $token = $db->signin([
            "email" => "mario",
            "pass" => "supermario",
            "namespace" => "test",
            "database" => "test",
            "access" => "account"
        ]);

        $this->assertIsString($token);
        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testAuthenticate(): void
    {
        $db = $this->getDb();

        $token = $db->signin(["user" => "root", "pass" => "root"]);
        $this->assertIsString($token);

        $result = $db->authenticate($token);
        $this->assertInstanceOf(None::class, $result, "The result is not null");

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testInvalidate(): void
    {
        $db = $this->getDb();

        $token = $db->signin(["user" => "root", "pass" => "root"]);
        $this->assertIsString($token);

        $info = $db->info();
        $this->assertNotNull($info);

        $result = $db->invalidate();
        $this->assertInstanceOf(None::class, $result);

        try {
            $db->info();
        } catch (SurrealException $exception) {
            $this->assertInstanceOf(SurrealException::class, $exception);
        }

        $db->close();
    }
}