<?php

require_once "vendor/autoload.php";

use Surreal\Surreal;

$db = new Surreal("http://localhost:8000");
$db->connect();
$db->use(["namespace" => "test", "database" => "test"]);

$setup_file_path = __DIR__ . "/assets/setup.surql";
$setup_file = file_get_contents($setup_file_path);

try {
    $db->import($setup_file, "root", "root");
} catch (Exception $e) {
    echo $e->getMessage();
} finally {
    $db->disconnect();
}