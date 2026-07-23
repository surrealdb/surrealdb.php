<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron;

/** Options for {@see Spectron::forget()}. */
final readonly class ForgetOptions
{
    /**
     * @param bool $purge erase matching rows instead of tombstoning them
     */
    public function __construct(
        public bool $purge = false,
    ) {}

    /**
     * @internal wire fields for `POST /forget`; `purge` is only sent when set
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return $this->purge ? ['purge' => true] : [];
    }
}
