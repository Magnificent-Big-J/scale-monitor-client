<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
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
}
