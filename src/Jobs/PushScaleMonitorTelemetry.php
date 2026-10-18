<?php

namespace Rainwaves\ScaleMonitorClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rainwaves\ScaleMonitorClient\Support\DeliveryStatus;
use Rainwaves\ScaleMonitorClient\Support\Redactor;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;
use Throwable;

/** One attempt per execution. Only actual asynchronous jobs may be released. */
class PushScaleMonitorTelemetry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly string $endpoint, private array $payload)
    {
        $this->payload['event_id'] ??= (string) Str::uuid();
        $this->payload['occurred_at'] ??= now()->utc()->toIso8601String();
        if (($release = env('APP_RELEASE')) && ! isset($this->payload['release'])) {
            $this->payload['release'] = $release;
        }
        $this->payload = Redactor::payload($endpoint, $this->payload);
        $this->tries = 1 + max(0, min(5, (int) config('scale-monitor.retries', 2)));
        $this->onQueue(config('scale-monitor.queue', 'default'));
    }

    public function handle(): void
    {
        if (! config('scale-monitor.enabled')) {
            return;
        }
        try {
            $baseUrl = rtrim((string) config('scale-monitor.base_url'), '/');
            $keyId = config('scale-monitor.key_id');
            $secret = config('scale-monitor.secret');
            if ($baseUrl === '' || empty($keyId) || empty($secret)) {
                $this->outcome('rejected', 'not_configured');

                return;
            }
            // Legacy serialized jobs need stable identity from the queue envelope.
            $envelope = $this->job?->payload() ?? [];
            $this->payload['event_id'] ??= $envelope['uuid'] ?? (string) Str::uuid();
            $this->payload['occurred_at'] ??= is_numeric($envelope['createdAt'] ?? null)
                ? Carbon::createFromTimestampUTC((int) $envelope['createdAt'])->toIso8601String() : now()->utc()->toIso8601String();
            $this->payload = Redactor::payload($this->endpoint, $this->payload);
            $path = "/api/v1/telemetry/{$this->endpoint}";
            $body = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $timestamp = (string) now()->timestamp;
            $nonce = (string) Str::uuid();
            $totalTimeout = max(0.1, min(10, (float) config('scale-monitor.timeouts.total', 3)));
            $connectTimeout = max(0.1, min($totalTimeout, (float) config('scale-monitor.timeouts.connect', 2)));
            $response = Http::withHeaders([
                ScaleMonitorSignature::HEADER_CLIENT => $keyId,
                ScaleMonitorSignature::HEADER_TIMESTAMP => $timestamp,
                ScaleMonitorSignature::HEADER_NONCE => $nonce,
                ScaleMonitorSignature::HEADER_SIGNATURE => ScaleMonitorSignature::sign($secret, 'POST', $path, $timestamp, $nonce, $body),
                ScaleMonitorSignature::HEADER_SCHEMA => '1.0',
            ])->withoutRedirecting()
                ->timeout($totalTimeout)
                ->connectTimeout($connectTimeout)
                ->withBody($body, 'application/json')->post($baseUrl.$path);
            if ($response->status() === 202) {
                $this->outcome('accepted', 'http_accepted', $response->status());
            } elseif (in_array($response->status(), [408, 425, 429], true) || $response->serverError()) {
                $this->transient('http_transient', $response->status(), $response->header('Retry-After'));
            } else {
                $this->outcome('rejected', match ($response->status()) {
                    401, 403 => 'authentication_rejected', 422 => 'payload_rejected',
                    default => 'http_rejected',
                }, $response->status());
            }
        } catch (ConnectionException) {
            $this->transient('connection_failed');
        } catch (Throwable) {
            $this->outcome('rejected', 'transport_error');
        }
    }

    private function transient(string $category, ?int $status = null, ?string $retryAfter = null): void
    {
        $async = $this->job !== null && ! ($this->job instanceof SyncJob);
        $attempt = $async ? $this->job->attempts() : 1;
        if ($async && $attempt < $this->tries) {
            $base = [1, 5, 15, 30, 60][min(4, max(0, $attempt - 1))];
            $delay = min(60, $base + random_int(0, max(1, (int) ($base / 4))));
            if ($retryAfter !== null) {
                $suggested = ctype_digit($retryAfter) ? (float) $retryAfter : (strtotime($retryAfter) ?: 0) - now()->timestamp;
                $delay = (int) min(60, max($delay, $suggested));
            }
            try {
                $this->release($delay);
                $this->outcome('retry_scheduled', $category, $status);
            } catch (Throwable) {
                $this->outcome('exhausted', 'retry_queue_unavailable', $status);
            }
        } else {
            $this->outcome('exhausted', $category, $status);
        }
    }

    private function outcome(string $outcome, string $category, ?int $status = null): void
    {
        try {
            app(DeliveryStatus::class)->record($outcome, $category, $this->safeEndpoint(), $this->payload['event_id'] ?? null, $this->job?->attempts() ?? 1, $status);
            Log::debug('Scale Monitor telemetry delivery outcome', ['outcome' => $outcome, 'category' => $category, 'endpoint' => $this->safeEndpoint(), 'http_status' => $status]);
        } catch (Throwable) { /* Keep the caller and worker fail-open. */
        }
    }

    private function safeEndpoint(): string
    {
        return in_array($this->endpoint, ['heartbeat', 'error', 'failed-job', 'deployment', 'metric', 'custom-event'], true) ? $this->endpoint : 'unknown';
    }

    /** Worker-level exhaustion (for example a crash) also keeps a safe outcome. */
    public function failed(?Throwable $exception): void
    {
        $this->outcome('exhausted', 'worker_failed');
    }
}
