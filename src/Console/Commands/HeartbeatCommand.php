<?php

namespace Rainwaves\ScaleMonitorClient\Console\Commands;

use Illuminate\Console\Command;
use Rainwaves\ScaleMonitorClient\ScaleMonitor;

/**
 * Meant for the host app's own schedule, e.g.
 * `Schedule::command('scale-monitor:heartbeat scheduler')->everyMinute()` in
 * routes/console.php — this package deliberately doesn't register that
 * schedule entry itself, since the cadence and key naming are the host
 * app's own decision (a per-queue-worker heartbeat needs a different
 * command per queue, for instance).
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'scale-monitor:heartbeat {key : The heartbeat expectation key configured in Scale Monitor}';

    protected $description = 'Report a heartbeat to Scale Monitor (no-op if not configured).';

    public function handle(ScaleMonitor $scaleMonitor): int
    {
        $scaleMonitor->heartbeat($this->argument('key'));

        return self::SUCCESS;
    }
}
