<?php

namespace Surreal\WebService;

use Illuminate\Support\Collection;
use Surreal\Config\ConfigContract;
use Surreal\Responses\ApiErrorResponse;
use Surreal\WebService\RpcMessages\RpcMessageContract;

interface WebServiceContract
{
	public function __construct(ConfigContract $config);

	public static function getInstance(): WebServiceContract;

	public static function getOrCreateService(ConfigContract $config): WebServiceContract;

	public function prepareRequest(): WebServiceContract;

	public function send(RpcMessageContract $message): Collection|ApiErrorResponse;
}
