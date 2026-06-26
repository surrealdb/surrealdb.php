<?php

namespace SurrealDB\SDK\Exceptions;

/** Thrown when the server returns a response the SDK cannot interpret. */
final class UnexpectedServerResponseException extends SurrealException
{
    public function __construct(public readonly mixed $response = null)
    {
        $encoded = json_encode($response);
        parent::__construct(
            'The server returned an unexpected response: ' . ($encoded === false ? '(unencodable)' : $encoded),
        );
    }
}
