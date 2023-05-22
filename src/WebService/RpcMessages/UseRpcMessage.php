<?php

namespace Surreal\WebService\RpcMessages;

class UseRpcMessage extends RpcMessage implements RpcMessageContract
{
	public function __construct(string $ns, string $db)
	{
		parent::__construct('use', [$ns, $db]);
	}

}
