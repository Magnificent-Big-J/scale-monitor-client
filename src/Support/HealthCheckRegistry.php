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

    /** @var array<string, Closure(): list<CheckResult>> */
    private array $groups = [];

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

    /**
     * For a source that produces a variable, not-known-ahead-of-time number
     * of checks from one place (e.g. an existing scheduled health-check
     * command's own cached results) — a plain `registerCheck()` can't
     * express this, since it always maps exactly one key to exactly one
     * result. $runner returns as many CheckResults as it likes; each one's
     * own `key` still ends up in the payload individually, exactly as if it
     * had been registered on its own.
     *
     * @param  Closure(): list<CheckResult>  $runner
     */
    public function registerCheckGroup(string $groupKey, Closure $runner): void
    {
        $this->groups[$groupKey] = $runner;
    }

    /** @return list<CheckResult> */
    public function runAll(): array
    {
        $results = array_map(function (HealthCheck $check) {
            try {
                return $check->run();
            } catch (Throwable $e) {
                // A broken check (app-specific or built-in) must never take
                // down the rest of the payload or the route itself.
                return CheckResult::critical($check->key(), 'Health check threw: '.$e->getMessage());
            }
        }, array_values($this->checks));

        foreach ($this->groups as $groupKey => $runner) {
            try {
                array_push($results, ...$runner());
            } catch (Throwable $e) {
                $results[] = CheckResult::critical($groupKey, 'Health check group threw: '.$e->getMessage());
            }
        }

        return $results;
    }
}
