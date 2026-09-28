<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Illuminate\Support\Facades\Redis;
use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;
use Throwable;

/**
 * Only actually verifies connectivity for the `redis` queue driver (by far
 * the most common in this fleet) — `sync`/`database`/`sqs` etc. have no
 * separate broker to ping, so those report `healthy` unconditionally rather
 * than a misleading `skipped` (a healthy-by-construction result is
 * different from "this check doesn't apply here" — see StorageHealthCheck's
 * docblock for the same distinction the other way around).
 */
class QueueHealthCheck implements HealthCheck
{
    public function key(): string
    {
        return 'queue';
    }

    public function run(): CheckResult
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");

        if ($driver !== 'redis') {
            return CheckResult::healthy($this->key(), "Queue driver '{$driver}' has no separate broker to check.");
        }

        try {
            $connectionName = config("queue.connections.{$connection}.connection", 'default');
            Redis::connection($connectionName)->ping();
        } catch (Throwable $e) {
            return CheckResult::critical($this->key(), 'Queue backend (Redis) unreachable.', errorCode: 'QUEUE_UNREACHABLE');
        }

        return CheckResult::healthy($this->key(), 'Queue backend reachable.');
    }
}
