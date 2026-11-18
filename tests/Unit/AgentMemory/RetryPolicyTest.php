<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory\RetryAfter;
use SurrealDB\AgentMemory\RetryPolicy;
use SurrealDB\Tests\Fakes\RecordingScheduler;

final class RetryPolicyTest extends TestCase
{
    public function testRetriesIdempotentReadsOnTransientFailures(): void
    {
        $policy = new RetryPolicy(3);

        foreach ([408, 429, 500, 502, 503, 504] as $status) {
            $this->assertTrue($policy->shouldRetry('GET', $status, 0), "status {$status}");
        }

        $this->assertTrue($policy->shouldRetry('get', 503, 2));
        $this->assertTrue($policy->shouldRetry('HEAD', null, 0));
        $this->assertFalse($policy->shouldRetry('GET', 500, 3));
    }

    public function testStatusesThatCannotHealAreNotRetried(): void
    {
        $policy = new RetryPolicy(3);

        foreach ([400, 401, 403, 404, 409, 415, 422, 501, 505] as $status) {
            $this->assertFalse($policy->shouldRetry('GET', $status, 0), "status {$status}");
        }
    }

    public function testWritesAreOnlyRetriedWhenMarkedIdempotent(): void
    {
        $policy = new RetryPolicy(3);

        $this->assertFalse($policy->shouldRetry('POST', 500, 0));
        $this->assertFalse($policy->shouldRetry('PUT', null, 0));
        $this->assertTrue($policy->shouldRetry('POST', 500, 0, idempotent: true));
        $this->assertTrue($policy->shouldRetry('POST', null, 1, idempotent: true));
    }

    public function testLoadSheddingIsRetriedForEveryMethodUntilAttemptsRunOut(): void
    {
        $policy = new RetryPolicy(3);

        $this->assertTrue($policy->shouldRetry('POST', 503, 0));
        $this->assertTrue($policy->shouldRetry('DELETE', 503, 2));
        $this->assertFalse($policy->shouldRetry('POST', 503, 3));
    }

    public function testOnlyAnInFlightConflictOnAnIdempotentWriteIsRetried(): void
    {
        $policy = new RetryPolicy(3);
        $inFlight = '{"message":"A request with this Idempotency-Key is already in progress"}';
        $reused = '{"message":"Idempotency-Key already used with a different request body"}';

        $this->assertTrue($policy->shouldRetry('POST', 409, 0, idempotent: true, body: $inFlight));
        $this->assertFalse($policy->shouldRetry('POST', 409, 0, idempotent: true, body: $reused));
        $this->assertFalse($policy->shouldRetry('POST', 409, 0, idempotent: true));
        $this->assertFalse($policy->shouldRetry('POST', 409, 0, body: $inFlight));
        $this->assertFalse($policy->shouldRetry('GET', 409, 0, body: $inFlight));
    }

    public function testBackOffDelegatesToTheSchedulerWithTheFixedSchedule(): void
    {
        $scheduler = new RecordingScheduler();
        $policy = new RetryPolicy(3, $scheduler);

        $policy->backOff(0);
        $policy->backOff(1);
        $policy->backOff(2);
        $policy->backOff(9);

        $this->assertSame([0.25, 0.5, 1.0, 1.0], $scheduler->delays);
    }

    public function testBackOffWaitsAtLeastTheServersRetryAfterUpToTheCap(): void
    {
        $scheduler = new RecordingScheduler();
        $policy = new RetryPolicy(3, $scheduler);

        $policy->backOff(0, retryAfterSeconds: 2);
        $policy->backOff(2, retryAfterSeconds: 0);
        $policy->backOff(0, retryAfterSeconds: 3600);

        $this->assertSame([2.0, 1.0, 4.0], $scheduler->delays);
    }

    public function testRetryAfterReadsOnlyTheDeltaSecondsForm(): void
    {
        $this->assertSame(1, RetryAfter::seconds(['Retry-After' => ['1']]));
        $this->assertSame(30, RetryAfter::seconds(['retry-after' => ' 30 ']));
        $this->assertNull(RetryAfter::seconds(['Retry-After' => ['Wed, 21 Oct 2026 07:28:00 GMT']]));
        $this->assertNull(RetryAfter::seconds(['Retry-After' => ['-5']]));
        $this->assertNull(RetryAfter::seconds(['Content-Type' => ['application/json']]));
    }
}
