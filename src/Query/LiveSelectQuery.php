<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Live\ManagedLiveSubscription;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Query\Concerns\HasCondition;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Value;
use function is_string;

/**
 * Fluent `LIVE SELECT` builder. {@see self::execute()} registers the live query
 * and returns a {@see ManagedLiveSubscription} to iterate.
 *
 * @extends QueryBuilder<ManagedLiveSubscription>
 */
final class LiveSelectQuery extends QueryBuilder
{
    use HasCondition;

    /** @var list<string> */
    public private(set) array $fields = [];
    public private(set) ?string $selection = null;
    /** @var list<string> */
    public private(set) array $fetch = [];

    /**
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what a string is a table name
     */
    public function __construct(
        private readonly ConnectionController $connection,
        public private(set) RecordId|RecordIdRange|StringRecordId|Table|string $what,
        private readonly ?string $session = null,
    ) {
        parent::__construct();
    }

    /** Receive only the given fields. */
    public function fields(string ...$fields): static
    {
        $this->fields = array_values($fields);
        $this->selection = 'fields';

        return $this;
    }

    /** Receive the value of a single field. */
    public function value(string $field): static
    {
        $this->fields = [$field];
        $this->selection = 'value';

        return $this;
    }

    /** Receive JSON patches describing each change instead of whole records. */
    public function diff(): static
    {
        $this->fields = [];
        $this->selection = 'diff';

        return $this;
    }

    /**
     * Filter the notifications. A {@see BoundQuery} condition with bound
     * values needs SurrealDB 3.0 or later: 2.x evaluates the condition for
     * each notification, after the bindings are gone, so {@see self::execute()}
     * refuses it there. Inline the values in a string condition instead.
     */
    public function where(string|BoundQuery $cond): static
    {
        $this->cond = $cond;

        return $this;
    }

    /** Resolve the given record-link fields (`FETCH`). */
    public function fetch(string ...$fields): static
    {
        $this->fetch = array_values($fields);

        return $this;
    }

    /**
     * Register the live query and subscribe to its notifications.
     */
    public function execute(): ManagedLiveSubscription
    {
        $this->connection->assertFeature(Features::liveQueries());
        $query = $this->build();

        // Only a `where()` with a BoundQuery adds bindings; on 2.x they would
        // be gone by the time the condition is evaluated.
        if ($query->bindings !== []) {
            $this->connection->assertFeature(Features::liveQueryBindings());
        }

        $subscription = new ManagedLiveSubscription($this->connection, $query, $this->session);
        $subscription->start();

        return $subscription;
    }

    protected function build(): BoundQuery
    {
        $query = new BoundQuery('LIVE SELECT');

        // Field names are inlined as string literals, not bound: SurrealDB
        // 2.x evaluates the projection and FETCH per notification, after the
        // request's bindings are gone.
        match ($this->selection) {
            'fields' => $query->append(' type::fields(' . Value::toSurql($this->fields) . ')'),
            'value' => $query->append(' VALUE type::field(' . Value::toSurql($this->fields[0]) . ')'),
            'diff' => $query->append(' DIFF'),
            default => $query->append(' *'),
        };

        $what = is_string($this->what) ? new Table($this->what) : $this->what;
        $query->append(' FROM ' . $this->thing($what));
        $this->applyWhere($query);

        if ($this->fetch !== []) {
            $query->append(' FETCH type::fields(' . Value::toSurql($this->fetch) . ')');
        }

        return $query;
    }
}
