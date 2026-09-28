<?php

namespace Rainwaves\ScaleMonitorClient\HealthChecks;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Rainwaves\ScaleMonitorClient\Contracts\HealthCheck;
use Rainwaves\ScaleMonitorClient\Support\CheckResult;
use Throwable;

class StorageHealthCheck implements HealthCheck
{
    public function key(): string
    {
        return 'storage';
    }

    public function run(): CheckResult
    {
        $disk = Storage::disk(config('filesystems.default'));
        $probe = '.scale-monitor-health-probe-'.Str::random(8);
        $start = microtime(true);

        try {
            $disk->put($probe, 'ok');
            $roundTripped = $disk->get($probe) === 'ok';
            $disk->delete($probe);
        } catch (Throwable $e) {
            return CheckResult::critical($this->key(), 'Default filesystem disk unreachable.', errorCode: 'STORAGE_UNREACHABLE');
        }

        if (! $roundTripped) {
            return CheckResult::critical($this->key(), 'Storage round-trip did not return the expected value.');
        }

        return CheckResult::healthy($this->key(), 'Default filesystem disk reachable.', (int) round((microtime(true) - $start) * 1000));
    }
}
