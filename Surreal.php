<?php
require __DIR__ . "/BaseSurreal.php";

class SurrealDB extends \surreal\BaseSurreal
{
}

spl_autoload_register("SurrealDB",true,true);
SurrealDB::$classMap = require __DIR__. "/classes.php";