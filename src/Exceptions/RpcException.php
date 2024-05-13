<?php

namespace Surreal\Exceptions;

use Exception;

class RpcException extends Exception
{
    public function __construct(string $message, int $code = 500)
    {
        parent::__construct("RpcException: " . $message, $code);
    }
}