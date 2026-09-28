<?php

namespace Rainwaves\ScaleMonitorClient\Contracts;

use Rainwaves\ScaleMonitorClient\Support\CheckResult;

/**
 * One entry the deep-health route runs and reports. Built-ins (database,
 * cache, queue, storage, scheduler, mail config, required env) cover the
 * generic Laravel-app case; a host app registers its own (e.g. a
 * third-party API canary) via `HealthCheckRegistry::registerCheck()`.
 *
 * Implementations must never throw — `HealthCheckRegistry::runAll()` wraps
 * every check in a try/catch as a backstop, but a check that can legitimately
 * fail (a network call, a query) should catch its own expected failure modes
 * and return CheckResult::critical() rather than let an exception escape.
 */
interface HealthCheck
{
    /** A short, stable identifier — becomes the check's `key` in the payload. */
    public function key(): string;

    public function run(): CheckResult;
}
