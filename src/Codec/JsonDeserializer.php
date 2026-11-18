<?php

namespace SurrealDB\Codec;

/**
 * The default, zero-dependency deserializer. Decodes JSON wire payloads into
 * associative PHP arrays.
 */
final class JsonDeserializer implements DeserializerInterface
{
    public function deserialize(string $data): mixed
    {
        if ($data === '') {
            return null;
        }

        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
}
