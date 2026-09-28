<?php

namespace Rainwaves\ScaleMonitorClient\Exceptions;

use Rainwaves\ScaleMonitorClient\ScaleMonitor;
use Throwable;

/**
 * Mix into the host app's own exception-reporting path — e.g.
 * bootstrap/app.php's `->withExceptions()`:
 *
 *   use Rainwaves\ScaleMonitorClient\Exceptions\ReportsToScaleMonitor;
 *
 *   class Handler { use ReportsToScaleMonitor; }
 *
 *   ->withExceptions(function (Exceptions $exceptions) {
 *       $exceptions->reportable(fn (Throwable $e) =>
 *           app(Handler::class)->reportExceptionToScaleMonitor($e));
 *   });
 *
 * Applies config('scale-monitor.report_exceptions')'s min_severity and
 * sampling gates before ever calling ScaleMonitor::error() — deliberately
 * opt-in per call site (you choose the severity), not automatic
 * classification of arbitrary exceptions, which this package has no
 * reliable way to do generically.
 */
trait ReportsToScaleMonitor
{
    private const array SEVERITY_RANK = ['low' => 0, 'warning' => 1, 'high' => 2, 'critical' => 3];

    /** @param  array<string, mixed>  $context */
    public function reportExceptionToScaleMonitor(Throwable $e, string $severity = 'high', array $context = [], ?string $errorCode = null): void
    {
        if (! $this->meetsMinimumSeverity($severity)) {
            return;
        }

        if (! $this->passesSampling()) {
            return;
        }

        app(ScaleMonitor::class)->error(
            severity: $severity,
            exceptionClass: get_class($e),
            message: $e->getMessage() ?: get_class($e),
            errorCode: $errorCode,
            context: $context,
        );
    }

    private function meetsMinimumSeverity(string $severity): bool
    {
        $minimum = config('scale-monitor.report_exceptions.min_severity', 'high');

        return (self::SEVERITY_RANK[$severity] ?? 0) >= (self::SEVERITY_RANK[$minimum] ?? 2);
    }

    private function passesSampling(): bool
    {
        $sampling = (float) config('scale-monitor.report_exceptions.sampling', 1.0);

        return $sampling >= 1.0 || mt_rand() / mt_getrandmax() < $sampling;
    }
}
