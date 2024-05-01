<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Client\SurrealHTTP;
use Surreal\Exceptions\SurrealException;

final class BasicTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testStatus(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $status = $db->status();

        $this->assertIsInt($status);
        $this->assertEquals(200, $status);

        $db->close();
    }

    public function testHealth(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $health = $db->health();

        $this->assertIsInt($health);
        $this->assertEquals(200, $health);

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testVersion(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $version = $db->version();

        $this->assertIsString($version);
        $this->assertStringStartsWith("surrealdb-", $version);

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testToken(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $db->auth->setToken("sometoken");
        $token = $db->auth->getToken();

        $this->assertEquals("sometoken", $token);

        $db->auth->setToken(null);
        $this->assertNull($db->auth->getToken());

        $db->close();
    }

    /**
     * @throws SurrealException
     * @throws Exception
     */
//    public function testInfo(): void
//    {
//        $token = self::$db->signin([
//            "email" => "beau@user.nl",
//            "pass" => "123!",
//            "NS" => "test",
//            "DB" => "test",
//            "SC" => "account"
//        ]);
//
//        self::$db->auth->setScope("account");
//        self::$db->auth->setToken($token);
//
//        $info = self::$db->info();
//
//        $this->assertIsArray($info);
//
//        $this->assertArrayHasKey("email", $info);
//        $this->assertArrayHasKey("id", $info);
//        $this->assertArrayHasKey("pass", $info);
//
//        $this->assertInstanceOf(RecordId::class, $info["id"]);
//    }
}