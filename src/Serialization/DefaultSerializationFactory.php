<?php

namespace Surreal\Serialization;

use JsonMapper;
use JsonMapper_Exception;

class DefaultSerializationFactory implements SerializationFactoryContract
{
	protected JsonMapper $mapper;

	public function __construct()
	{
		$this->mapper                                = new JsonMapper();
		$this->mapper->bEnforceMapType               = false;
		$this->mapper->bExceptionOnUndefinedProperty = false;
	}

	/**
	 * @param object|array $data
	 * @param string       $classFqn
	 *
	 * @return mixed
	 * @throws JsonMapper_Exception
	 */
	public function deserialize(object|array $data, string $classFqn): mixed
	{
		// if (is_array($data)) {
		// 	return $this->mapper->mapArray($data, [], new $classFqn());
		// }

		return $this->mapper->map($data, new $classFqn());
	}

	/**
	 * @return SerializationFactoryContract
	 */
	public function createSerializer(): SerializationFactoryContract
	{
		return new DefaultSerializationFactory();
	}
}
