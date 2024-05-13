<?php

namespace Surreal\Cbor\Types;

final readonly class StringRecordId implements \JsonSerializable
{
    public string $recordId;

    public function __construct(string $recordId)
    {
        $this->recordId = $recordId;
    }

    public function __toString(): string
    {
        return $this->recordId;
    }

    public function jsonSerialize(): string
    {
        return $this->recordId;
    }
}