<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\HealthChecks\SchedulerHealthCheck;
use Rainwaves\ScaleMonitorClient\ScaleMonitor;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\SchedulerMeasurement;

class SchedulerMeasurementTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scale-monitor.enabled', true);
    }

    public function test_real_scheduled_callback_records_execution_and_preserves_stale_evidence_until_recovery(): void
    {
        $this->travelTo(now()->startOfMinute());
        $check = new SchedulerHealthCheck;
        $this->assertSame('skipped', $check->run()->status);
        $events = collect($this->app->make(Schedule::class)->events())->where('description', 'scale-monitor:measure-scheduler');
        $this->assertCount(1, $events);
        $event = $events->sole();
        $this->assertSame('* * * * *', $event->expression);
        $event->run($this->app);
        $this->assertSame('healthy', $check->run()->status);
        $this->travel(10)->minutes();
        $this->assertNotNull(Cache::get(SchedulerMeasurement::CACHE_KEY));
        $this->assertSame('critical', $check->run()->status);
        $this->assertSame('SCHEDULER_STALE', $check->run()->errorCode);
        $event->run($this->app);
        $this->assertSame('healthy', $check->run()->status);
    }

    public function test_telemetry_failure_and_manual_heartbeats_do_not_fake_scheduler_execution(): void
    {
        config([
            'scale-monitor.base_url' => 'https://monitor.test',
            'scale-monitor.key_id' => 'test-key',
            'scale-monitor.secret' => 'test-secret',
            'scale-monitor.runtime_metadata' => false,
        ]);
        Queue::shouldReceive('connection')->twice()->andThrow(new \RuntimeException('unavailable'));
        $scaleMonitor = $this->app->make(ScaleMonitor::class);
        $scaleMonitor->heartbeat('scheduler');
        $this->assertNull(Cache::get(SchedulerMeasurement::CACHE_KEY));
        $event = collect($this->app->make(Schedule::class)->events())->where('description', 'scale-monitor:measure-scheduler')->sole();
        $event->run($this->app);
        $this->assertSame('healthy', (new SchedulerHealthCheck)->run()->status);
        $scaleMonitor->heartbeat('scheduler');
        $this->assertSame('healthy', (new SchedulerHealthCheck)->run()->status);
    }

    public function test_recording_failure_does_not_interrupt_host_schedule_and_reads_report_failure(): void
    {
        Cache::shouldReceive('forever')->once()->andThrow(new \RuntimeException('cache secret must not be shown'));
        (new SchedulerMeasurement)->record();
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache secret must not be shown'));
        $result = (new SchedulerHealthCheck)->run();
        $this->assertSame('critical', $result->status);
        $this->assertSame('SCHEDULER_CACHE_UNAVAILABLE', $result->errorCode);
        $this->assertStringNotContainsString('secret', $result->summary);
    }
}
