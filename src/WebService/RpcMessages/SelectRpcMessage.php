<?php

namespace Surreal\WebService\RpcMessages;


class SelectRpcMessage extends RpcMessage implements RpcMessageContract
{
	public function __construct(string $thing)
	{
		parent::__construct('select', [$thing]);
	}

}
