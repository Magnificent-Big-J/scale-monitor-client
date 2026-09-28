<?php

namespace Rainwaves\ScaleMonitorClient\Support;

/**
 * Mirrors Scale Monitor's own `App\Modules\Telemetry\Support\
 * TelemetrySignature` exactly (05-api-and-telemetry-contract.md §3.2) — the
 * host app is on both sides of it: it verifies Scale Monitor's signed
 * inbound deep-health pull, and signs its own outbound heartbeat/error/
 * failed-job/deployment/metric/custom-event pushes with the same
 * construction.
 */
class ScaleMonitorSignature
{
    public const string HEADER_CLIENT = 'X-Scale-Client';

    public const string HEADER_TIMESTAMP = 'X-Scale-Timestamp';

    public const string HEADER_NONCE = 'X-Scale-Nonce';

    public const string HEADER_SIGNATURE = 'X-Scale-Signature';

    public const string HEADER_SCHEMA = 'X-Scale-Schema';

    public static function stringToSign(string $method, string $pathWithQuery, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            'v1',
            strtoupper($method),
            $pathWithQuery,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function sign(string $secret, string $method, string $pathWithQuery, string $timestamp, string $nonce, string $body): string
    {
        return 'v1='.hash_hmac('sha256', self::stringToSign($method, $pathWithQuery, $timestamp, $nonce, $body), $secret);
    }

    public static function matches(string $secret, string $expectedSignatureHeader, string $method, string $pathWithQuery, string $timestamp, string $nonce, string $body): bool
    {
        return hash_equals(self::sign($secret, $method, $pathWithQuery, $timestamp, $nonce, $body), $expectedSignatureHeader);
    }
}
