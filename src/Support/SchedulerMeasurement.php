<?php

namespace Rainwaves\ScaleMonitorClient\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** Records only execution of the scheduled callback, independently of transport. */
class SchedulerMeasurement
{
    public const string CACHE_KEY = 'scale-monitor:scheduler:last-run';

    public function record(): void
    {
        try {
            // Do not expire: a stopped scheduler must remain stale, not become skipped.
            Cache::forever(self::CACHE_KEY, now()->utc()->toIso8601String());
        } catch (Throwable) {
            // Monitoring must never interrupt the host's schedule. The cache check
            // and scheduler diagnostic will report unavailable evidence separately.
        }
    }
}
