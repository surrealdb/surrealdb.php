<?php

require_once "vendor/autoload.php";

use Surreal\Surreal;

$db = new Surreal();

$db->connect("http://127.0.0.1:8000", [
    "namespace" => "test",
    "database" => "test",
    "versionCheck" => true
]);

$setup_file_path = __DIR__ . "/assets/setup.surql";
$setup_file = file_get_contents($setup_file_path);

try {
    $db->import($setup_file, "root", "root");
} catch (Exception $e) {
    echo $e->getMessage();
} finally {
    $db->close();
}