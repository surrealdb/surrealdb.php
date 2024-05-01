<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Client\SurrealHTTP;

class AuthTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testScopeAuth(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $token = $db->signup([
            "email" => "beau.one",
            "pass" => "beau.one",
            "NS" => "test",
            "DB" => "test",
            "SC" => "account"
        ]);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $token = $db->signin([
            "email" => "beau.one",
            "pass" => "beau.one",
            "ns" => "test",
            "db" => "test",
            "sc" => "account"
        ]);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testSigninRoot(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $token = $db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        $this->assertIsString($token);

        $db->close();
    }

    public function testSigninNamespace(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $token = $db->signin([
            "user" => "julian",
            "pass" => "123!",
            "ns" => "test"
        ]);

        $this->assertIsString($token);

        $db->close();
    }

    /**
     *
     * @throws Exception
     */
    public function testSigninDatabase(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $token = $db->signin([
            "user" => "beau",
            "pass" => "123!",
            "ns" => "test",
            "db" => "test"
        ]);

        $this->assertIsString($token);

        $db->close();
    }

    public function testInvalidate(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $this->assertNull($db->auth->getToken());
    }
}