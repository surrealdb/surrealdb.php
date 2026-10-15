<?php

namespace SurrealDB\AgentMemory;

use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Schema\Schemas;
use function count;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function array_key_exists;

/**
 * One page of a cursor-paginated collection, plus the means to walk the rest.
 *
 * Every Agent Memory collection pages with keyset cursors: the response carries
 * its rows beside a `page` block (`hasMore`, `nextCursor`, and `totalSize` when
 * the request asked for `count`). Iterating a `Page` walks this page's rows;
 * {@see next()} fetches the following page, {@see autoPaginate()} lazily
 * yields every row from here to the end, re-issuing the original request with
 * each `nextCursor`, and {@see collect()} gathers those rows into one list:
 *
 * ```php
 * foreach ($memory->entities->list()->autoPaginate() as $entity) {
 *     echo $entity['name'], PHP_EOL;
 * }
 * ```
 *
 * Every collection method narrows `T` to its row schema: `$memory->documents->list()`
 * is a `Page<DocumentJson>` ({@see Schemas}). `T` is covariant, since a page
 * is read-only: a `Page<DocumentJson>` is also a `Page<array<string, mixed>>`.
 *
 * @template-covariant T = array<string, mixed> the row type; untyped decoded rows by default
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T>                          $items      this page's rows
     * @param bool                             $hasMore    whether a further page exists
     * @param string|null                      $nextCursor token for the next page; `null` on the last page
     * @param int|null                         $totalSize  total rows matching the filters, present only on the page
     *                                                     whose request set `count` ({@see next()} does not repeat
     *                                                     the count, which costs the server a full scan)
     * @param array<int|string,mixed>          $raw        the full decoded response envelope
     * @param (\Closure(string): self<T>)|null $fetch      re-issues the originating request at a cursor
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasMore = false,
        public readonly ?string $nextCursor = null,
        public readonly ?int $totalSize = null,
        public readonly array $raw = [],
        private readonly ?\Closure $fetch = null,
    ) {}

    /**
     * @internal builds a page from a collection envelope such as `{"entities": [...], "page": {...}}`
     *
     * @param array<int|string,mixed>                          $envelope decoded response body; `[]` for an empty body
     * @param string                                           $itemsKey the envelope member holding the rows
     * @param (\Closure(string): self<array<string, mixed>>)|null $fetch    re-issues the originating request at a cursor
     *
     * @return self<array<string, mixed>> rows are untyped here; the calling component narrows the page to its schema
     *
     * @throws AgentMemoryException when the envelope lacks the rows member, which every collection
     *                              response declares as required (so the API changed shape)
     */
    public static function fromEnvelope(array $envelope, string $itemsKey, ?\Closure $fetch = null): self
    {
        if ($envelope !== [] && !array_key_exists($itemsKey, $envelope)) {
            throw new AgentMemoryException('Unexpected collection response', 0, "the response has no `{$itemsKey}` member");
        }

        $items = [];

        foreach (is_array($envelope[$itemsKey] ?? null) ? $envelope[$itemsKey] : [] as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        $meta = is_array($envelope['page'] ?? null) ? $envelope['page'] : [];
        $nextCursor = is_string($meta['nextCursor'] ?? null) && $meta['nextCursor'] !== '' ? $meta['nextCursor'] : null;

        /** @var list<array<string, mixed>> $items */
        return new self(
            $items,
            is_bool($meta['hasMore'] ?? null) ? $meta['hasMore'] : $nextCursor !== null,
            $nextCursor,
            is_int($meta['totalSize'] ?? null) ? $meta['totalSize'] : null,
            $envelope,
            $fetch,
        );
    }

    /**
     * Fetches the following page, or returns `null` when this is the last one.
     *
     * @return self<T>|null
     *
     * @throws AgentMemoryException when the request for the next page fails, or the server
     *                              reported more rows without the cursor to fetch them
     */
    public function next(): ?self
    {
        if (!$this->hasMore || $this->fetch === null) {
            return null;
        }

        if ($this->nextCursor === null) {
            throw new AgentMemoryException('Inconsistent page', 0, 'the server reported more rows but sent no cursor to fetch them');
        }

        return ($this->fetch)($this->nextCursor);
    }

    /**
     * Lazily yields every row from this page onward, fetching each further page
     * only once the previous one is exhausted.
     *
     * @return \Generator<int, T, mixed, void>
     *
     * @throws AgentMemoryException when a page request fails, or the server hands back a
     *                              cursor it already sent (which would otherwise loop forever)
     */
    public function autoPaginate(): \Generator
    {
        foreach ($this->pages() as $page) {
            foreach ($page->items as $item) {
                yield $item;
            }
        }
    }

    /**
     * Every row from this page to the end of the collection, as one list: the
     * PHP form of the JS client's `collectPages`, behind every `listAll()` and
     * `all*()`. The walk ends where {@see autoPaginate()} ends. `$max` stops it
     * after the page on which the row count reaches `$max`; pages arrive whole,
     * so the result can exceed `$max` by up to one page, as in JS.
     *
     * @param int|null $max stop once at least this many rows are in hand (at least 1)
     *
     * @return list<T>
     *
     * @throws InvalidArgumentException when `$max` is below 1
     * @throws AgentMemoryException     when a page request fails, or the server hands back a
     *                                  cursor it already sent (which would otherwise loop forever)
     */
    public function collect(?int $max = null): array
    {
        Guard::max('Page::collect()', $max);

        $rows = [];

        foreach ($this->pages() as $page) {
            foreach ($page->items as $item) {
                $rows[] = $item;
            }

            if ($max !== null && count($rows) >= $max) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return \Iterator<int, T>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->items);
    }

    /** Rows on this page (not {@see $totalSize}). */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * This page, then each following one, fetched only when the walk resumes.
     * The walk ends on a page without `hasMore`; one that claims more without
     * a cursor, or hands back a cursor already followed, throws.
     *
     * @return \Generator<int, self<T>, mixed, void>
     *
     * @throws AgentMemoryException when a page request fails or the cursor does not advance
     */
    private function pages(): \Generator
    {
        $page = $this;
        $followed = [];

        while (true) {
            yield $page;

            $next = $page->next();

            if ($next === null) {
                return;
            }

            $followed[(string) $page->nextCursor] = true;

            if ($next->hasMore && isset($followed[(string) $next->nextCursor])) {
                throw new AgentMemoryException('Pagination cursor did not advance', 0, "cursor {$next->nextCursor} was already followed");
            }

            $page = $next;
        }
    }
}
