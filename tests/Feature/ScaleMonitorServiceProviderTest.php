<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\ScaleMonitor;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\HealthCheckRegistry;

class ScaleMonitorServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    public function test_default_config_is_merged_and_shadow_mode_by_default(): void
    {
        $this->assertFalse(config('scale-monitor.enabled'));
        $this->assertSame('', config('scale-monitor.base_url'));
        $this->assertSame('', config('scale-monitor.key_id'));
        $this->assertSame('', config('scale-monitor.secret'));
        $this->assertSame(2, config('scale-monitor.retries'));
    }

    public function test_scale_monitor_resolves_as_a_singleton(): void
    {
        $this->assertSame($this->app->make(ScaleMonitor::class), $this->app->make(ScaleMonitor::class));
    }

    public function test_the_signature_middleware_alias_is_registered(): void
    {
        $router = $this->app->make('router');

        $this->assertArrayHasKey('scale-monitor.signature', $router->getMiddleware());
    }

    public function test_every_built_in_health_check_is_registered_by_default(): void
    {
        $results = $this->app->make(HealthCheckRegistry::class)->runAll();
        $keys = array_map(fn ($r) => $r->key, $results);

        foreach (['database', 'cache', 'queue', 'storage', 'scheduler', 'mail_config', 'required_env'] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }
}
