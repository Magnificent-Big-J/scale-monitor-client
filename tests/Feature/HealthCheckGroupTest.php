<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\Http\Controllers\HealthController;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;
use Rainwaves\ScaleMonitorClient\Support\HealthCheckRegistry;
use RuntimeException;

/**
 * registerCheckGroup() — for a source that produces a variable,
 * not-known-ahead-of-time number of checks from one place (e.g. an
 * existing scheduled health-check command's own cached results), which a
 * plain registerCheck() can't express (one key -> one result, always).
 */
class HealthCheckGroupTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    public function test_every_result_a_group_produces_appears_individually_in_the_payload(): void
    {
        $this->app->make(HealthCheckRegistry::class)->registerCheckGroup('cached-results', fn () => [
            CheckResult::healthy('database.database', 'Database reachable.'),
            CheckResult::critical('queue.failed-jobs', 'Failed-job count is elevated.', severityHint: 'p2'),
        ]);

        $response = $this->getJson('/health');
        $checks = collect($response->json('checks'));

        $this->assertSame('healthy', $checks->firstWhere('key', 'database.database')['status']);
        $this->assertSame('critical', $checks->firstWhere('key', 'queue.failed-jobs')['status']);
        $response->assertJsonPath('status', 'critical');
    }

    public function test_a_broken_group_reports_one_critical_entry_instead_of_crashing_the_response(): void
    {
        $this->app->make(HealthCheckRegistry::class)->registerCheckGroup('broken-group', function () {
            throw new RuntimeException('cache unreadable');
        });

        $response = $this->getJson('/health');

        $response->assertOk();
        $broken = collect($response->json('checks'))->firstWhere('key', 'broken-group');
        $this->assertSame('critical', $broken['status']);
    }

    public function test_a_group_can_produce_zero_checks_without_error(): void
    {
        $this->app->make(HealthCheckRegistry::class)->registerCheckGroup('empty-group', fn () => []);

        $this->getJson('/health')->assertOk();
    }
}
