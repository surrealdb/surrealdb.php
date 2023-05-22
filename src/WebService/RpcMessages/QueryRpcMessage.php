<?php

namespace Surreal\WebService\RpcMessages;

use Surreal\QueryBuilder\QueryData;

class QueryRpcMessage extends RpcMessage implements RpcMessageContract
{
	public function __construct(QueryData $query)
	{
		$params = [$query->query];
		if (!empty($query->parameters)) {
			$params[] = $query->parameters;
		}

		parent::__construct('query', $params);
	}

}
