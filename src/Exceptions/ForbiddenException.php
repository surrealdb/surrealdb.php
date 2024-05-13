<?php

namespace Surreal\Exceptions;

use Exception;

class ForbiddenException extends Exception
{
	public function __construct(string $message)
	{
		parent::__construct("ForbiddenException: " . $message, 403);
	}
}
