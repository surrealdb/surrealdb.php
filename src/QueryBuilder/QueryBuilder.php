<?php

namespace Surreal\QueryBuilder;

use Surreal\Model\SurrealModel;
use Surreal\Responses\ApiQueryResponse;

/**
 * @template T of SurrealModel
 */
class QueryBuilder
{
	/** @var WhereStatement[] */
	private array $wheres = [];
	/** @var QueryParameter[] */
	private array $parameters = [];

	/** @var array<string> */
	private array $select = [];

	/** @var array<string> */
	private array $from = [];

	/** @var array<string> */
	private array $groupBy = [];

	/** @var array<QueryOrder> */
	private array $orderBy = [];

	private int|null $limit   = null;
	private int|null $startAt = null;

	/** @var array<string>|null */
	private ?array $fetch = null;

	private int|null $timeout = null;

	private bool|null $parallel = null;

	public function __construct(protected SurrealModel $model) { }

	/**
	 * @param string ...$projections
	 *
	 * @return QueryBuilder<T>
	 */
	public function select(string ...$projections): static
	{
		$this->select = array_merge($this->select, $projections);

		return $this;
	}

	/**
	 * @param string ...$targets
	 *
	 * @return QueryBuilder<T>
	 */
	public function from(string ...$targets): static
	{
		$this->from = array_merge($this->from, $targets);

		return $this;
	}

	/**
	 * @param string     $field
	 * @param mixed|null $operator
	 * @param mixed|null $value
	 *
	 * @return QueryBuilder<T>
	 */
	public function where(string $field, mixed $operator = null, mixed $value = null): static
	{
		// If there's only two args, we'll make an assumption that it's an "field = value" query
		if (func_num_args() === 2) {
			$value    = $operator;
			$operator = '=';
		} // If there's only one arg, we'll make an assumption that it's an "id = value" query
		else {
			if (func_num_args() === 1) {
				$value    = $field;
				$operator = '=';
				$field    = 'id';
			}
		}

		$this->wheres[] = new WhereStatement(
			field: $field,
			operator: $operator,
			value: $value,
			name: $this->addParameter($field, $value),
		);

		return $this;
	}

	private function addParameter(string $originalFieldName, mixed $value): string
	{
		$parameterName = $originalFieldName . '_' . count($this->parameters);

		$this->parameters[$parameterName] = new QueryParameter(
			field: $originalFieldName,
			value: $value,
			name: $parameterName,
		);

		return $parameterName;
	}

	// public function split($fields)
	// {
	// 	$this->queryParts['split'] = is_array($fields) ? implode(", ", $fields) : $fields;
	//
	// 	return $this;
	// }

	/**
	 * @param array ...$fields
	 *
	 * @return QueryBuilder<T>
	 */
	public function groupBy(array ...$fields): static
	{
		$this->groupBy = array_merge($this->groupBy, $fields);

		return $this;
	}

	/**
	 * @param int $limit
	 *
	 * @return QueryBuilder<T>
	 */
	public function limit(int $limit): static
	{
		$this->limit = $limit;

		return $this;
	}

	/**
	 * @param string              $field
	 * @param QueryOrderDirection $direction
	 *
	 * @return QueryBuilder<T>
	 */
	public function orderBy(string $field, QueryOrderDirection $direction = QueryOrderDirection::ASC): static
	{
		$this->orderBy[] = new QueryOrder(
			field: $field,
			direction: $direction,
		);

		return $this;
	}

	/**
	 * @param string ...$fields
	 *
	 * @return QueryBuilder<T>
	 */
	public function fetch(string ...$fields): static
	{
		$this->fetch = array_merge($this->fetch, $fields);

		return $this;
	}

	// public function startAt($start)
	// {
	// 	$this->queryParts['startAt'] = $start;
	//
	// 	return $this;
	// }
	//

	//
	// public function timeout($duration)
	// {
	// 	$this->queryParts['timeout'] = $duration;
	//
	// 	return $this;
	// }

	/**
	 * @param bool $value
	 *
	 * @return QueryBuilder<T>
	 */
	public function parallel(bool $value = true): static
	{
		$this->parallel = $value;

		return $this;
	}

	/**
	 * @return array{
	 *     sql: string,
	 *     parameters: array<string, mixed>
	 * }
	 */
	public function toSql(): array
	{
		$parameters = [];

		if (empty($this->select)) {
			$this->select = ['*'];
		}
		if (empty($this->from)) {
			$this->from = [$this->model->getTableName()];
		}

		$query = 'SELECT ' . implode(', ', $this->select) . ' FROM ' . implode(', ', $this->from);

		/**
		 * Add WHERE statements
		 */
		if (!empty($this->wheres)) {
			$wheres = [];
			foreach ($this->wheres as $where) {
				$value = $where->value;

				if ($parameter = $this->parameters[$where->name] ?? null) {
					$value                    = '$' . $parameter->name;
					$parameters[$where->name] = $parameter->value;
				}

				$wheres[] = $where->field . ' ' . $where->operator . ' ' . $value;
			}
			$query .= ' WHERE ' . implode(" AND ", $wheres);
		}

		// if (isset($this->queryParts['split'])) {
		// 	$query .= ' SPLIT ' . $this->queryParts['split'];
		// }
		//

		/**
		 * Add GROUP BY statement
		 */
		if (!empty($this->groupBy)) {
			$query .= ' GROUP BY ' . implode(", ", $this->groupBy);
		}

		/**
		 * Add ORDER BY statement
		 */
		if (!empty($this->orderBy)) {
			$query .= ' ORDER BY ' . implode(", ", array_map(function (QueryOrder $order) {
					return $order->field . ' ' . $order->direction;
				}, $this->orderBy));
		}

		/**
		 * Add LIMIT statement
		 */
		if ($this->limit !== null) {
			$query .= ' LIMIT ' . $this->limit;
		}

		// if (isset($this->queryParts['startAt'])) {
		// 	$query .= ' START AT ' . $this->queryParts['startAt'];
		// }

		/**
		 * Add FETCH statement
		 */
		if (!empty($this->fetch)) {
			$query .= ' FETCH ' . implode(", ", $this->fetch);
		}

		// if (isset($this->queryParts['timeout'])) {
		// 	$query .= ' TIMEOUT ' . $this->queryParts['timeout'];
		// }

		if ($this->parallel) {
			$query .= ' PARALLEL';
		}

		$query .= ';';


		return [$query, $parameters];
	}

	/**
	 * Run the query and obtain the raw response
	 *
	 * @return ApiQueryResponse<T>
	 */
	public function execute(): ApiQueryResponse
	{
		[$sql, $parameters] = $this->toSql();

		$results = $this->model::raw($sql, $parameters);

		if ($results->hasError()) {
			$results->throw();
		}

		return $results;
	}

	/**
	 * Run the query and obtain all models matching the query
	 *
	 * @return array<T>|null
	 */
	public function get(): ?array
	{
		return $this->execute()?->firstResult()?->all();
	}

	/**
	 * Run the query and obtain the first model matching the query
	 *
	 * @return T|null
	 */
	public function first(): mixed
	{
		$this->limit(1);

		return $this->execute()?->firstResult()?->first();
	}
}
