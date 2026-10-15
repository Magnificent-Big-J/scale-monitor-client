<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;

class DependenciesRouteDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scale-monitor.dependencies.route', false);
    }

    public function test_the_route_can_be_turned_off(): void
    {
        $this->assertFalse($this->app['router']->has('scale-monitor.dependencies'));
    }
}
