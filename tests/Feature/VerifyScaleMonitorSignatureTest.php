<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;

class VerifyScaleMonitorSignatureTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scale-monitor.key_id', 'test-key-id');
        $app['config']->set('scale-monitor.secret', 'test-secret');
    }

    protected function defineRoutes($router): void
    {
        Route::get('/probe', fn () => response()->json(['ok' => true]))->middleware('scale-monitor.signature');
    }

    private function signedGet(string $path, ?string $keyId = null, ?string $secret = null, array $overrides = []): TestResponse
    {
        $keyId ??= 'test-key-id';
        $secret ??= 'test-secret';
        $timestamp = $overrides['timestamp'] ?? (string) time();
        $nonce = $overrides['nonce'] ?? (string) Str::uuid();
        $signature = $overrides['signature'] ?? ScaleMonitorSignature::sign($secret, 'GET', $path, $timestamp, $nonce, '');

        return $this->call('GET', $path, [], [], [], [
            'HTTP_'.str_replace('-', '_', strtoupper(ScaleMonitorSignature::HEADER_CLIENT)) => $keyId,
            'HTTP_'.str_replace('-', '_', strtoupper(ScaleMonitorSignature::HEADER_TIMESTAMP)) => $timestamp,
            'HTTP_'.str_replace('-', '_', strtoupper(ScaleMonitorSignature::HEADER_NONCE)) => $nonce,
            'HTTP_'.str_replace('-', '_', strtoupper(ScaleMonitorSignature::HEADER_SIGNATURE)) => $signature,
        ]);
    }

    public function test_a_correctly_signed_request_is_accepted(): void
    {
        $this->signedGet('/probe')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_an_unknown_key_id_is_rejected(): void
    {
        $this->signedGet('/probe', keyId: 'someone-else')->assertStatus(401);
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $this->signedGet('/probe', secret: 'wrong-secret')->assertStatus(401);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $this->signedGet('/probe', overrides: ['timestamp' => (string) (time() - 400)])->assertStatus(401);
    }

    public function test_a_replayed_nonce_is_rejected(): void
    {
        $nonce = (string) Str::uuid();

        $this->signedGet('/probe', overrides: ['nonce' => $nonce])->assertOk();
        $this->signedGet('/probe', overrides: ['nonce' => $nonce])->assertStatus(401);
    }

    public function test_it_404s_when_not_configured(): void
    {
        config(['scale-monitor.key_id' => '', 'scale-monitor.secret' => '']);

        $this->signedGet('/probe')->assertStatus(404);
    }
}
