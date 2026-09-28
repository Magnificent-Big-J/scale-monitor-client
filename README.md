# rainwaves/scale-monitor-client

Laravel client for [Scale Monitor](https://monitor.codescaletech.co.za), Code Scale Tech's central
operational monitoring platform. Extracted from Smart Helpers Center's own hand-written integration
(`Shc` repo, SM-306) once it was proven working in production, per
`Planning/14-phased-backlog.md`'s own sequencing.

Ships **off by default** (shadow mode) — installing it changes nothing about your app's behaviour
until you set `SCALE_MONITOR_ENABLED=true` and configure a real `TelemetryClient` credential.
Every call this package makes is fail-open: a misconfigured or unreachable Scale Monitor can never
break the app it's installed in.

## Install

```bash
composer require rainwaves/scale-monitor-client
php artisan vendor:publish --tag=scale-monitor-config
```

```env
SCALE_MONITOR_ENABLED=false
SCALE_MONITOR_BASE_URL=
SCALE_MONITOR_KEY_ID=
SCALE_MONITOR_SECRET=
SCALE_MONITOR_APPLICATION=your-app-slug
```

`key_id`/`secret` come from Scale Monitor's own Telemetry Credentials UI (Application → Environment
→ Telemetry Credentials → New credential). See Scale Monitor's own
[`docs/integration-guide.md`](https://github.com/Magnificent-Big-J/scale-monitor/blob/main/docs/integration-guide.md)
Part 2 for the full walkthrough.

## Sending telemetry

```php
use Rainwaves\ScaleMonitorClient\ScaleMonitor;

app(ScaleMonitor::class)->heartbeat('scheduler');
app(ScaleMonitor::class)->error('high', 'RuntimeException', 'Payment gateway timed out.');
app(ScaleMonitor::class)->metric('queue_depth', ['value' => 42]);
app(ScaleMonitor::class)->event('user_registered', fingerprint: 'signup-flow');
app(ScaleMonitor::class)->deployment(release: 'v1.4.0', commitSha: $sha, status: 'succeeded');
```

A heartbeat on a schedule:

```php
// routes/console.php
Schedule::command('scale-monitor:heartbeat scheduler')->everyMinute();
```

A failed-job listener (not auto-discovered — register it yourself):

```php
// AppServiceProvider::boot()
Event::listen(JobFailed::class, \Rainwaves\ScaleMonitorClient\Listeners\ReportFailedJobToScaleMonitor::class);
```

An exception-handler hook:

```php
// app/Exceptions/Handler.php (or wherever your Handler lives)
use Rainwaves\ScaleMonitorClient\Exceptions\ReportsToScaleMonitor;

class Handler
{
    use ReportsToScaleMonitor;
}

// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(fn (Throwable $e) =>
        app(Handler::class)->reportExceptionToScaleMonitor($e, severity: 'high'));
})
```

A deploy pipeline marker:

```bash
php artisan scale-monitor:deployment --release="$GITHUB_SHA" --commit="$GITHUB_SHA" --status=succeeded || true
```

A one-off sanity check while setting things up:

```bash
php artisan scale-monitor:ping
```

## Serving the deep-health pull (full integration mode only)

```php
// routes/api.php
Route::get('/api/monitoring/v1/health', \Rainwaves\ScaleMonitorClient\Http\Controllers\HealthController::class)
    ->middleware('scale-monitor.signature');
```

Seven built-in checks (database, cache, queue, storage, scheduler, mail config, required env) run
automatically. Add your own — e.g. a third-party API canary derived from your app's own existing
state rather than a live, possibly chargeable call:

```php
use Rainwaves\ScaleMonitorClient\Support\{HealthCheckRegistry, CheckResult};

app(HealthCheckRegistry::class)->registerCheck('google_maps', function () {
    $incident = OperationalIncident::where('service', 'google-maps')->where('status', 'open')->first();

    return $incident === null
        ? CheckResult::healthy('google_maps')
        : CheckResult::critical('google_maps', $incident->message, errorCode: 'BILLING_DISABLED');
});
```

## Configuration reference

See [`config/scale-monitor.php`](config/scale-monitor.php) — every key is documented inline:
`enabled`, `base_url`, `key_id`/`secret`, `application`/`environment`, `timeouts`, `retries`,
`queue`, `redaction` (keys stripped from outbound payloads at any depth), `report_exceptions`
(`min_severity`, `sampling`), `heartbeats.queues`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Design principles

- **Fail open, always.** Every public method is wrapped; nothing this package does can throw into
  a host app's request/job/command. See `tests/Feature/FailOpenTest.php`.
- **Shadow mode by default.** Every config default is off/empty — installing this package is a
  no-op until an operator explicitly configures it.
- **No auto-discovery magic beyond what Laravel already does.** The failed-job listener and the
  exception-handler hook are registered explicitly by the host app, not silently wired up — this
  mirrors a real, previously-found gap: Laravel's own event auto-discovery only scans an app's own
  listener directories, never a package's.
