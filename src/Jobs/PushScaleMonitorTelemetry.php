<?php

namespace Rainwaves\ScaleMonitorClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;
use Throwable;

/**
 * Signs and posts one telemetry event to Scale Monitor
 * (05-api-and-telemetry-contract.md §3). Queued so a Scale Monitor outage
 * never blocks the caller (a request, a command, an exception handler) —
 * fail open: every failure is logged at `debug` and never thrown, including
 * once retries are exhausted (`failed()` below).
 */
class PushScaleMonitorTelemetry implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [1, 5, 15];

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        private readonly string $endpoint,
        private array $payload,
    ) {
        $this->onQueue(config('scale-monitor.queue', 'default'));
    }

    public function handle(): void
    {
        $baseUrl = rtrim((string) config('scale-monitor.base_url'), '/');
        $keyId = config('scale-monitor.key_id');
        $secret = config('scale-monitor.secret');

        if (! config('scale-monitor.enabled') || empty($baseUrl) || empty($keyId) || empty($secret)) {
            return;
        }

        $this->payload['event_id'] ??= (string) Str::uuid();
        $this->payload['occurred_at'] ??= now()->toIso8601String();

        if (($release = env('APP_RELEASE')) && ! isset($this->payload['release'])) {
            $this->payload['release'] = $release;
        }

        $path = "/api/v1/telemetry/{$this->endpoint}";
        $body = json_encode($this->payload);
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $signature = ScaleMonitorSignature::sign($secret, 'POST', $path, $timestamp, $nonce, $body);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                ScaleMonitorSignature::HEADER_CLIENT => $keyId,
                ScaleMonitorSignature::HEADER_TIMESTAMP => $timestamp,
                ScaleMonitorSignature::HEADER_NONCE => $nonce,
                ScaleMonitorSignature::HEADER_SIGNATURE => $signature,
                ScaleMonitorSignature::HEADER_SCHEMA => '1.0',
            ])
                ->timeout(config('scale-monitor.timeouts.total', 3))
                ->connectTimeout(config('scale-monitor.timeouts.connect', 2))
                ->withBody($body, 'application/json')
                ->post($baseUrl.$path);

            if ($response->failed()) {
                Log::debug('Scale Monitor telemetry push rejected', [
                    'endpoint' => $this->endpoint,
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $e) {
            Log::debug('Scale Monitor telemetry push failed', [
                'endpoint' => $this->endpoint,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Never let a Scale Monitor outage pile up retries in the queue. */
    public function failed(Throwable $exception): void
    {
        Log::debug('Scale Monitor telemetry push exhausted retries', [
            'endpoint' => $this->endpoint,
            'error' => $exception->getMessage(),
        ]);
    }
}
