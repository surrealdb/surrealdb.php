<?php

namespace SurrealDB\Tests\Unit\Spectron;

use PHPUnit\Framework\TestCase;
use SurrealDB\Spectron\RetryPolicy;
use SurrealDB\Tests\Fakes\RecordingScheduler;

final class RetryPolicyTest extends TestCase
{
    public function testRetriesIdempotentReadsOnServerErrorsAndConnectionFailures(): void
    {
        $policy = new RetryPolicy(3);

        $this->assertTrue($policy->shouldRetry('GET', 500, 0));
        $this->assertTrue($policy->shouldRetry('get', 503, 2));
        $this->assertTrue($policy->shouldRetry('HEAD', null, 0));
        $this->assertFalse($policy->shouldRetry('GET', 404, 0));
        $this->assertFalse($policy->shouldRetry('GET', 500, 3));
    }

    public function testWritesAreOnlyRetriedWhenMarkedIdempotent(): void
    {
        $policy = new RetryPolicy(3);

        $this->assertFalse($policy->shouldRetry('POST', 500, 0));
        $this->assertFalse($policy->shouldRetry('PUT', null, 0));
        $this->assertTrue($policy->shouldRetry('POST', 500, 0, idempotent: true));
        $this->assertTrue($policy->shouldRetry('POST', null, 1, idempotent: true));
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
}
