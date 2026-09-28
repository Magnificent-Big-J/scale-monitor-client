<?php

namespace Rainwaves\ScaleMonitorClient\Support;

/**
 * One entry in the deep-health payload's `checks[]` array
 * (05-api-and-telemetry-contract.md §4.2).
 */
final class CheckResult
{
    private function __construct(
        public readonly string $key,
        public readonly string $status,
        public readonly ?string $summary = null,
        public readonly ?int $latencyMs = null,
        public readonly ?string $severityHint = null,
        public readonly ?string $errorCode = null,
    ) {}

    public static function healthy(string $key, ?string $summary = null, ?int $latencyMs = null): self
    {
        return new self($key, 'healthy', $summary, $latencyMs);
    }

    public static function degraded(string $key, ?string $summary = null, ?int $latencyMs = null, ?string $severityHint = null): self
    {
        return new self($key, 'degraded', $summary, $latencyMs, $severityHint);
    }

    public static function critical(string $key, ?string $summary = null, ?int $latencyMs = null, ?string $severityHint = null, ?string $errorCode = null): self
    {
        return new self($key, 'critical', $summary, $latencyMs, $severityHint, $errorCode);
    }

    public static function skipped(string $key, ?string $summary = null): self
    {
        return new self($key, 'skipped', $summary);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'status' => $this->status,
            'latency_ms' => $this->latencyMs,
            // 05 §4.2's own cap — never include hosts/paths/SQL/secrets in a
            // summary; that's the caller's own responsibility, this class
            // only enforces the length.
            'summary' => $this->summary !== null ? mb_substr($this->summary, 0, 300) : null,
            'severity_hint' => $this->severityHint,
            'error_code' => $this->errorCode,
        ], fn ($value) => $value !== null);
    }
}
