<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Illuminate\Support\Facades\Cache;
use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;

/**
 * This package doesn't own the host app's own schedule, so it can't tell on
 * its own whether `schedule:run` is actually firing every minute (e.g. a
 * dead cron entry). It reports against a cache key the host app is expected
 * to touch itself once a minute — e.g.
 * `Schedule::call(fn () => Cache::put('scale-monitor:scheduler:heartbeat',
 * now(), 300))->everyMinute()` in routes/console.php. If that key was never
 * wired up at all, this reports `skipped` rather than a false alarm.
 */
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
        $lastBeat = Cache::get(self::CACHE_KEY);

        if ($lastBeat === null) {
            return CheckResult::skipped($this->key(), 'No scheduler heartbeat wired up — see this check\'s own docblock.');
        }

        $ageSeconds = now()->diffInSeconds($lastBeat);

        if ($ageSeconds > self::STALE_AFTER_SECONDS) {
            return CheckResult::critical($this->key(), "Scheduler heartbeat is {$ageSeconds}s old (expected < ".self::STALE_AFTER_SECONDS.'s).', errorCode: 'SCHEDULER_STALE');
        }

        return CheckResult::healthy($this->key(), "Scheduler heartbeat is {$ageSeconds}s old.");
    }
}
