<?php

namespace Rainwaves\ScaleMonitorClient;

use Illuminate\Support\Facades\Log;
use Rainwaves\ScaleMonitorClient\Jobs\PushScaleMonitorTelemetry;
use Rainwaves\ScaleMonitorClient\Support\Redactor;
use Throwable;

/**
 * The single entry point a host app uses to talk to Scale Monitor —
 * heartbeats, errors, failed jobs, deployment markers, metric samples, and
 * generic custom events, one method per `/api/v1/telemetry/{type}` endpoint
 * (05-api-and-telemetry-contract.md §3).
 *
 * Every method is wrapped and never throws: a disabled/misconfigured
 * integration, or dispatching onto a broken queue connection, must never be
 * the thing that breaks a real request, command, or exception path. This is
 * the fail-open contract the whole package is built around — see the
 * `FailOpenTest` in this package's own test suite.
 */
class ScaleMonitor
{
    public function heartbeat(string $key, array $meta = []): void
    {
        $this->push('heartbeat', array_filter([
            'key' => $key,
            'meta' => $meta !== [] ? $this->redact($meta) : null,
        ]));
    }

    public function error(string $severity, string $exceptionClass, string $message, ?string $errorCode = null, array $context = []): void
    {
        $this->push('error', array_filter([
            'severity' => $severity,
            'exception_class' => $exceptionClass,
            'message' => mb_substr($message, 0, 2000),
            'error_code' => $errorCode,
            'context' => $context !== [] ? $this->redact($context) : null,
        ]));
    }

    public function failedJob(string $job, ?string $queue, ?string $connection, string $exceptionClass, string $message, int $attempts): void
    {
        $this->push('failed-job', array_filter([
            'job' => $job,
            'queue' => $queue,
            'connection' => $connection,
            'exception_class' => $exceptionClass,
            'message' => mb_substr($message, 0, 2000),
            'attempts' => $attempts,
            'failed_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $value  {value, warning_min?, warning_max?, critical_min?, critical_max?} — only `value` is required, the rest let Scale Monitor evaluate a threshold breach without a separately-configured MetricDefinition first existing for a brand-new key.
     */
    public function metric(string $key, array $value): void
    {
        $this->push('metric', array_filter([
            'key' => $key,
            ...$value,
        ]));
    }

    /**
     * A generic named event outside the five typed endpoints above. Only
     * ever participates in incident-threshold evaluation on Scale Monitor's
     * side when $fingerprint is a stable, caller-chosen string (never a
     * per-call-random one) — an event with no fingerprint is recorded but
     * can never open an incident on its own, by design.
     */
    public function event(string $name, string $severity = 'info', array $context = [], ?string $fingerprint = null): void
    {
        $this->push('custom-event', array_filter([
            'name' => $name,
            'severity' => $severity,
            'context' => $context !== [] ? $this->redact($context) : null,
            'fingerprint' => $fingerprint,
        ]));
    }

    /**
     * Dispatched synchronously, unlike every other event here — this is a
     * rare, one-off call from a deploy pipeline (the package's own
     * `scale-monitor:deployment` command, or a host app's own deploy hook),
     * not a hot path, and it should either land or fail before the deploy
     * script moves on rather than depend on a queue worker being alive
     * mid-deploy (which may be exactly when it's being restarted).
     */
    public function deployment(string $release, ?string $commitSha, string $status, ?string $deployedBy = null, ?string $notes = null): void
    {
        if (! config('scale-monitor.enabled')) {
            return;
        }

        try {
            PushScaleMonitorTelemetry::dispatchSync('deployment', array_filter([
                'release' => $release,
                'commit_sha' => $commitSha,
                'status' => $status,
                'deployed_by' => $deployedBy,
                'notes' => $notes,
            ]));
        } catch (Throwable $e) {
            Log::debug('Unable to send Scale Monitor deployment marker', ['error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $context */
    private function redact(array $context): array
    {
        return Redactor::redact($context, config('scale-monitor.redaction', []));
    }

    /** @param  array<string, mixed>  $payload */
    private function push(string $endpoint, array $payload): void
    {
        if (! config('scale-monitor.enabled')) {
            return;
        }

        try {
            PushScaleMonitorTelemetry::dispatch($endpoint, $payload);
        } catch (Throwable $e) {
            // Dispatch itself can throw if the queue connection is down
            // (e.g. a `database` queue driver with the DB unreachable) --
            // never let that propagate into the caller.
            Log::debug('Unable to queue Scale Monitor telemetry push', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
