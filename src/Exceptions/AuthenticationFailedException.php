<?php

namespace Surreal\Exceptions;

use Exception;
use Surreal\Responses\ApiErrorResponse;

class AuthenticationFailedException extends Exception
{
	public ApiErrorResponse $response;

	public function __construct(ApiErrorResponse $response)
	{
		parent::__construct('Authentication failed', $response->code);

		$this->response = $response;
	}

}
