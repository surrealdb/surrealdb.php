<?php

namespace Surreal\Core\Responses;

use Surreal\Core\Curl\HttpContentFormat;

interface ResponseInterface
{
    /**
     * Parse the response body and return a new instance of the class
     * @param mixed $data
     * @param HttpContentFormat $type
     * @param int $status
     */
    public static function from(mixed $data, HttpContentFormat $type, int $status);

    /**
     * Returns the response from the request.
     * @return mixed
     */
    public function data(): mixed;
}