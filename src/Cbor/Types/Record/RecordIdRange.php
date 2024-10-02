<?php

namespace Surreal\Cbor\Types\Record;

use JsonSerializable;
use Surreal\Cbor\Helpers\RangeHelper;
use Surreal\Cbor\Helpers\RecordIdHelper;
use Surreal\Cbor\Types\Bound\BoundExcluded;
use Surreal\Cbor\Types\Bound\BoundIncluded;
use Surreal\Cbor\Types\Table;
use Surreal\Exceptions\SurrealException;

class RecordIdRange implements JsonSerializable
{
    public Table $table;
    public BoundIncluded|BoundExcluded $begin;
    public BoundIncluded|BoundExcluded $end;

    /**
     * @throws SurrealException
     */
    public function __construct(
        Table|string                $table,
        BoundIncluded|BoundExcluded $begin,
        BoundIncluded|BoundExcluded $end
    )
    {
        $this->table = match (true) {
            is_string($table) => new Table($table),
            $table instanceof Table => $table,
            default => throw new SurrealException("Invalid table")
        };

        $this->begin = match(true) {
            RangeHelper::isValidIdBound($begin) => $begin,
            default => throw new SurrealException("Invalid bound")
        };

        $this->end = match(true) {
            RangeHelper::isValidIdBound($end) => $end,
            default => throw new SurrealException("Invalid bound")
        };
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        $table = RecordIdHelper::escapeIdent($this->table->toString());

        $begin = RangeHelper::escapeIdBound($this->begin);
        $end = RangeHelper::escapeIdBound($this->end);

        return $table . ":" . $begin . RangeHelper::getRangeJoin($this->begin, $this->end) . $end;
    }
}