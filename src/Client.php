<?php

namespace Surreal;

use Surreal\Config\ConfigContract;
use Surreal\Exceptions\FailedRpcResponseError;
use Surreal\QueryBuilder\QueryData;
use Surreal\Responses\ApiQueryResponse;
use Surreal\Serialization\DefaultSerializationFactory;
use Surreal\Serialization\SerializationFactoryContract;
use Surreal\WebService\BaseWebService;
use Surreal\WebService\RpcMessages\QueryRpcMessage;
use Surreal\WebService\WebService;
use Surreal\WebService\WebServiceContract;

class Client
{
	private static ?ConfigContract $config = null;

	public static function configure(ConfigContract $config): void
	{
		if ($config->getWebService() === null) {
			$config->webservice(WebService::class);
		}

		if ($config->getSerializerFactory() === null) {
			$config->serializerFactory(new DefaultSerializationFactory());
		}

		[$configIsValid, $errorMessage] = self::validateConfig($config);
		if (!$configIsValid) {
			throw new \InvalidArgumentException($errorMessage);
		}

		self::$config = $config;
	}

	/**
	 * @param ConfigContract $config
	 *
	 * @return array{0:bool,1:string}
	 */
	private static function validateConfig(ConfigContract $config): array
	{
		if ($config->getUrl() === null) {
			return [false, 'Url is required'];
		}

		if ($config->getDb() === null) {
			return [false, 'Database name is required'];
		}

		if ($config->getNamespace() === null) {
			return [false, 'Namespace is required'];
		}

		if ($config->getUsername() === null) {
			return [false, 'Username is required'];
		}

		if ($config->getPassword() === null) {
			return [false, 'Password is required'];
		}

		if (!class_exists($config->getWebService())) {
			return [false, 'Web service class does not exist'];
		}

		return [true, ''];
	}

	public static function getConfig(): ConfigContract
	{
		if (self::$config === null) {
			throw new \RuntimeException('Client is not configured');
		}

		return self::$config;
	}

	public static function getWebService(): WebServiceContract
	{
		/** @var BaseWebService $service */
		$service = self::$config->getWebService();

		return $service::getOrCreateService(self::$config);
	}

	/**
	 * @template T of class-string
	 *
	 * @param string $sql
	 * @param array  $parameters
	 * @param T|null $model
	 *
	 * @return ApiQueryResponse
	 */
	public static function query(string $sql, array $parameters = [], mixed $model = null): ApiQueryResponse
	{
		$response = self::getWebService()
			->prepareRequest()
			->send(new QueryRpcMessage(QueryData::make($sql, $parameters)));

		return new ApiQueryResponse($response, $model);
	}

	/**
	 * @template T of class-string
	 *
	 * @param T      $model
	 * @param string $sql
	 * @param array  $parameters
	 *
	 * @return ApiQueryResponse<T>
	 */
	public static function queryModel(mixed $model, string $sql, array $parameters = []): ApiQueryResponse
	{
		return self::query($sql, $parameters, $model);
	}

	public static function getSerializer(): SerializationFactoryContract
	{
		if (self::$config?->getSerializerFactory() === null) {
			throw new \Exception('Serializer factory is not set');
		}

		return self::$config->getSerializerFactory()->createSerializer();
	}


}
