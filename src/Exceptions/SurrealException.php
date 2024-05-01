<?php

namespace Surreal\Exceptions;

use Exception;

class SurrealException extends Exception
{
	public function __construct(string $message, int $code = 500, Exception $previous = null)
	{
		parent::__construct("SurrealException: " . $message, $code, $previous);
	}
}