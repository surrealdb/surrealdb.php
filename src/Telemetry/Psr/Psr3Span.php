<?php

namespace SurrealDB\Telemetry\Psr;

use Psr\Log\LoggerInterface;
use SurrealDB\Contracts\Span;
use SurrealDB\Enum\SpanKind;
use SurrealDB\Enum\SpanStatus;

/** A {@see Span} that emits a structured PSR-3 log line when it ends. */
final class Psr3Span implements Span
{
    private readonly int $startedAt;

    /** @var array<string, scalar|array<scalar>|null> */
    private array $attributes;

    private SpanStatus $status = SpanStatus::Unset;
    private ?string $statusDescription = null;

    /**
     * @param array<non-empty-string, scalar|array<scalar>|null> $attributes
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level,
        private readonly string $name,
        private readonly SpanKind $kind,
        array $attributes = [],
    ) {
        $this->attributes = $attributes;
        $this->startedAt = hrtime(true);
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function recordException(\Throwable $exception): static
    {
        $this->logger->error('span {span}: {error}', [
            'span' => $this->name,
            'error' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        return $this;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): static
    {
        $this->status = $status;
        $this->statusDescription = $description;

        return $this;
    }

    public function end(): void
    {
        $this->logger->log($this->level, 'span {span} ({duration_ms} ms) [{status}]', [
            'span' => $this->name,
            'kind' => $this->kind->name,
            'status' => $this->status->name,
            'status_description' => $this->statusDescription,
            'duration_ms' => (hrtime(true) - $this->startedAt) / 1_000_000,
            'attributes' => $this->attributes,
        ]);
    }
}
