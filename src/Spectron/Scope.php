<?php

namespace SurrealDB\Spectron;

use function is_string;

/**
 * Normalises scope selectors to the API wire format.
 *
 * The wire format is a `ScopeSets`: a DNF (disjunctive-normal-form) selector
 * shaped as `string[][]`. The outer array is an OR of clauses; each inner array
 * is an AND of hierarchical `key/value` slash-path strings, so
 * `[["team/a"], ["team/b", "clearance/secret"]]` means
 * `team/a OR (team/b AND clearance/secret)`.
 *
 * For ergonomics the client also accepts a bare path string (a single-path
 * clause) and a flat string array (an OR of single-path clauses), and the two
 * mix. So `"team/eng"` is `[["team/eng"]]`, `["a", "b"]` is `[["a"], ["b"]]`
 * (a OR b), and you nest to express an AND: `[["a", "b"]]` is `a AND b`.
 */
final class Scope
{
    /**
     * Normalises a scope input to the wire `ScopeSets` selector.
     *
     * A bare string becomes one single-path clause. Each element of the outer
     * array becomes a clause: a string element is a one-path clause, an array
     * element is an AND clause of its paths. Within each clause empty strings
     * are dropped and paths are de-duplicated preserving first-seen order; a
     * clause that ends up empty is dropped (empty clauses are rejected on the
     * wire).
     *
     * @param string|array<int,string|list<string>>|null $scope scope input in any accepted shape
     *
     * @return list<non-empty-list<string>>|null the normalised DNF selector, or `null` when no
     *                                           non-empty clause remains (so callers can omit the
     *                                           field entirely and use the key's default write region)
     */
    public static function normalise(string|array|null $scope): ?array
    {
        if ($scope === null) {
            return null;
        }

        $clauses = is_string($scope) ? [$scope] : $scope;
        $out = [];

        foreach ($clauses as $clause) {
            $paths = is_string($clause) ? [$clause] : $clause;
            $deduped = array_values(array_unique(array_filter(
                $paths,
                static fn (string $path): bool => $path !== '',
            )));

            if ($deduped !== []) {
                $out[] = $deduped;
            }
        }

        return $out === [] ? null : $out;
    }
}
