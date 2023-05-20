<?php

namespace Surreal\Model;

use Surreal\Client;
use Surreal\QueryBuilder\QueryBuilder;
use Surreal\Responses\ApiQueryResponse;

/**
 * @template T of SurrealModel|class-string
 */
class SurrealModel
{
	public function __construct()
	{
	}

	public function getTableName(): string
	{
		$name = strtolower((new \ReflectionClass($this))->getShortName());
		if (str_ends_with($name, 'model')) {
			$name = substr($name, 0, -5);
		}

		// Ensure the name is singular
		if (str_ends_with($name, 's')) {
			$name = substr($name, 0, -1);
		}

		return $name;
	}

	/**
	 * @param string $query
	 * @param array  $parameters
	 *
	 * @return ApiQueryResponse<T>
	 */
	public static function raw(string $query, array $parameters = []): ApiQueryResponse
	{
		return Client::queryModel(static::class, $query, $parameters);
	}


	/**
	 * @return QueryBuilder<T>
	 */
	public static function query(): QueryBuilder
	{
		return new QueryBuilder(new static());
	}

}
