<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;
use Throwable;

class CacheHealthCheck implements HealthCheck
{
    public function key(): string
    {
        return 'cache';
    }

    public function run(): CheckResult
    {
        $probe = 'scale-monitor:health-probe:'.Str::random(8);
        $start = microtime(true);

        try {
            Cache::put($probe, true, 10);
            $roundTripped = Cache::get($probe) === true;
            Cache::forget($probe);
        } catch (Throwable $e) {
            return CheckResult::critical($this->key(), 'Cache unreachable.', errorCode: 'CACHE_UNREACHABLE');
        }

        if (! $roundTripped) {
            return CheckResult::critical($this->key(), 'Cache round-trip did not return the expected value.');
        }

        return CheckResult::healthy($this->key(), 'Cache reachable.', (int) round((microtime(true) - $start) * 1000));
    }
}
