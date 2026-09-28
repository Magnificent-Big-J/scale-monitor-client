<?php

namespace Rainwaves\ScaleMonitorClient\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;
use Throwable;

/**
 * A manual, synchronous "is my configuration actually correct" diagnostic —
 * signs and calls Scale Monitor's own `GET /api/v1/telemetry/ping` (verifies
 * a credential without requiring any particular scope). Never queued,
 * unlike every other call this package makes — the whole point is to see
 * the real result immediately while debugging a fresh setup.
 */
class PingCommand extends Command
{
    protected $signature = 'scale-monitor:ping';

    protected $description = 'Verify this application\'s Scale Monitor credentials by calling the ping endpoint directly.';

    public function handle(): int
    {
        if (! config('scale-monitor.enabled')) {
            $this->warn('scale-monitor.enabled is false — nothing to ping.');

            return self::SUCCESS;
        }

        $baseUrl = rtrim((string) config('scale-monitor.base_url'), '/');
        $keyId = config('scale-monitor.key_id');
        $secret = config('scale-monitor.secret');

        if (empty($baseUrl) || empty($keyId) || empty($secret)) {
            $this->error('base_url, key_id, and secret must all be set.');

            return self::FAILURE;
        }

        $path = '/api/v1/telemetry/ping';
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $signature = ScaleMonitorSignature::sign($secret, 'GET', $path, $timestamp, $nonce, '');

        try {
            $response = Http::withHeaders([
                ScaleMonitorSignature::HEADER_CLIENT => $keyId,
                ScaleMonitorSignature::HEADER_TIMESTAMP => $timestamp,
                ScaleMonitorSignature::HEADER_NONCE => $nonce,
                ScaleMonitorSignature::HEADER_SIGNATURE => $signature,
            ])
                ->timeout(config('scale-monitor.timeouts.total', 3))
                ->connectTimeout(config('scale-monitor.timeouts.connect', 2))
                ->get($baseUrl.$path);
        } catch (Throwable $e) {
            $this->error("Could not reach Scale Monitor: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->info("Ping succeeded ({$response->status()}) — credentials are valid.");

            return self::SUCCESS;
        }

        $this->error("Ping failed ({$response->status()}): {$response->body()}");

        return self::FAILURE;
    }
}
