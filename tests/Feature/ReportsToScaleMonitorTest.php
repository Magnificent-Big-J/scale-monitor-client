<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\Exceptions\ReportsToScaleMonitor;
use Rainwaves\ScaleMonitorClient\Jobs\PushScaleMonitorTelemetry;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use RuntimeException;

class ReportsToScaleMonitorTest extends TestCase
{
    use ReportsToScaleMonitor;

    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scale-monitor.enabled', true);
        $app['config']->set('scale-monitor.base_url', 'https://monitor.test');
        $app['config']->set('scale-monitor.key_id', 'key');
        $app['config']->set('scale-monitor.secret', 'secret');
    }

    public function test_a_severity_at_or_above_the_minimum_is_reported(): void
    {
        Queue::fake();
        config(['scale-monitor.report_exceptions.min_severity' => 'high']);

        $this->reportExceptionToScaleMonitor(new RuntimeException('boom'), severity: 'critical');

        Queue::assertPushed(PushScaleMonitorTelemetry::class);
    }

    public function test_a_severity_below_the_minimum_is_not_reported(): void
    {
        Queue::fake();
        config(['scale-monitor.report_exceptions.min_severity' => 'high']);

        $this->reportExceptionToScaleMonitor(new RuntimeException('boom'), severity: 'warning');

        Queue::assertNotPushed(PushScaleMonitorTelemetry::class);
    }

    public function test_zero_sampling_reports_nothing(): void
    {
        Queue::fake();
        config(['scale-monitor.report_exceptions.min_severity' => 'low', 'scale-monitor.report_exceptions.sampling' => 0.0]);

        $this->reportExceptionToScaleMonitor(new RuntimeException('boom'), severity: 'critical');

        Queue::assertNotPushed(PushScaleMonitorTelemetry::class);
    }

    public function test_full_sampling_always_reports(): void
    {
        Queue::fake();
        config(['scale-monitor.report_exceptions.min_severity' => 'low', 'scale-monitor.report_exceptions.sampling' => 1.0]);

        $this->reportExceptionToScaleMonitor(new RuntimeException('boom'), severity: 'critical');

        Queue::assertPushed(PushScaleMonitorTelemetry::class);
    }
}
