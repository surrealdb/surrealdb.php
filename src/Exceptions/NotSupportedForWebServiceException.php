<?php

namespace Surreal\Exceptions;

use Exception;

class NotSupportedForWebServiceException extends Exception
{
	public function __construct($operation)
	{
		parent::__construct("Operation $operation is not supported for http web service");
	}

}
