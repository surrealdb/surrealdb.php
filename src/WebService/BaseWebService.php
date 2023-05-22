<?php

namespace Surreal\WebService;

use Illuminate\Support\Collection;
use Surreal\Config\ConfigContract;
use Surreal\Responses\ApiErrorResponse;
use Surreal\WebService\RpcMessages\RpcMessageContract;

abstract class BaseWebService implements WebServiceContract
{
	protected static ?WebServiceContract $instance = null;

	protected ConfigContract $config;

	public function __construct(ConfigContract $config)
	{
		$this->config = $config;

		if (!$this->shouldReconstructPerRequest() && self::$instance !== null) {
			throw new \Exception('Cannot instantiate more than one instance of a singleton web service.');
		}

		self::$instance = $this;
	}

	public static function getInstance(): WebServiceContract
	{
		return self::$instance;
	}

	public static function getOrCreateService(ConfigContract $config): WebServiceContract
	{
		if (self::$instance !== null && self::$instance::class !== static::class) {
			self::$instance = null;
		}

		if (self::$instance === null || self::$instance?->shouldReconstructPerRequest()) {
			return new static($config);
		}

		return self::$instance;
	}

	public abstract function shouldReconstructPerRequest(): bool;

	public abstract function prepareRequest(): WebServiceContract;

	public abstract function send(RpcMessageContract $message): Collection|ApiErrorResponse;

}
