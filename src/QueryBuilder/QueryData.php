<?php

namespace Surreal\QueryBuilder;

class QueryData
{

	/**
	 * The query to be executed.
	 *
	 * @var string|null
	 */
	public ?string $query = null;

	/**
	 * The parameters to be passed to the query.
	 *
	 * @var array
	 */
	public array $parameters = [];

	public function __construct() { }

	public static function make(string $query, array $parameters = []): QueryData
	{
		$queryData = new static();

		$queryData->query      = $query;
		$queryData->parameters = $parameters;

		return $queryData;
	}
}
