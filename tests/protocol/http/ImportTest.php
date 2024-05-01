<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Client\SurrealHTTP;
use Surreal\Exceptions\AuthException;
use Surreal\Exceptions\SurrealException;

class ImportTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testImport(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $file = __DIR__ . "/../../assets/import.surql";
        $file = file_get_contents($file);

        $result = $db->import($file, "root", "root");

        $this->assertIsArray($result);

        $db->close();
    }

    public function testImportWithWrongCredentials(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $file = __DIR__ . "/../../assets/import.surql";
        $file = file_get_contents($file);

        try {
            $result = $db->import($file, "root", "wrong");
        } catch (SurrealException $e) {
            $this->assertInstanceOf(SurrealException::class, $e);
        } catch (AuthException $e) {
            $this->assertInstanceOf(AuthException::class, $e);
        } finally {
            $db->close();
        }
    }
}