<?php

namespace Surreal\QueryBuilder;

class QueryParameter
{
	public function __construct(
		public string $field,
		public mixed $value,
		public string $name,
	) {
	}
}
