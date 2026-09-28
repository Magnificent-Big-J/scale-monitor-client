<?php

namespace Rainwaves\ScaleMonitorClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Rainwaves\ScaleMonitorClient\Support\HealthCheckRegistry;

/**
 * The deep-health pull Scale Monitor's own `DeepHealthCheckRunner` signs and
 * fetches (05-api-and-telemetry-contract.md §4.2). Register the route
 * yourself, gated by `VerifyScaleMonitorSignature`:
 *
 *   Route::get('/api/monitoring/v1/health', HealthController::class)
 *       ->middleware('scale-monitor.signature');
 */
class HealthController
{
    public function __invoke(HealthCheckRegistry $registry): JsonResponse
    {
        $checks = $registry->runAll();
        $statuses = array_map(fn ($c) => $c->status, $checks);

        $overall = match (true) {
            in_array('critical', $statuses, true) => 'critical',
            in_array('degraded', $statuses, true) => 'degraded',
            default => 'healthy',
        };

        return response()->json([
            'schema_version' => '1.0',
            'application' => config('scale-monitor.application'),
            'environment' => config('scale-monitor.environment'),
            'release' => env('APP_RELEASE'),
            'status' => $overall,
            'observed_at' => now()->toIso8601String(),
            'checks' => array_map(fn ($c) => $c->toArray(), $checks),
        ]);
    }
}
