<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;

/**
 * A configuration check, not a live send — verifies the app hasn't been
 * accidentally left on a driver that silently discards mail (`log`/`array`)
 * outside local/testing, and that a from-address is actually set. A real
 * send belongs to the host app's own synthetic-monitoring layer, not this
 * package.
 */
class MailConfigHealthCheck implements HealthCheck
{
    public function key(): string
    {
        return 'mail_config';
    }

    public function run(): CheckResult
    {
        $driver = config('mail.default');
        $fromAddress = config('mail.from.address');

        if (empty($fromAddress)) {
            return CheckResult::critical($this->key(), 'No MAIL_FROM_ADDRESS configured.', errorCode: 'MAIL_FROM_MISSING');
        }

        if (in_array($driver, ['log', 'array'], true) && app()->environment('production')) {
            return CheckResult::degraded($this->key(), "Mail driver is '{$driver}' in production — outbound mail is silently discarded.");
        }

        return CheckResult::healthy($this->key(), "Mail driver '{$driver}' configured with a from-address.");
    }
}
