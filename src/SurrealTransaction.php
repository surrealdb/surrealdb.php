<?php

namespace SurrealDB\SDK;

use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Exceptions\TransactionFinishedException;

/**
 * An interactive transaction. Every query made through this object, raw or
 * through a builder, runs inside the transaction until {@see self::commit()}
 * or {@see self::cancel()}; after that the object can no longer be used.
 *
 *     $txn = $db->beginTransaction();
 *     $txn->create(new RecordId('account', 'one'))->content(['balance' => 100])->execute();
 *     $txn->commit();
 *
 * Mirrors the JS SDK's `SurrealTransaction`.
 */
final class SurrealTransaction extends SurrealQueryable
{
    private bool $finished = false;

    public function __construct(
        ConnectionController $connection,
        ?string $sessionId,
        private readonly string $transactionId,
    ) {
        parent::__construct($connection, $sessionId);
    }

    /** The transaction id assigned by the server. */
    public function id(): string
    {
        return $this->transactionId;
    }

    /** The session the transaction belongs to, or null for the default session. */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Whether the transaction is over: committed or cancelled, or ended on
     * the server by resetting or closing its session, or by losing the
     * connection.
     */
    public function isFinished(): bool
    {
        return $this->finished || !$this->connection->isTransactionOpen($this->transactionId, $this->sessionId);
    }

    /**
     * Commit the transaction. Its changes become visible to other sessions.
     */
    public function commit(): void
    {
        // A server error ends the transaction too; the connection tracks that.
        $this->connection->commit($this->transactionId(), $this->sessionId);
        $this->finished = true;
    }

    /**
     * Cancel the transaction, discarding its changes.
     */
    public function cancel(): void
    {
        $this->connection->cancel($this->transactionId(), $this->sessionId);
        $this->finished = true;
    }

    protected function transactionId(): string
    {
        if ($this->isFinished()) {
            throw new TransactionFinishedException($this->transactionId);
        }

        return $this->transactionId;
    }
}
