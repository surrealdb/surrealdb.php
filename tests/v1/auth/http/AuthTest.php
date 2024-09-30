<?php

namespace v2\auth\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\None;
use Surreal\Surreal;

class AuthTest extends TestCase
{
    private function getDb(): Surreal
    {
        $db = new Surreal();
        
        $db->connect("http://localhost:8000", [
            "namespace" => "test",
            "database" => "test"
        ]);

        return $db;
    }

    /**
     * @throws Exception
     */
    public function testScopeAuth(): void
    {
        $db = $this->getDb();

        /** @var ?string $token */
        $token = null;

        $token = $db->signup([
            "email" => "beau.one",
            "pass" => "beau.one",
            "namespace" => "test",
            "database" => "test",
            "scope" => "account"
        ]);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $token = $db->signin([
            "email" => "beau.one",
            "pass" => "beau.one",
            "namespace" => "test",
            "database" => "test",
            "scope" => "account"
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
        $db = $this->getDb();

        $token = $db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        $this->assertIsString($token);

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testSigninNamespace(): void
    {
        $db = $this->getDb();

        $jwt = $db->signin([
            "user" => "julian",
            "pass" => "123!",
            "namespace" => "test"
        ]);

        $this->assertIsString($jwt);

        $db->close();
    }

    /**
     *
     * @throws Exception
     */
    public function testSigninDatabase(): void
    {
        $db = $this->getDb();

        $token = $db->signin([
            "user" => "beau",
            "pass" => "123!",
            "namespace" => "test",
            "database" => "test"
        ]);

        $this->assertIsString($token);

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testInvalidate(): void
    {
        $db = $this->getDb();
        $this->assertInstanceOf(None::class, $db->invalidate());
        $db->close();
    }
}