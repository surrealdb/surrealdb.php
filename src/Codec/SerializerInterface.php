<?php

namespace SurrealDB\SDK\Codec;

interface SerializerInterface
{
	public function serialize(mixed $data): string;
}
