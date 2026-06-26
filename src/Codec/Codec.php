<?php

namespace SurrealDB\SDK\Codec;

final readonly class Codec
{
	const DEFAULT_SERIALIZER = "cbor";
	const DEFAULT_DESERIALIZER = "cbor";

	public function __construct(
		public SerializerInterface $serializer,
		public DeserializerInterface $deserializer,
	) {}

    public static function json(): self
    {
        return new self(new JsonSerializer(), new JsonDeserializer());
    }

    public static function cbor(): self
    {
        return new self(new CborSerializer(), new CborDeserializer());
    }

	public function serialize(mixed $data): string
	{
		return $this->serializer->serialize($data);
	}

	public function deserialize(string $data): mixed
	{
		return $this->deserializer->deserialize($data);
	}
}
