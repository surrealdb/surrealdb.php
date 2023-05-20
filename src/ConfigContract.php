<?php

namespace Surreal;

use Surreal\Serialization\SerializationFactoryContract;

interface ConfigContract
{

	/**
	 * @return WebServiceContract|null
	 */
	public function getWebService(): ?WebServiceContract;

	/**
	 * @param WebServiceContract|null $webService
	 *
	 * @return ConfigContract
	 */
	public function webservice(?WebServiceContract $webService): ConfigContract;

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
	 * @return ConfigContract
	 */
	public function url(?string $url): ConfigContract;

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
