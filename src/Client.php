<?php

namespace Surreal;

use Surreal\Config\ConfigContract;
use Surreal\Exceptions\AuthenticationFailedException;
use Surreal\Exceptions\FailedRpcResponseError;
use Surreal\Exceptions\NotSupportedForWebServiceException;
use Surreal\QueryBuilder\QueryData;
use Surreal\Responses\ApiQueryResponse;
use Surreal\Serialization\DefaultSerializationFactory;
use Surreal\Serialization\SerializationFactoryContract;
use Surreal\WebService\BaseWebService;
use Surreal\WebService\Data\SigninData;
use Surreal\WebService\Data\SignupData;
use Surreal\WebService\JsonRpcWebService;
use Surreal\WebService\RpcMessages\CreateRpcMessage;
use Surreal\WebService\RpcMessages\QueryRpcMessage;
use Surreal\WebService\RpcMessages\RpcMessage;
use Surreal\WebService\RpcMessages\SelectRpcMessage;
use Surreal\WebService\RpcMessages\SigninRpcMessage;
use Surreal\WebService\RpcMessages\SignupRpcMessage;
use Surreal\WebService\RpcMessages\UpdateRpcMessage;
use Surreal\WebService\RpcMessages\UseRpcMessage;
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

	public static function getSerializer(): SerializationFactoryContract
	{
		if (self::$config?->getSerializerFactory() === null) {
			throw new \Exception('Serializer factory is not set');
		}

		return self::$config->getSerializerFactory()->createSerializer();
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

	private static function isUsingJsonRpc(): bool
	{
		return self::$config->getWebService() === JsonRpcWebService::class;
	}

	public static function use(?string $ns, ?string $db): ApiQueryResponse
	{
		if (!self::isUsingJsonRpc()) {
			throw new NotSupportedForWebServiceException('use');
		}

		$response = self::getWebService()
			->prepareRequest()
			->send(new UseRpcMessage($ns, $db));


		return new ApiQueryResponse($response);
	}

	public static function signup(SignupData $data): ?string
	{
		if (!self::isUsingJsonRpc()) {
			throw new NotSupportedForWebServiceException('signup');
		}

		$response = self::getWebService()
			->prepareRequest()
			->send(new SignupRpcMessage($data));

		$result = new ApiQueryResponse($response);

		if ($result->hasError()) {
			throw new AuthenticationFailedException($result->getError());
		}


		return $result->firstResult()?->first();
	}

	public static function signin(SigninData $data)
	{
		if (!self::isUsingJsonRpc()) {
			throw new NotSupportedForWebServiceException('signin');
		}

		$response = self::getWebService()
			->prepareRequest()
			->send(new SigninRpcMessage($data));

		$result = new ApiQueryResponse($response);

		if ($result->hasError()) {
			throw new AuthenticationFailedException($result->getError());
		}


		return $result->firstResult()?->first();
	}

	public static function invalidate(): void
	{
		if (!self::isUsingJsonRpc()) {
			throw new NotSupportedForWebServiceException('signin');
		}

		$response = self::getWebService()
			->prepareRequest()
			->send(new RpcMessage('invalidate'));

		$result = new ApiQueryResponse($response);

		if ($result->hasError()) {
			throw new FailedRpcResponseError($result->getErrorInformation(), $result->getErrorCode());
		}
	}

	/**
	 * @template T of class-string
	 *
	 * @param string $thing
	 * @param T|null $model
	 *
	 * @return array<T>
	 */
	public static function select(string $thing, mixed $model = null): array
	{
		$response = self::getWebService()
			->prepareRequest()
			->send(new SelectRpcMessage($thing));

		$result = new ApiQueryResponse($response, $model, true);

		return $result->getAllItems();
	}

	/**
	 * @template T of class-string
	 *
	 * @param string $thing
	 * @param array  $data
	 * @param T|null $model
	 *
	 * @return T|object
	 */
	public static function create(string $thing, array $data, mixed $model = null): mixed
	{
		$response = self::getWebService()
			->prepareRequest()
			->send(new CreateRpcMessage($thing, $data));

		$result = new ApiQueryResponse($response, $model, true);

		return $result->getAllItems()[0] ?? null;
	}

	/**
	 * @template T of class-string
	 *
	 * @param string|Thing $thing
	 * @param array        $data
	 * @param T|null       $model
	 *
	 * @return T|object
	 */
	public static function update(string|Thing $thing, array $data, mixed $model = null): mixed
	{
		$thing = thing($thing);

		$response = self::getWebService()
			->prepareRequest()
			->send(new UpdateRpcMessage($thing, $data));

		$result = new ApiQueryResponse($response, $model, true);

		if($thing->hasId()) {
			return $result->first();
		}

		return $result->getAllItems();
	}


}
