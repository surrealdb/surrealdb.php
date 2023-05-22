<?php

namespace Surreal\WebService\RpcMessages;


class CreateRpcMessage extends RpcMessage implements RpcMessageContract
{
	public function __construct(string $thing, array $data)
	{
		parent::__construct('create', [$thing, $data]);
	}

}
