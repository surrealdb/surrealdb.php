<?php

namespace Surreal\Serialization;

class Serializer implements SerializationContract
{
	protected SerializationFactoryContract $factory;

	public function __construct(SerializationFactoryContract $factory)
	{
		$this->factory = $factory;
	}

	public function serialize(object $object): string
	{
		return json_encode($object);
	}

	/**
	 * @template T of class-string
	 *
	 * @param object|array       $data
	 * @param class-string $classFqn
	 *
	 * @return T
	 */
	public function deserialize(object|array $data, string $classFqn): mixed
	{
		return $this->factory->deserialize($classFqn, $data);
	}
}
