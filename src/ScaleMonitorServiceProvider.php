<?php

namespace Rainwaves\ScaleMonitorClient;

use Illuminate\Console\Scheduling\Schedule;
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
use Rainwaves\ScaleMonitorClient\Http\Controllers\DependenciesController;
use Rainwaves\ScaleMonitorClient\Http\Middleware\VerifyScaleMonitorSignature;
use Rainwaves\ScaleMonitorClient\Support\HealthCheckRegistry;
use Rainwaves\ScaleMonitorClient\Support\SchedulerMeasurement;

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
                SchedulerHealthCheck::class,
                MailConfigHealthCheck::class,
                RequiredEnvHealthCheck::class,
            ] as $check) {
                $registry->registerCheck($this->app->make($check));
            }

            $disks = config('scale-monitor.storage.disks', []);
            if (! is_array($disks) || $disks === []) {
                $registry->registerCheck(new StorageHealthCheck);
            } else {
                foreach ($disks as $disk => $provider) {
                    $registry->registerCheck(new StorageHealthCheck((string) $disk, is_string($provider) ? $provider : 'unknown'));
                }
                if (! array_key_exists(config('filesystems.default'), $disks)) {
                    $registry->registerCheck(new StorageHealthCheck);
                }
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

        // Signed pull for Scale Monitor's dependency vulnerability checks. Registered
        // here (unlike the health route) so upgrading the package is enough.
        if (config('scale-monitor.dependencies.route', true) && ! $this->app->routesAreCached()) {
            $router->get('api/monitoring/v1/dependencies', DependenciesController::class)
                ->middleware('scale-monitor.signature')
                ->name('scale-monitor.dependencies');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PingCommand::class,
                HeartbeatCommand::class,
                DeploymentCommand::class,
            ]);

            if (config('scale-monitor.enabled') && config('scale-monitor.scheduler.measure', true)) {
                $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                    $schedule->call(fn () => $this->app->make(SchedulerMeasurement::class)->record())
                        ->name('scale-monitor:measure-scheduler')->everyMinute();
                });
            }
        }
    }
}
