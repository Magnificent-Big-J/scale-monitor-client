<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\Jobs\PushScaleMonitorTelemetry;
use Rainwaves\ScaleMonitorClient\ScaleMonitor;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;

class TelemetryPayloadContractTest extends TestCase
{
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
        $app['config']->set('queue.default', 'sync');
    }

    public function test_metric_samples_preserve_zero_and_observation_time(): void
    {
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        app(ScaleMonitor::class)->metric('queue_depth', ['value' => 0, 'observed_at' => '2026-10-08T10:00:00Z', 'tags' => ['password' => 'hide', 'queue' => 'default']]);
        Http::assertSent(fn (Request $request) => $request['samples'][0]['value'] === 0
            && $request['samples'][0]['observed_at'] === '2026-10-08T10:00:00Z'
            && $request['samples'][0]['key'] === 'queue_depth'
            && $request['samples'][0]['tags']['password'] === '[redacted]');
    }

    public function test_custom_events_include_the_required_fields(): void
    {
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        app(ScaleMonitor::class)->event('runtime_diagnostic', 'info', ['enabled' => true], message: 'Runtime checks completed');
        Http::assertSent(fn (Request $request) => $request['kind'] === 'runtime_diagnostic'
            && $request['title'] === 'runtime_diagnostic' && $request['message'] === 'Runtime checks completed'
            && $request['context']['enabled'] === true);
    }

    public function test_legacy_low_severity_maps_to_info_and_string_zero_is_preserved(): void
    {
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        app(ScaleMonitor::class)->error('low', 'RuntimeException', '0', '0');
        Http::assertSent(fn (Request $request) => $request['severity'] === 'info' && $request['message'] === '0' && $request['error_code'] === '0');
    }

    public function test_default_metric_observation_time_survives_queue_delay(): void
    {
        Queue::fake();
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        $this->travelTo(Carbon::parse('2026-10-08T10:00:00Z'));
        app(ScaleMonitor::class)->metric('queue_depth', ['value' => 0]);
        $job = Queue::pushed(PushScaleMonitorTelemetry::class)->first();
        $this->travel(1)->hour();
        $job->handle();
        Http::assertSent(fn (Request $request) => $request['samples'][0]['observed_at'] === '2026-10-08T10:00:00+00:00');
    }
}
