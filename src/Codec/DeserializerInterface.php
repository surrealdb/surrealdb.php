<?php

namespace SurrealDB\Codec;

interface DeserializerInterface
{
	public function deserialize(string $data): mixed;
}
