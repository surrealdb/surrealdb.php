<?php

namespace Surreal\Exceptions;

use Exception;
use Surreal\Responses\ApiErrorResponse;
use Surreal\WebService\RpcMessages\RpcMessageContract;

class FailedRpcResponseError extends Exception
{

	protected ?RpcMessageContract $sentMessage = null;


	public function setSentMessage(?RpcMessageContract $sentMessage): self
	{
		$this->sentMessage = $sentMessage;

		return $this;
	}

	public function getSentMessage(): ?RpcMessageContract
	{
		return $this->sentMessage;
	}

}
