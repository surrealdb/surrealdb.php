<?php

namespace Surreal\WebService\RpcMessages;

use Surreal\WebService\Data\SignupData;

class SigninRpcMessage extends RpcMessage implements RpcMessageContract
{
	public function __construct(SignupData $data)
	{
		parent::__construct('signin', [$data->toArray()]);
	}

}
