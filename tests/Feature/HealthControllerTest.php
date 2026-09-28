<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\Http\Controllers\HealthController;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;
use Rainwaves\ScaleMonitorClient\Support\HealthCheckRegistry;
use RuntimeException;

class HealthControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::get('/health', HealthController::class);
    }

    protected function defineEnvironment($app): void
    {
        // Testbench's default test app has no APP_KEY at all, unlike any
        // real app -- set one so required_env's built-in APP_KEY check
        // reflects a real app's baseline, not this harness's own gap.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    public function test_the_payload_has_the_documented_shape(): void
    {
        config(['scale-monitor.application' => 'test-app', 'scale-monitor.environment' => 'testing']);

        $response = $this->getJson('/health');

        $response->assertOk();
        $response->assertJsonStructure(['schema_version', 'application', 'environment', 'status', 'observed_at', 'checks']);
        $response->assertJsonPath('schema_version', '1.0');
        $response->assertJsonPath('application', 'test-app');
    }

    public function test_status_is_healthy_when_every_built_in_check_passes_against_a_working_testbench_app(): void
    {
        $response = $this->getJson('/health');

        // scheduler is `skipped` by default (no host heartbeat wired up in this
        // test app) -- confirms skipped never drags the overall status down.
        $response->assertJsonPath('status', 'healthy');
    }

    public function test_a_registered_app_specific_check_appears_in_the_payload(): void
    {
        $this->app->make(HealthCheckRegistry::class)->registerCheck(
            'google_maps',
            fn () => CheckResult::critical('google_maps', 'Billing disabled.', errorCode: 'BILLING_DISABLED'),
        );

        $response = $this->getJson('/health');

        $checks = collect($response->json('checks'));
        $googleMaps = $checks->firstWhere('key', 'google_maps');

        $this->assertSame('critical', $googleMaps['status']);
        $this->assertSame('BILLING_DISABLED', $googleMaps['error_code']);
        $response->assertJsonPath('status', 'critical');
    }

    public function test_a_broken_custom_check_reports_critical_instead_of_crashing_the_whole_response(): void
    {
        $this->app->make(HealthCheckRegistry::class)->registerCheck(
            'broken',
            fn () => throw new RuntimeException('boom'),
        );

        $response = $this->getJson('/health');

        $response->assertOk();
        $broken = collect($response->json('checks'))->firstWhere('key', 'broken');
        $this->assertSame('critical', $broken['status']);
        $response->assertJsonPath('status', 'critical');
    }
}
