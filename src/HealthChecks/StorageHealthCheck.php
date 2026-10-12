<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;
use Throwable;

class StorageHealthCheck implements HealthCheck
{
    private const array PROVIDERS = ['local', 'amazon_s3', 'supabase', 'digitalocean_spaces', 's3_compatible', 'unknown'];

    public function __construct(private readonly ?string $diskName = null, private readonly string $provider = 'unknown') {}

    public function key(): string
    {
        if ($this->diskName === null) {
            return 'storage';
        }
        $provider = in_array($this->provider, self::PROVIDERS, true) ? $this->provider : 'unknown';
        $disk = preg_match('/^[a-zA-Z0-9_-]{1,24}$/', $this->diskName) ? $this->diskName : 'invalid';

        return 'storage_'.$provider.'_'.$disk;
    }

    public function run(): CheckResult
    {
        $name = $this->diskName ?? config('filesystems.default');
        $config = is_string($name) ? config('filesystems.disks.'.$name) : null;
        if (! is_array($config) || ($this->diskName !== null && ! preg_match('/^[a-zA-Z0-9_-]{1,24}$/', $this->diskName))) {
            return CheckResult::skipped($this->key(), 'Storage disk is not configured.');
        }
        if (! in_array($config['driver'] ?? null, ['local', 's3'], true)) {
            return CheckResult::skipped($this->key(), 'Storage driver has no bounded probe support.');
        }
        if ($config['read-only'] ?? false) {
            return CheckResult::skipped($this->key(), 'Storage disk is read-only; write probe is not configured.');
        }
        if ($config['driver'] === 's3' && empty($config['bucket'])) {
            return CheckResult::skipped($this->key(), 'Storage bucket is not configured.');
        }
        $probe = '.scale-monitor/probes/'.Str::uuid();
        $payload = (string) Str::uuid();
        $start = microtime(true);
        $failure = null;
        $disk = null;
        $attemptedWrite = false;
        try {
            // Isolate probe settings from the host's application disk.
            $config['throw'] = true;
            if ($config['driver'] === 's3') {
                $config['http'] = [...($config['http'] ?? []), 'connect_timeout' => 2, 'timeout' => 3];
                $config['retries'] = 0;
            }
            $disk = Storage::build($config);
            $attemptedWrite = true;
            if ($disk->put($probe, $payload) !== true) {
                $failure = 'STORAGE_WRITE_FAILED';
            } elseif ($disk->get($probe) !== $payload) {
                $failure = 'STORAGE_READ_FAILED';
            }
        } catch (Throwable) {
            $failure = 'STORAGE_UNREACHABLE';
        } finally {
            if ($disk !== null && $attemptedWrite) {
                try {
                    if ($disk->delete($probe) !== true) {
                        $failure = 'STORAGE_CLEANUP_FAILED';
                    }
                } catch (Throwable) {
                    $failure = 'STORAGE_CLEANUP_FAILED';
                }
            }
        }
        $latency = (int) round((microtime(true) - $start) * 1000);
        if ($failure !== null) {
            return CheckResult::critical($this->key(), 'Storage write/read/delete probe failed.', $latency, errorCode: $failure);
        }

        return CheckResult::healthy($this->key(), 'Storage write/read/delete probe passed.', $latency);
    }
}
