<?php

namespace Surreal\WebService\RpcMessages;

use Illuminate\Support\Str;

class RpcMessage implements RpcMessageContract
{
	public string $id;

	public string $method;

	public array $params = [];

	public function __construct(string $method, array $params = [], ?string $id = null)
	{
		$this->id     = $id ?? Str::ulid();
		$this->method = $method;
		$this->params = $params;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	public function getParams(): array
	{
		return $this->params;
	}

	public function toArray(): array
	{
		return [
			'id'     => $this->id,
			'method' => $this->method,
			'params' => $this->params,
		];
	}

	public function toJson(): string
	{
		return json_encode($this->toArray());
	}
}
