<?php

namespace Surreal\Core\Results;

use Surreal\Core\Responses\ResponseInterface;

interface ResultInterface
{
    /**
     * Parser the response into a result.
     * @param ResponseInterface $response
     * @return mixed
     */
    public static function from(ResponseInterface $response): mixed;
}