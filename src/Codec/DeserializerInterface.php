<?php

namespace SurrealDB\SDK\Codec;

interface DeserializerInterface
{
	public function deserialize(string $data): mixed;
}
