<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\Jobs\PushScaleMonitorTelemetry;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;

class PushScaleMonitorTelemetryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scale-monitor.enabled', true);
        $app['config']->set('scale-monitor.base_url', 'https://monitor.test');
        $app['config']->set('scale-monitor.key_id', 'test-key-id');
        $app['config']->set('scale-monitor.secret', 'test-secret');
    }

    public function test_it_sends_a_genuinely_signed_request(): void
    {
        Http::fake(['monitor.test/*' => Http::response(['success' => true], 202)]);

        (new PushScaleMonitorTelemetry('heartbeat', ['key' => 'scheduler']))->handle();

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $timestamp = $request->header(ScaleMonitorSignature::HEADER_TIMESTAMP)[0];
            $nonce = $request->header(ScaleMonitorSignature::HEADER_NONCE)[0];
            $signature = $request->header(ScaleMonitorSignature::HEADER_SIGNATURE)[0];

            $expected = ScaleMonitorSignature::sign('test-secret', 'POST', '/api/v1/telemetry/heartbeat', $timestamp, $nonce, $request->body());

            return $request->url() === 'https://monitor.test/api/v1/telemetry/heartbeat'
                && $request->header(ScaleMonitorSignature::HEADER_CLIENT)[0] === 'test-key-id'
                && $signature === $expected
                && $body['key'] === 'scheduler'
                && isset($body['event_id'], $body['occurred_at']);
        });
    }

    public function test_it_does_nothing_when_not_configured(): void
    {
        config(['scale-monitor.enabled' => false]);
        Http::fake();

        (new PushScaleMonitorTelemetry('heartbeat', ['key' => 'scheduler']))->handle();

        Http::assertNothingSent();
    }

    public function test_a_failed_response_never_throws(): void
    {
        Http::fake(['monitor.test/*' => Http::response(['error' => 'bad_signature'], 401)]);

        (new PushScaleMonitorTelemetry('heartbeat', ['key' => 'scheduler']))->handle();

        $this->assertTrue(true);
    }

    public function test_a_connection_exception_never_throws(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        (new PushScaleMonitorTelemetry('heartbeat', ['key' => 'scheduler']))->handle();

        $this->assertTrue(true);
    }

    public function test_queue_delay_preserves_the_original_occurrence_and_id(): void
    {
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        $this->travelTo(Carbon::parse('2026-10-08T12:00:00+02:00'));
        $job = unserialize(serialize(new PushScaleMonitorTelemetry('heartbeat', ['key' => 'scheduler'])));
        $this->travel(1)->day();
        $job->handle();
        $this->travel(5)->minutes();
        $job->handle();

        $requests = Http::recorded();
        $first = json_decode($requests[0][0]->body(), true);
        $second = json_decode($requests[1][0]->body(), true);
        $this->assertSame('2026-10-08T10:00:00+00:00', $first['occurred_at']);
        $this->assertSame($first['occurred_at'], $second['occurred_at']);
        $this->assertSame($first['event_id'], $second['event_id']);
    }

    public function test_explicit_occurrence_and_id_are_preserved(): void
    {
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        (new PushScaleMonitorTelemetry('metric', [
            'key' => 'queue_depth', 'value' => 4,
            'event_id' => 'existing-id', 'occurred_at' => '2026-10-07T10:00:00+00:00',
        ]))->handle();

        Http::assertSent(fn (Request $request) => $request['event_id'] === 'existing-id'
            && $request['occurred_at'] === '2026-10-07T10:00:00+00:00');
    }

    public function test_legacy_job_uses_queue_creation_time(): void
    {
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        $job = new PushScaleMonitorTelemetry('heartbeat', ['key' => 'scheduler']);
        (new \ReflectionProperty($job, 'payload'))->setValue($job, ['key' => 'scheduler']);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('payload')->andReturn(['createdAt' => 1791453600]);
        $job->setJob($queueJob);
        $this->travelTo(Carbon::parse('2026-10-09T10:00:00Z'));
        $job->handle();

        Http::assertSent(fn (Request $request) => $request['occurred_at'] === '2026-10-08T10:00:00+00:00');
    }
}
