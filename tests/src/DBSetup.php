<?php

namespace src;

use Surreal\Surreal;

final class DBSetup
{
    public static function setup(
        array $protocols,
        array $auth
    ): void
    {
        $db = new Surreal();
        $db->connect("http://localhost:8000", $protocols);
    }
}