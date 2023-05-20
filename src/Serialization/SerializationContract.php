<?php

namespace Surreal\Serialization;

interface SerializationContract
{
	/**
	 * @template T of class-string
	 *
	 * @param object|array $data
	 * @param class-string $classFqn
	 *
	 * @return T
	 */
	public function deserialize(object|array $data, string $classFqn): mixed;
}
