<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Illuminate\Support\Facades\DB;
use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;
use Throwable;

class DatabaseHealthCheck implements HealthCheck
{
    public function key(): string
    {
        return 'database';
    }

    public function run(): CheckResult
    {
        $start = microtime(true);

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return CheckResult::critical($this->key(), 'Database unreachable.', errorCode: 'DB_UNREACHABLE');
        }

        return CheckResult::healthy($this->key(), 'Database reachable.', (int) round((microtime(true) - $start) * 1000));
    }
}
