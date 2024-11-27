<?php

namespace Surreal\Cbor\Types\Record;

use Surreal\Cbor\Interfaces\RecordInterface;

final readonly class StringRecordId implements RecordInterface
{
    /** @var string $recordId */
    public string $recordId;

    public function __construct(string|StringRecordId|RecordId $recordId)
    {
        $this->recordId = match(true) {
            $recordId instanceof StringRecordId => $recordId->toString(),
            $recordId instanceof RecordId => $recordId->toString(),
            default => $recordId
        };
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
        return $this->recordId === $recordId->toString();
    }

    public static function create(string $recordId): StringRecordId
    {
        return new StringRecordId($recordId);
    }
}