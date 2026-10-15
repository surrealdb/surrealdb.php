<?php

namespace SurrealDB\SDK;

use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Exceptions\ServerException;
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

    /** Whether the transaction has been committed or cancelled. */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Commit the transaction. Its changes become visible to other sessions.
     */
    public function commit(): void
    {
        $this->finish(fn (string $id) => $this->connection->commit($id, $this->sessionId));
    }

    /**
     * Cancel the transaction, discarding its changes.
     */
    public function cancel(): void
    {
        $this->finish(fn (string $id) => $this->connection->cancel($id, $this->sessionId));
    }

    /**
     * Once the server has answered, the transaction is over even if the
     * answer is an error (a commit that hit a conflict is gone too). Only a
     * call that may not have reached the server leaves it open.
     *
     * @param \Closure(string): void $end
     */
    private function finish(\Closure $end): void
    {
        try {
            $end($this->transactionId());
        } catch (ServerException $error) {
            $this->finished = true;

            throw $error;
        }

        $this->finished = true;
    }

    protected function transactionId(): string
    {
        if ($this->finished) {
            throw new TransactionFinishedException($this->transactionId);
        }

        return $this->transactionId;
    }
}
