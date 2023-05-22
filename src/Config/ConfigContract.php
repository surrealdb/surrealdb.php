<?php

namespace Surreal\Config;

use Surreal\Serialization\SerializationFactoryContract;
use Surreal\WebService\WebServiceContract;

interface ConfigContract extends BaseConfigContract
{

	/**
	 * @return string|null
	 */
	public function getWebService(): ?string;

	/**
	 * @param class-string|null $webService
	 *
	 * @return ConfigContract
	 */
	public function webservice(?string $webService): ConfigContract;

	/**
	 * @return string|null
	 */
	public function getPassword(): ?string;

	/**
	 * @return string|null
	 */
	public function getNamespace(): ?string;

	/**
	 * @param string|null $namespace
	 *
	 * @return ConfigContract
	 */
	public function namespace(?string $namespace): ConfigContract;

	/**
	 * @return string|null
	 */
	public function getDb(): ?string;

	/**
	 * @return string|null
	 */
	public function getUsername(): ?string;

	/**
	 * @param string|null $username
	 *
	 * @return ConfigContract
	 */
	public function username(?string $username): ConfigContract;

	/**
	 * @param string|null $db
	 *
	 * @return ConfigContract
	 */
	public function db(?string $db): ConfigContract;

	/**
	 * @param string|null $password
	 *
	 * @return ConfigContract
	 */
	public function password(?string $password): ConfigContract;

	/**
	 * @return string|null
	 */
	public function getUrl(): ?string;

	/**
	 * @param string|null $url
	 *
	 * @return ConfigContract|BaseConfigContract
	 */
	public function url(?string $url): ConfigContract|BaseConfigContract;

	/**
	 * @param SerializationFactoryContract|null $serializerFactory
	 *
	 * @return ConfigContract
	 */
	public function serializerFactory(?SerializationFactoryContract $serializerFactory): ConfigContract;

	/**
	 * @return SerializationFactoryContract|null
	 */
	public function getSerializerFactory(): ?SerializationFactoryContract;
}
