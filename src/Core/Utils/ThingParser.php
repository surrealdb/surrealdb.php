<?php

namespace Surreal\Core\Utils;

use InvalidArgumentException;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\Table;

final readonly class ThingParser
{
    public RecordId|Table $value;

    private function __construct(string|RecordId|Table $thing)
    {
        if(is_string($thing)) {
            $this->value = match(true) {
                str_contains($thing, ':') => RecordId::fromString($thing),
                default => Table::fromString($thing)
            };
        } else {
            $this->value = $thing;
        }
    }

    public static function from(string|RecordId|Table $thing): self
    {
        return new self($thing);
    }

    public function isTable(): bool
    {
        return $this->value instanceof Table;
    }

    public function isRecordId(): bool
    {
        return $this->value instanceof RecordId;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function toString(): string
    {
        return (string) $this->value;
    }

    public function getTable(): Table
    {
        if($this->value instanceof RecordId) {
            return self::from($this->value->getTable())->getTable();
        }

        return $this->value;
    }

    public function getRecordId(): RecordId
    {
        if(!$this->isRecordId()) {
            throw new InvalidArgumentException("Thing is not a RecordId");
        }

        return $this->value;
    }
}