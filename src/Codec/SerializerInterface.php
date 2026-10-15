<?php

namespace SurrealDB\Codec;

interface SerializerInterface
{
	public function serialize(mixed $data): string;
}
