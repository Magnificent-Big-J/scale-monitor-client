<?php

namespace Rainwaves\ScaleMonitorClient\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Rainwaves\ScaleMonitorClient\ScaleMonitor;
use Throwable;

/**
 * Real-time, per-failure signal to Scale Monitor's own attempt-count-tiered
 * threshold evaluation (07-incident-lifecycle.md §3.3) — this package never
 * evaluates a threshold itself, just reports the raw event.
 *
 * NOT auto-discovered — Laravel's event auto-discovery only scans the host
 * app's own listener directories, never a package's. Register it explicitly
 * in the host app's own EventServiceProvider/AppServiceProvider:
 *
 *   Event::listen(JobFailed::class, ReportFailedJobToScaleMonitor::class);
 */
class ReportFailedJobToScaleMonitor
{
    public function __construct(private readonly ScaleMonitor $scaleMonitor) {}

    public function handle(JobFailed $event): void
    {
        try {
            $this->scaleMonitor->failedJob(
                job: $event->job->resolveName(),
                queue: $event->job->getQueue(),
                connection: $event->connectionName,
                exceptionClass: get_class($event->exception),
                message: $event->exception->getMessage() ?: get_class($event->exception),
                attempts: $event->job->attempts(),
            );
        } catch (Throwable) {
            // ScaleMonitor already fails open internally; this guards only
            // against $event->job's own accessors misbehaving for some
            // exotic queue driver/job shape.
        }
    }
}
