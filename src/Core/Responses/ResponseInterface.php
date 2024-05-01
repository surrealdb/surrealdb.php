<?php

namespace Surreal\Core\Responses;

use Surreal\Curl\HttpContentType;

interface ResponseInterface
{
    /**
     * Parse the response body and return a new instance of the class
     * @param mixed $data
     * @param HttpContentType $type
     * @param int $status
     */
    public static function from(mixed $data, HttpContentType $type, int $status);

    /**
     * Returns the response from the request.
     * @return mixed
     */
    public function data(): mixed;
}