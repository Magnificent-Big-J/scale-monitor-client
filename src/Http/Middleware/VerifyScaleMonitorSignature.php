<?php

namespace Rainwaves\ScaleMonitorClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Scale Monitor's signed pull of the deep-health route
 * (05-api-and-telemetry-contract.md §4.2), the same construction
 * `PushScaleMonitorTelemetry` uses for outbound pushes, just the host app
 * playing the server role instead of the client role here. There's exactly
 * one configured credential per environment — no DB lookup, just a config
 * comparison.
 */
class VerifyScaleMonitorSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $keyId = config('scale-monitor.key_id');
        $secret = config('scale-monitor.secret');

        if (empty($keyId) || empty($secret)) {
            return response()->json(['error' => 'Scale Monitor integration is not configured.'], 404);
        }

        $providedKeyId = (string) $request->header(ScaleMonitorSignature::HEADER_CLIENT);

        if (! hash_equals($keyId, $providedKeyId)) {
            return response()->json(['error' => 'unknown_client'], 401);
        }

        $timestamp = (string) $request->header(ScaleMonitorSignature::HEADER_TIMESTAMP);

        if ($timestamp === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return response()->json(['error' => 'stale_timestamp'], 401);
        }

        $nonce = (string) $request->header(ScaleMonitorSignature::HEADER_NONCE);

        if ($nonce === '' || strlen($nonce) < 16 || strlen($nonce) > 64) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        if (! Cache::add("scale-monitor:nonce:{$nonce}", 1, 600)) {
            return response()->json(['error' => 'replayed_nonce'], 401);
        }

        $signature = (string) $request->header(ScaleMonitorSignature::HEADER_SIGNATURE);
        $pathWithQuery = $request->getRequestUri();

        if (! ScaleMonitorSignature::matches($secret, $signature, $request->method(), $pathWithQuery, $timestamp, $nonce, (string) $request->getContent())) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        return $next($request);
    }
}
