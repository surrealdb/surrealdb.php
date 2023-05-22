<?php

namespace Surreal\WebService\RpcMessages;

interface RpcMessageContract
{
	public function getId(): string;

	public function getMethod(): string;

	public function getParams(): array;

	public function toArray(): array;

	public function toJson(): string;
}
