<?php

namespace SurrealDB\SDK\Contracts;

interface SurrealType
{
	/**
	 * Escape the value for use in a SurrealDB query.
	 * @return string
	 */
	public function escape(): string;
}
