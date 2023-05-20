<?php

namespace Surreal\QueryBuilder;

class WhereStatement
{
	public function __construct(
		public string $field,
		public string $operator,
		public mixed $value,
		public string $name,
	) { }
}
