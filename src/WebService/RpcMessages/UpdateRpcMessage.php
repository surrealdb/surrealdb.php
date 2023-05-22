<?php

namespace Surreal\WebService\RpcMessages;


class UpdateRpcMessage extends RpcMessage implements RpcMessageContract
{
	public function __construct(string $thing, array $data)
	{
		parent::__construct('update', [$thing, $data]);
	}

}
