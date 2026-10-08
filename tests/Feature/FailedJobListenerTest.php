<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\Jobs\PushScaleMonitorTelemetry;
use Rainwaves\ScaleMonitorClient\Listeners\ReportFailedJobToScaleMonitor;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use RuntimeException;

class FailedJobListenerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    public function test_monitoring_transport_failure_does_not_report_itself(): void
    {
        config(['scale-monitor.enabled' => true]);
        Queue::fake();
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(PushScaleMonitorTelemetry::class);
        app(ReportFailedJobToScaleMonitor::class)->handle(new JobFailed('redis', $job, new RuntimeException('worker failed')));
        Queue::assertNothingPushed();
    }

    public function test_business_job_failure_still_reports(): void
    {
        config(['scale-monitor.enabled' => true]);
        Queue::fake();
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendDocument');
        $job->shouldReceive('getQueue')->andReturn('documents');
        $job->shouldReceive('attempts')->andReturn(3);
        app(ReportFailedJobToScaleMonitor::class)->handle(new JobFailed('redis', $job, new RuntimeException('worker failed')));
        Queue::assertPushed(PushScaleMonitorTelemetry::class, 1);
    }
}
