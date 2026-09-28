<?php

namespace Rainwaves\ScaleMonitorClient;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Rainwaves\ScaleMonitorClient\Console\Commands\DeploymentCommand;
use Rainwaves\ScaleMonitorClient\Console\Commands\HeartbeatCommand;
use Rainwaves\ScaleMonitorClient\Console\Commands\PingCommand;
use Rainwaves\ScaleMonitorClient\HealthChecks\CacheHealthCheck;
use Rainwaves\ScaleMonitorClient\HealthChecks\DatabaseHealthCheck;
use Rainwaves\ScaleMonitorClient\HealthChecks\MailConfigHealthCheck;
use Rainwaves\ScaleMonitorClient\HealthChecks\QueueHealthCheck;
use Rainwaves\ScaleMonitorClient\HealthChecks\RequiredEnvHealthCheck;
use Rainwaves\ScaleMonitorClient\HealthChecks\SchedulerHealthCheck;
use Rainwaves\ScaleMonitorClient\HealthChecks\StorageHealthCheck;
use Rainwaves\ScaleMonitorClient\Http\Middleware\VerifyScaleMonitorSignature;
use Rainwaves\ScaleMonitorClient\Support\HealthCheckRegistry;

class ScaleMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scale-monitor.php', 'scale-monitor');

        $this->app->singleton(ScaleMonitor::class);

        $this->app->singleton(HealthCheckRegistry::class, function () {
            $registry = new HealthCheckRegistry;

            foreach ([
                DatabaseHealthCheck::class,
                CacheHealthCheck::class,
                QueueHealthCheck::class,
                StorageHealthCheck::class,
                SchedulerHealthCheck::class,
                MailConfigHealthCheck::class,
                RequiredEnvHealthCheck::class,
            ] as $check) {
                $registry->registerCheck($this->app->make($check));
            }

            return $registry;
        });
    }

    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__.'/../config/scale-monitor.php' => config_path('scale-monitor.php'),
        ], 'scale-monitor-config');

        $router->aliasMiddleware('scale-monitor.signature', VerifyScaleMonitorSignature::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PingCommand::class,
                HeartbeatCommand::class,
                DeploymentCommand::class,
            ]);
        }
    }
}
