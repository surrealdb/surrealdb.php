<?php

namespace SurrealDB\Types;

/**
 * A SurrealQL `range` value: a bounded or unbounded interval delimited by
 * inclusive ({@see BoundIncluded}) or exclusive ({@see BoundExcluded}) bounds.
 *
 * A `null` bound denotes an open (unbounded) end, e.g. `1..` or `..=10`.
 *
 * @template TBegin
 * @template TEnd
 */
final class Range extends Value
{
    /**
     * @param BoundIncluded<TBegin>|BoundExcluded<TBegin>|null $begin
     * @param BoundIncluded<TEnd>|BoundExcluded<TEnd>|null $end
     */
    public function __construct(
        public readonly BoundIncluded|BoundExcluded|null $begin,
        public readonly BoundIncluded|BoundExcluded|null $end,
    ) {}

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && self::boundsEqual($this->begin, $other->begin)
            && self::boundsEqual($this->end, $other->end);
    }

    public function __toString(): string
    {
        return $this->escape();
    }

    /**
     * Inline SurrealQL literal form, e.g. `1..=10`, `1>..10`, `..=10`, `1..`.
     */
    public function escape(): string
    {
        return self::escapeBound($this->begin)
            . self::joinBounds($this->begin, $this->end)
            . self::escapeBound($this->end);
    }

    /**
     * @return array{'$range': array{begin: BoundIncluded<TBegin>|BoundExcluded<TBegin>|null, end: BoundIncluded<TEnd>|BoundExcluded<TEnd>|null}}
     */
    public function jsonSerialize(): array
    {
        return ['$range' => ['begin' => $this->begin, 'end' => $this->end]];
    }

    /**
     * Render a single bound's value as a SurrealQL literal (empty when open).
     *
     * @template TValue
     *
     * @param BoundIncluded<TValue>|BoundExcluded<TValue>|null $bound
     */
    public static function escapeBound(BoundIncluded|BoundExcluded|null $bound): string
    {
        return $bound === null ? '' : Value::toSurql($bound->value);
    }

    /**
     * The range operator between two bounds: `>` prefixes an excluded begin and
     * `=` suffixes an included end (`..`, `>..`, `..=`, `>..=`).
     *
     * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $begin
     * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $end
     */
    public static function joinBounds(
        BoundIncluded|BoundExcluded|null $begin,
        BoundIncluded|BoundExcluded|null $end,
    ): string {
        $output = $begin instanceof BoundExcluded ? '>' : '';
        $output .= '..';
        $output .= $end instanceof BoundIncluded ? '=' : '';

        return $output;
    }

    /**
     * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $a
     * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $b
     */
    private static function boundsEqual(
        BoundIncluded|BoundExcluded|null $a,
        BoundIncluded|BoundExcluded|null $b,
    ): bool {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if ($a::class !== $b::class) {
            return false;
        }

        if ($a->value instanceof Value) {
            return $a->value->equals($b->value);
        }

        return $a->value == $b->value;
    }
}
