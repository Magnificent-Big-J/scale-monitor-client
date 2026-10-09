<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\HealthChecks\SchedulerHealthCheck;

class SchedulerHealthCheckTest extends TestCase
{
    public function test_old_scheduler_evidence_is_critical_instead_of_a_negative_age(): void
    {
        $this->travelTo(now()->startOfHour());
        Cache::put('scale-monitor:scheduler:heartbeat', now()->subMinutes(10));
        $result = (new SchedulerHealthCheck)->run();
        $this->assertSame('critical', $result->status);
        $this->assertSame('SCHEDULER_STALE', $result->errorCode);
        $this->assertStringContainsString('600s old', $result->summary);
    }

    public function test_recent_timezone_aware_evidence_is_healthy_and_not_negative(): void
    {
        $this->travelTo(now()->startOfHour());
        Cache::put('scale-monitor:scheduler:heartbeat', now()->subMinute()->timezone('Africa/Johannesburg')->toIso8601String());
        $result = (new SchedulerHealthCheck)->run();
        $this->assertSame('healthy', $result->status);
        $this->assertSame('Scheduler heartbeat is 60s old.', $result->summary);
    }

    public function test_future_or_invalid_timestamps_are_unverified_and_missing_evidence_is_skipped(): void
    {
        Cache::put('scale-monitor:scheduler:heartbeat', now()->addHour());
        $this->assertSame('degraded', (new SchedulerHealthCheck)->run()->status);
        Cache::put('scale-monitor:scheduler:heartbeat', 'not a date');
        $this->assertSame('degraded', (new SchedulerHealthCheck)->run()->status);
        Cache::forget('scale-monitor:scheduler:heartbeat');
        $this->assertSame('skipped', (new SchedulerHealthCheck)->run()->status);
    }
}
