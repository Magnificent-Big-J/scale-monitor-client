<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\ScaleMonitor;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\RuntimeSnapshot;
use RuntimeException;

class RuntimeSnapshotTest extends TestCase
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

    public function test_scheduler_reports_bounded_runtime_without_secrets_or_paths(): void
    {
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        app(ScaleMonitor::class)->heartbeat('scheduler', ['source' => 'console']);
        Http::assertSent(function (Request $request) {
            $runtime = $request['meta']['runtime'];

            return $runtime['php_version'] === PHP_VERSION && $runtime['queue_backlog'] === 0
                && isset($runtime['client_version'], $runtime['framework_version'], $runtime['observed_at'])
                && ! isset($runtime['secret'], $runtime['key_id'], $runtime['base_path'])
                && strlen(json_encode($request['meta'])) <= 1024 && $request['meta']['source'] === 'console';
        });
    }

    public function test_runtime_collection_failure_does_not_prevent_the_heartbeat(): void
    {
        $snapshot = \Mockery::mock(RuntimeSnapshot::class);
        $snapshot->shouldReceive('collect')->once()->andThrow(new RuntimeException('probe unavailable'));
        $this->app->instance(RuntimeSnapshot::class, $snapshot);
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        app(ScaleMonitor::class)->heartbeat('scheduler');
        Http::assertSent(fn (Request $request) => $request['key'] === 'scheduler');
    }

    public function test_metadata_can_be_disabled_and_custom_heartbeat_keys_are_unchanged(): void
    {
        config(['scale-monitor.runtime_metadata' => false]);
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        app(ScaleMonitor::class)->heartbeat('scheduler', ['source' => 'console']);
        app(ScaleMonitor::class)->heartbeat('backup', ['source' => 'backup']);
        foreach (Http::recorded() as [$request]) {
            $this->assertArrayNotHasKey('runtime', $request['meta']);
        }
    }

    public function test_worker_verification_counts_only_actual_workers_for_this_application(): void
    {
        $directory = sys_get_temp_dir().'/scale-monitor-workers-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            foreach ([
                11 => ["php\0artisan\0horizon:work\0redis\0", base_path()],
                12 => ["bash\0script mentions artisan horizon:work but is not a worker\0", base_path()],
                13 => ["php\0artisan\0horizon:work\0redis\0", sys_get_temp_dir()],
            ] as $pid => [$command, $cwd]) {
                mkdir($directory.'/'.$pid);
                file_put_contents($directory.'/'.$pid.'/cmdline', $command);
                symlink($cwd, $directory.'/'.$pid.'/cwd');
            }
            $this->assertSame(1, app(RuntimeSnapshot::class)->liveWorkers(base_path(), $directory));
            $this->assertNull(app(RuntimeSnapshot::class)->liveWorkers(base_path(), $directory.'/missing'));
        } finally {
            foreach (glob($directory.'/*') as $pid) {
                unlink($pid.'/cmdline');
                unlink($pid.'/cwd');
                rmdir($pid);
            }
            rmdir($directory);
        }
    }

    public function test_runtime_does_not_push_existing_metadata_over_the_server_limit(): void
    {
        Http::fake(['monitor.test/*' => Http::response([], 202)]);
        app(ScaleMonitor::class)->heartbeat('scheduler', ['details' => str_repeat('x', 900)]);
        Http::assertSent(fn (Request $request) => strlen($request['meta']['details']) === 900
            && ! isset($request['meta']['runtime']));
    }
}
