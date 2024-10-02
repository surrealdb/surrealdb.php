<?php

namespace Surreal\Cbor\Types\Record;

final readonly class StringRecordId implements \JsonSerializable
{
    public string $recordId;

    public function __construct(string $recordId)
    {
        $this->recordId = $recordId;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return $this->recordId;
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function equals(StringRecordId $recordId): bool
    {
        return $this->recordId === $recordId->recordId;
    }

    public static function create(string $recordId): StringRecordId
    {
        return new StringRecordId($recordId);
    }
}