<?php

namespace Rainwaves\ScaleMonitorClient\Support;

use Closure;
use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Throwable;

/**
 * Holds every check the deep-health route runs — the seven built-ins
 * (registered by the service provider) plus whatever a host app adds via
 * `registerCheck()`, typically in its own AppServiceProvider::boot(), e.g.
 * a third-party API canary derived from the app's own existing incident
 * state rather than a live (possibly chargeable) call.
 */
class HealthCheckRegistry
{
    /** @var array<string, HealthCheck> */
    private array $checks = [];

    public function registerCheck(HealthCheck|string $check, ?Closure $runner = null): void
    {
        if ($check instanceof HealthCheck) {
            $this->checks[$check->key()] = $check;

            return;
        }

        // registerCheck('google_maps', fn () => CheckResult::healthy('google_maps'))
        // — a closure is the common case for a one-off, app-specific check
        // that doesn't warrant its own class.
        $this->checks[$check] = new class($check, $runner) implements HealthCheck
        {
            public function __construct(private readonly string $key, private readonly Closure $runner) {}

            public function key(): string
            {
                return $this->key;
            }

            public function run(): CheckResult
            {
                return ($this->runner)();
            }
        };
    }

    /** @return list<CheckResult> */
    public function runAll(): array
    {
        return array_map(function (HealthCheck $check) {
            try {
                return $check->run();
            } catch (Throwable $e) {
                // A broken check (app-specific or built-in) must never take
                // down the rest of the payload or the route itself.
                return CheckResult::critical($check->key(), 'Health check threw: '.$e->getMessage());
            }
        }, array_values($this->checks));
    }
}
