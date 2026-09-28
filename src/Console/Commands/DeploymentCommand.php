<?php

namespace Rainwaves\ScaleMonitorClient\Console\Commands;

use Illuminate\Console\Command;
use Rainwaves\ScaleMonitorClient\ScaleMonitor;

/**
 * Not part of the originally-specified command set (ping/heartbeat) but
 * kept from SHC's own real usage (its deploy pipeline's `afterHooks` calls
 * this directly) — genuinely useful enough to ship, not scope creep: every
 * app onboarding this package will eventually want a deployment marker in
 * its own CI pipeline, and hand-rolling this again per app is exactly what
 * extracting the package was meant to avoid.
 */
class DeploymentCommand extends Command
{
    protected $signature = 'scale-monitor:deployment
        {--release= : Release identifier; defaults to APP_RELEASE if set}
        {--commit= : Full 40-character commit SHA}
        {--status=succeeded : started|succeeded|failed|rolled_back}
        {--notes= : Optional free-text notes}';

    protected $description = 'Report a deployment marker to Scale Monitor (no-op if not configured).';

    public function handle(ScaleMonitor $scaleMonitor): int
    {
        $release = $this->option('release') ?: env('APP_RELEASE');

        if (empty($release)) {
            $this->warn('No release identifier available (pass --release or set APP_RELEASE) — skipping.');

            return self::SUCCESS;
        }

        $scaleMonitor->deployment(
            release: $release,
            commitSha: $this->option('commit') ?: null,
            status: $this->option('status'),
            deployedBy: get_current_user() ?: null,
            notes: $this->option('notes') ?: null,
        );

        $this->info("Reported deployment {$release} to Scale Monitor (if configured).");

        return self::SUCCESS;
    }
}
