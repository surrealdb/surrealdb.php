<?php

namespace SurrealDB\SDK\Telemetry\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use SurrealDB\SDK\Contracts\Span;
use SurrealDB\SDK\Enum\SpanStatus;

/**
 * Wraps an OpenTelemetry span. {@see end()} detaches the activated scope before
 * ending the span so the previous context is restored.
 */
final class OtelSpan implements Span
{
    public function __construct(
        private readonly SpanInterface $span,
        private readonly ?ScopeInterface $scope = null,
    ) {}

    public function setAttribute(string $key, mixed $value): static
    {
        if ($key !== '') {
            $this->span->setAttribute($key, $value);
        }

        return $this;
    }

    public function recordException(\Throwable $exception): static
    {
        $this->span->recordException($exception);

        return $this;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): static
    {
        $this->span->setStatus($this->mapStatus($status), $description);

        return $this;
    }

    public function end(): void
    {
        $this->scope?->detach();
        $this->span->end();
    }

    /**
     * @return StatusCode::STATUS_*
     */
    private function mapStatus(SpanStatus $status): string
    {
        return match ($status) {
            SpanStatus::Unset => StatusCode::STATUS_UNSET,
            SpanStatus::Ok => StatusCode::STATUS_OK,
            SpanStatus::Error => StatusCode::STATUS_ERROR,
        };
    }
}
