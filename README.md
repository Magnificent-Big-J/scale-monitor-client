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
Route::get('/monitoring/v1/health', \Rainwaves\ScaleMonitorClient\Http\Controllers\HealthController::class)
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

## Dependency vulnerability checks (automatic)

From v1.4.0 the package registers a signed `GET /api/monitoring/v1/dependencies` route. Scale
Monitor pulls it daily, signed with this environment's credential, and checks the deployed
`composer.lock` / `package-lock.json` versions against Packagist and npm security advisories.
It only ever returns package names, versions, dev flags and lock-file digests. Nothing to
configure; set `SCALE_MONITOR_DEPENDENCIES_ROUTE=false` to turn it off.

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

### Queue delays and recovery

Version 1.1.1 captures each event’s UTC occurrence time before it enters the queue.
A queued heartbeat describes when the scheduler ran; delivering it later does not
provide fresh evidence of health. Old serialized jobs use the queue envelope’s
`createdAt` where available. The failed-job listener ignores this package’s transport
jobs to avoid recursive reporting. Ensure a worker consumes `SCALE_MONITOR_QUEUE`
and point deployment scheduler and worker commands at the stable `current` path.

Version 1.1.2 aligns metric/custom-event wire payloads with the receiver. Metric
values of zero are retained, and default observation time is captured before
queueing. Configure metric keys and thresholds in Scale Monitor; unknown/disabled
keys are acknowledged and dropped by the server. Legacy `low` severity maps to
`info`. `event()` accepts an optional `message` after its existing arguments.

Version 1.2.0 adds a read-only `runtime` snapshot to `scheduler` heartbeat metadata.
It reports software versions, a deployment SHA when the release folder identifies
it, the configured telemetry queue’s backlog, and Horizon’s reported/observed
worker counts. It neither consumes jobs nor starts a scheduler. Counts that cannot
be verified stay unknown. Set `SCALE_MONITOR_RUNTIME_METADATA=false` to disable it;
if adding the snapshot would exceed the 1 KB heartbeat limit, existing metadata is
preserved and the optional snapshot is omitted.

Version 1.2.1 corrects scheduler freshness calculations for signed Carbon date
differences. Old heartbeat evidence becomes critical; malformed or excessively
future timestamps report degraded, and missing evidence stays skipped. PHP and
Laravel support requirements are unchanged. The route above belongs in Laravel's
`routes/api.php`, which already adds the `/api` prefix.

### Local scheduler measurement and named storage checks (1.3.0)

When monitoring is enabled, the package adds a local callback to Laravel's real
schedule every minute. It stores `scale-monitor:scheduler:last-run` indefinitely,
so a stopped cron retains stale evidence (critical after 180 seconds). Cron and
the health endpoint must share a persistent cache. Missing evidence stays skipped;
invalid/future evidence is degraded, and cache-read failure is critical. Local
measurement is independent of heartbeat queue/delivery, and running the heartbeat
command manually does not refresh it. The existing host heartbeat schedule still
sends telemetry; this callback sends nothing. Set
`SCALE_MONITOR_MEASURE_SCHEDULER=false` to opt out. Shadow mode adds no schedule.
This measures callback execution, not successful completion of business jobs.

In the host's `config/scale-monitor.php`, explicitly select disks actually used:

```php
'storage' => [
    'disks' => ['s3' => 'supabase', 'public' => 'local'],
],
```

Supported provider labels are `local`, `amazon_s3`, `supabase`,
`digitalocean_spaces`, `s3_compatible`, `unknown` and `auto`. Auto labels local
drivers and recognized provider endpoint domains. When an S3 endpoint is absent,
auto inspects the endpoint resolved by Laravel's S3 client, including SDK-level
endpoint overrides. Standard AWS configuration therefore identifies Amazon S3
without requiring an explicit endpoint. This reads client configuration without
making a storage request. Unrecognized endpoints are S3 compatible; failed or
unavailable endpoint resolution remains unknown. Disk names must contain
1–24 letters, digits, underscores or hyphens. Keys such as `storage_supabase_s3`
identify both provider and disk without exposing bucket names or endpoints.
Do not label every S3 driver as Amazon: confirm the actual provider first.
A named default replaces the generic `storage` check; an unlisted default keeps
its legacy check. Other configured disks are never automatically probed.

Each selected writable local/S3 disk gets a unique temporary object beneath
`.scale-monitor/probes/`, followed by read verification and deletion, including
cleanup after exceptions. Grant write/read/delete only in this reserved prefix;
no customer objects are read or deleted. S3 requests use a separate adapter,
2-second connect and 3-second request timeouts, with retries disabled. This bounds
each request, not the whole three-operation probe. Missing buckets/disks,
unsupported drivers and explicitly read-only disks remain skipped. Write, read,
connection and cleanup failures are critical until a subsequent probe succeeds.
The host's disk adapter/configuration is unchanged. These probes verify storage
access, not provider-wide AWS/CloudWatch or Supabase service coverage.
