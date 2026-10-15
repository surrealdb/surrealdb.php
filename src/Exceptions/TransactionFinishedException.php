<?php

namespace SurrealDB\SDK\Exceptions;

/** Thrown when a transaction is used after it was committed or cancelled. */
final class TransactionFinishedException extends SurrealException
{
    public function __construct(public readonly string $transaction)
    {
        parent::__construct('The transaction has already been committed or cancelled: ' . $transaction);
    }
}
