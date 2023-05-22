<?php

namespace Surreal\WebService\RpcMessages;

use Surreal\WebService\Data\SignupData;

class SignupRpcMessage extends RpcMessage implements RpcMessageContract
{
	public function __construct(SignupData $data)
	{
		parent::__construct('signup', [$data->toArray()]);
	}

}
