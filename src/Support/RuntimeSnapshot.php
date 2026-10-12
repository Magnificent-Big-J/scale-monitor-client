<?php

namespace Rainwaves\ScaleMonitorClient\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Queue;
use Throwable;

/** A small, read-only snapshot. Never includes credentials, paths, process arguments or user data. */
class RuntimeSnapshot
{
    public const string CLIENT_VERSION = '1.3.0';

    public function collect(): array
    {
        $connection = (string) config('queue.default');
        $queue = (string) config('scale-monitor.queue', 'default');
        $release = basename(realpath(base_path()) ?: base_path());
        $snapshot = [
            'client_version' => self::CLIENT_VERSION,
            'php_version' => PHP_VERSION,
            'framework_version' => app()->version(),
            'observed_at' => now()->utc()->toIso8601String(),
            'queue_connection' => mb_substr($connection, 0, 32),
            'queue_driver' => mb_substr((string) config('queue.connections.'.$connection.'.driver', $connection), 0, 32),
            'telemetry_queue' => mb_substr($queue, 0, 64),
        ];
        if (preg_match('/^[a-f0-9]{40}$/i', $release)) {
            $snapshot['release'] = $release;
        }
        try {
            $version = InstalledVersions::getPrettyVersion('rainwaves/scale-monitor-client');
            if ($version !== null && preg_match('/^v?\d+\.\d+\.\d+$/', $version)) {
                $snapshot['client_version'] = ltrim($version, 'v');
            }
        } catch (Throwable) {
            // Manual/path installs still have the release constant.
        }
        try {
            $snapshot['queue_backlog'] = Queue::connection($connection)->size($queue);
        } catch (Throwable) {
            $snapshot['queue_backlog'] = null;
        }
        $contract = 'Laravel\\Horizon\\Contracts\\MasterSupervisorRepository';
        if (! app()->bound($contract)) {
            $snapshot['horizon_status'] = 'not_installed';

            return $snapshot;
        }
        try {
            $masters = app($contract)->all();
            $statuses = array_column($masters, 'status');
            $snapshot['horizon_status'] = in_array('running', $statuses, true) ? 'running' : ($masters === [] ? 'inactive' : 'paused');
            $supervisors = app('Laravel\\Horizon\\Contracts\\SupervisorRepository')->all();
            $snapshot['horizon_reported_workers'] = array_sum(array_map(fn ($supervisor) => array_sum($supervisor->processes ?? []), $supervisors));
            $snapshot['horizon_live_workers'] = $this->liveWorkers(base_path());
        } catch (Throwable) {
            $snapshot['horizon_status'] = 'unavailable';
        }

        return $snapshot;
    }

    /** Verify actual worker children; Horizon's reported count can survive a crash loop. */
    public function liveWorkers(string $basePath, string $processRoot = '/proc'): ?int
    {
        if (! is_dir($basePath) || ! is_dir($processRoot) || ! is_readable($processRoot)) {
            return null;
        }
        $count = 0;
        $unreadable = false;
        set_error_handler(static fn () => true);
        try {
            foreach (glob($processRoot.'/[0-9]*/cmdline') ?: [] as $file) {
                $command = file_get_contents($file);
                $arguments = $command === false ? [] : explode("\0", $command);
                $worker = false;
                foreach ($arguments as $index => $argument) {
                    if (basename($argument) === 'artisan' && ($arguments[$index + 1] ?? '') === 'horizon:work') {
                        $worker = true;
                        break;
                    }
                }
                if (! $worker) {
                    continue;
                }
                $cwd = readlink(dirname($file).'/cwd');
                if ($cwd === false) {
                    $unreadable = true;
                }
                if ($cwd !== false && realpath($cwd) === realpath($basePath)) {
                    $count++;
                }
            }
        } finally {
            restore_error_handler();
        }

        return $count === 0 && $unreadable ? null : $count;
    }
}
