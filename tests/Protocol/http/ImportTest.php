<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Exceptions\AuthException;
use Surreal\Exceptions\SurrealException;
use Surreal\Surreal;

class ImportTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testImport(): void
    {
        $db = new Surreal();
        $db->connect("http://localhost:8000", [
            "namespace" => "test",
            "database" => "test"
        ]);

        $file = __DIR__ . "/../../assets/import.surql";
        $file = file_get_contents($file);

        $result = $db->import($file, "root", "root");

        $this->assertIsArray($result);

        $db->close();
    }

    public function testImportWithWrongCredentials(): void
    {
        $db = new Surreal();
        $db->connect("http://localhost:8000", [
            "namespace" => "test",
            "database" => "test"
        ]);

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