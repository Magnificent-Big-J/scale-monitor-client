<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;
use Rainwaves\ScaleMonitorClient\Support\SchedulerMeasurement;
use Throwable;

/** Local schedule execution; received telemetry is a separate measurement. */
class SchedulerHealthCheck implements HealthCheck
{
    private const string CACHE_KEY = 'scale-monitor:scheduler:heartbeat';

    private const int STALE_AFTER_SECONDS = 180;

    public function key(): string
    {
        return 'scheduler';
    }

    public function run(): CheckResult
    {
        try {
            $lastBeat = Cache::get(SchedulerMeasurement::CACHE_KEY) ?? Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return CheckResult::critical($this->key(), 'Scheduler measurement cache unavailable.', errorCode: 'SCHEDULER_CACHE_UNAVAILABLE');
        }

        if ($lastBeat === null) {
            return CheckResult::skipped($this->key(), 'No local scheduler execution recorded yet.');
        }

        try {
            if (! $lastBeat instanceof \DateTimeInterface && (! is_string($lastBeat) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $lastBeat))) {
                return CheckResult::degraded($this->key(), 'Scheduler heartbeat time is invalid; freshness is unverified.');
            }
            $heartbeatAt = Carbon::parse($lastBeat)->utc();
        } catch (Throwable) {
            return CheckResult::degraded($this->key(), 'Scheduler heartbeat time is invalid; freshness is unverified.');
        }

        if ($heartbeatAt->gt(now()->utc())) {
            return CheckResult::degraded($this->key(), 'Scheduler heartbeat is in the future; freshness is unverified.');
        }
        $ageSeconds = max(0, (int) $heartbeatAt->diffInSeconds(now()->utc()));

        if ($ageSeconds > self::STALE_AFTER_SECONDS) {
            return CheckResult::critical($this->key(), "Scheduler heartbeat is {$ageSeconds}s old (expected < ".self::STALE_AFTER_SECONDS.'s).', errorCode: 'SCHEDULER_STALE');
        }

        return CheckResult::healthy($this->key(), "Scheduler heartbeat is {$ageSeconds}s old.");
    }
}
