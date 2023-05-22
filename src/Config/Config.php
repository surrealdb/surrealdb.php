<?php

namespace Surreal\Config;


use Surreal\Serialization\SerializationFactoryContract;
use Surreal\WebService\WebServiceContract;

class Config extends BaseConfig implements ConfigContract
{

	/**
	 * The class FQN of the web service to be used.
	 * @var class-string|null
	 */
	private ?string $webService = null;

	private ?string $url = null;

	private ?string $username = null;

	private ?string $password = null;

	private ?string $db = null;

	private ?string $namespace = null;

	private ?SerializationFactoryContract $serializerFactory = null;


	/**
	 * @return string|null
	 */
	public function getWebService(): ?string
	{
		return $this->webService;
	}

	/**
	 * @param class-string|null $webService
	 *
	 * @return ConfigContract
	 */
	public function webservice(?string $webService): ConfigContract
	{
		$this->webService = $webService;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getUsername(): ?string
	{
		return $this->username;
	}

	/**
	 * @param string|null $username
	 *
	 * @return ConfigContract
	 */
	public function username(?string $username): ConfigContract
	{
		$this->username = $username;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getPassword(): ?string
	{
		return $this->password;
	}

	/**
	 * @param string|null $password
	 *
	 * @return ConfigContract
	 */
	public function password(?string $password): ConfigContract
	{
		$this->password = $password;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getDb(): ?string
	{
		return $this->db;
	}

	/**
	 * @param string|null $db
	 *
	 * @return ConfigContract
	 */
	public function db(?string $db): ConfigContract
	{
		$this->db = $db;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getNamespace(): ?string
	{
		return $this->namespace;
	}

	/**
	 * @param string|null $namespace
	 *
	 * @return ConfigContract
	 */
	public function namespace(?string $namespace): ConfigContract
	{
		$this->namespace = $namespace;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getUrl(): ?string
	{
		return $this->url;
	}

	/**
	 * @param string|null $url
	 *
	 * @return ConfigContract
	 */
	public function url(?string $url): ConfigContract
	{
		$this->url = $url;

		return $this;
	}

	/**
	 * @return SerializationFactoryContract|null
	 */
	public function getSerializerFactory(): ?SerializationFactoryContract
	{
		return $this->serializerFactory;
	}

	/**
	 * @param SerializationFactoryContract|null $serializerFactory
	 *
	 * @return ConfigContract
	 */
	public function serializerFactory(?SerializationFactoryContract $serializerFactory): ConfigContract
	{
		$this->serializerFactory = $serializerFactory;

		return $this;
	}


}
