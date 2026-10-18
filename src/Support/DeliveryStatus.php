<?php

namespace Rainwaves\ScaleMonitorClient\Support;

use Throwable;

/** Best-effort, bounded local evidence; never stores bodies, URLs or exceptions. */
class DeliveryStatus
{
    public function record(string $outcome, string $category, string $endpoint, ?string $eventId = null, int $attempt = 0, ?int $httpStatus = null): void
    {
        $file = null;
        try {
            $path = config('scale-monitor.delivery.state_path', storage_path('app/scale-monitor/delivery.json'));
            if (! is_string($path) || $path === '') {
                return;
            }
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                return;
            }
            $file = @fopen($path, 'c+');
            if ($file === false || ! flock($file, LOCK_EX | LOCK_NB)) {
                return;
            }
            @chmod($path, 0600);
            $state = json_decode(stream_get_contents($file, 8192), true) ?: [];
            $now = now()->utc()->toIso8601String();
            $state['counts'][$outcome] = min(PHP_INT_MAX - 1, (int) ($state['counts'][$outcome] ?? 0)) + 1;
            $state['last'] = ['outcome' => $outcome, 'category' => $category, 'endpoint' => $endpoint,
                'event_reference' => $eventId === null ? null : hash('sha256', $eventId), 'attempt' => $attempt, 'http_status' => $httpStatus, 'at' => $now];
            if ($outcome === 'accepted') {
                $state['last_success_at'] = $now;
            }
            if (in_array($outcome, ['rejected', 'exhausted', 'dispatch_failed'], true)) {
                $state['last_rejection_category'] = $category;
            }
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($file);
        } catch (Throwable) {
            // Observation must never become another failure in the host app.
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    public function read(): array
    {
        try {
            $path = config('scale-monitor.delivery.state_path', storage_path('app/scale-monitor/delivery.json'));
            if (! is_string($path) || $path === '') {
                return ['status' => 'disabled'];
            }
            if (! is_file($path)) {
                return ['status' => file_exists($path) ? 'unavailable' : 'not_recorded'];
            }
            $file = @fopen($path, 'r');
            if ($file === false) {
                return ['status' => 'unavailable'];
            }
            try {
                if (! flock($file, LOCK_SH | LOCK_NB)) {
                    return ['status' => 'unavailable'];
                }
                $state = json_decode(stream_get_contents($file, 8192), true, flags: JSON_THROW_ON_ERROR);

                return is_array($state) && isset($state['last']) ? ['status' => 'recorded', ...$state] : ['status' => 'unavailable'];
            } finally {
                fclose($file);
            }
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
