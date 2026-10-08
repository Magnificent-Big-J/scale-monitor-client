<?php

namespace Rainwaves\ScaleMonitorClient;

use Illuminate\Support\Facades\Log;
use Rainwaves\ScaleMonitorClient\Jobs\PushScaleMonitorTelemetry;
use Rainwaves\ScaleMonitorClient\Support\Redactor;
use Rainwaves\ScaleMonitorClient\Support\RuntimeSnapshot;
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
        if (! config('scale-monitor.enabled')) {
            return;
        }
        if ($key === 'scheduler' && config('scale-monitor.runtime_metadata', true)) {
            try {
                $candidate = [...$meta, 'runtime' => app(RuntimeSnapshot::class)->collect()];
                if (strlen(json_encode($candidate) ?: '') <= 1024) {
                    $meta = $candidate;
                }
            } catch (Throwable) {
                // Failure to collect diagnostics must not prevent a heartbeat.
            }
        }
        $this->push('heartbeat', array_filter([
            'key' => $key,
            'meta' => $meta !== [] ? $this->redact($meta) : null,
        ], static fn ($value) => $value !== null));
    }

    public function error(string $severity, string $exceptionClass, string $message, ?string $errorCode = null, array $context = []): void
    {
        $this->push('error', array_filter([
            'severity' => $severity === 'low' ? 'info' : $severity,
            'exception_class' => $exceptionClass,
            'message' => mb_substr($message, 0, 2000),
            'error_code' => $errorCode,
            'context' => $context !== [] ? $this->redact($context) : null,
        ], static fn ($value) => $value !== null));
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
        ], static fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $value  {value, observed_at?, tags?}; configure thresholds in Scale Monitor's metric definitions.
     */
    public function metric(string $key, array $value): void
    {
        $this->push('metric', ['samples' => [array_filter([
            'key' => $key,
            'value' => $value['value'] ?? null,
            'observed_at' => $value['observed_at'] ?? now()->utc()->toIso8601String(),
            'tags' => isset($value['tags']) && is_array($value['tags']) ? $this->redact($value['tags']) : null,
        ], static fn ($value) => $value !== null)]]);
    }

    /**
     * A generic named event outside the five typed endpoints above. Only
     * ever participates in incident-threshold evaluation on Scale Monitor's
     * side when $fingerprint is a stable, caller-chosen string (never a
     * per-call-random one) — an event with no fingerprint is recorded but
     * can never open an incident on its own, by design.
     */
    public function event(string $name, string $severity = 'info', array $context = [], ?string $fingerprint = null, ?string $message = null): void
    {
        $this->push('custom-event', array_filter([
            'kind' => mb_substr($name, 0, 64),
            'title' => mb_substr($name, 0, 191),
            'message' => mb_substr($message ?? $name, 0, 2000),
            'severity' => $severity === 'low' ? 'info' : $severity,
            'context' => $context !== [] ? $this->redact($context) : null,
            'fingerprint' => $fingerprint,
        ], static fn ($value) => $value !== null));
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
            ], static fn ($value) => $value !== null));
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
