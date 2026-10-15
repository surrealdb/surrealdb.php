<?php

namespace SurrealDB\Protocol;

/** An immutable set of features declared as supported by an engine. */
final class FeatureSet
{
    /** @var array<string,Feature> */
    private array $features = [];

    public function __construct(Feature ...$features)
    {
        foreach ($features as $feature) {
            $this->features[$feature->name] = $feature;
        }
    }

    public function has(Feature $feature): bool
    {
        return isset($this->features[$feature->name]);
    }

    /** @return list<Feature> */
    public function all(): array
    {
        return array_values($this->features);
    }
}
