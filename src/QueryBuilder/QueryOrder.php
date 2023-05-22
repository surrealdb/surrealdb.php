<?php

namespace Surreal\QueryBuilder;

class QueryOrder
{
	public function __construct(
		public string $field,
		public QueryOrderDirection $direction,
	) {
	}
}
