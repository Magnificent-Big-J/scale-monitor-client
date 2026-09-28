<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;

/**
 * APP_KEY is always checked unconditionally (a missing one breaks
 * encryption/signed URLs/sessions app-wide). Anything beyond that is the
 * host app's own list — config('scale-monitor.required_env', []) — since
 * this package has no way to know which other env vars a given app
 * actually depends on.
 */
class RequiredEnvHealthCheck implements HealthCheck
{
    public function key(): string
    {
        return 'required_env';
    }

    public function run(): CheckResult
    {
        $missing = [];

        if (blank(config('app.key'))) {
            $missing[] = 'APP_KEY';
        }

        foreach (config('scale-monitor.required_env', []) as $variable) {
            if (blank(env($variable))) {
                $missing[] = $variable;
            }
        }

        if ($missing !== []) {
            return CheckResult::critical($this->key(), 'Missing required env vars: '.implode(', ', $missing), errorCode: 'REQUIRED_ENV_MISSING');
        }

        return CheckResult::healthy($this->key(), 'Every required env var is set.');
    }
}
