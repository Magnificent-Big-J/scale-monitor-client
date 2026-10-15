<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scale Monitor integration
    |--------------------------------------------------------------------------
    |
    | Extracted from SHC's own hand-written implementation (Shc repo, SM-306)
    | once it was proven working in production, per Planning/14-phased-
    | backlog.md's own sequencing (05-api-and-telemetry-contract.md §6).
    |
    | Defaults are deliberately all "off"/empty — shadow mode. Nothing about
    | the host app's behaviour changes until an operator explicitly sets
    | SCALE_MONITOR_ENABLED=true and issues a real TelemetryClient credential
    | for that environment.
    |
    */

    // Master switch. When true: outbound heartbeat/error/failed-job/
    // deployment/metric/custom-event telemetry starts flowing, and the deep-
    // health endpoint starts serving real data (it 404s "not configured"
    // regardless of this flag — a signature can't be verified with no secret
    // either way).
    'enabled' => (bool) env('SCALE_MONITOR_ENABLED', false),

    'base_url' => env('SCALE_MONITOR_BASE_URL', ''),

    // Issued by Scale Monitor's TelemetryClient lifecycle API for this
    // specific environment; empty means "not yet configured", not "use a
    // default".
    'key_id' => env('SCALE_MONITOR_KEY_ID', ''),
    'secret' => env('SCALE_MONITOR_SECRET', ''),

    // How this environment identifies itself in outbound telemetry bodies —
    // purely descriptive, Scale Monitor already knows which environment a
    // key_id belongs to.
    'application' => env('SCALE_MONITOR_APPLICATION', ''),
    'environment' => env('SCALE_MONITOR_ENVIRONMENT', env('APP_ENV', 'production')),

    'timeouts' => [
        'connect' => 2,
        'total' => 3,
    ],

    // Exponential backoff, then drop — never a caller-blocking retry loop.
    // Applied by the queued job, not at dispatch time, so a Scale Monitor
    // outage never blocks the caller.
    'retries' => 2,

    'queue' => env('SCALE_MONITOR_QUEUE', 'default'),

    // Read-only runtime/queue evidence accompanies scheduler heartbeats.
    'runtime_metadata' => (bool) env('SCALE_MONITOR_RUNTIME_METADATA', true),

    // A local callback runs with the real schedule, independently of telemetry
    // workers/delivery. No manual heartbeat command refreshes this evidence.
    // Use a persistent cache shared by cron and the signed health endpoint.
    // Signed GET /api/monitoring/v1/dependencies, which lets Scale Monitor check
    // this deployment's composer.lock/package-lock.json against published
    // security advisories. Returns only package names, versions and lock
    // digests, and only to a request signed with this environment's secret.
    'dependencies' => [
        'route' => (bool) env('SCALE_MONITOR_DEPENDENCIES_ROUTE', true),
    ],

    'scheduler' => [
        'measure' => (bool) env('SCALE_MONITOR_MEASURE_SCHEDULER', true),
    ],

    // Explicitly list only disks actually used. Provider values: local,
    // amazon_s3, supabase, digitalocean_spaces, s3_compatible, unknown, auto.
    // Example: ['s3' => 'supabase', 'public' => 'local'].
    // A named default disk replaces the generic default check; other defaults
    // remain checked. Empty configuration retains the legacy storage check.
    'storage' => [
        'disks' => [],
    ],

    // Keys stripped (case-insensitively, at any depth) from every outbound
    // context/message payload before it ever leaves this app — a defence-in-
    // depth backstop, not a replacement for not passing secrets in the first
    // place. Scale Monitor's own ingestion applies its own Redactor too; this
    // one runs first, client-side.
    'redaction' => [
        'password', 'secret', 'token', 'api_key', 'apikey', 'access_token',
        'refresh_token', 'authorization', 'cookie', 'session', 'csrf',
        'credit_card', 'card_number', 'cvv',
    ],

    // Automatic exception reporting via the ReportsToScaleMonitor exception-
    // handler hook (opt-in — the host app's handler calls it explicitly).
    'report_exceptions' => [
        // Only exceptions at or above this severity are ever sent.
        // One of: low, warning, high, critical.
        'min_severity' => env('SCALE_MONITOR_MIN_SEVERITY', 'high'),
        // 1.0 = every matching exception; 0.1 = roughly 1 in 10. Applied
        // after min_severity, so critical errors can still be sampled down
        // on a noisy endpoint without losing the severity gate.
        'sampling' => (float) env('SCALE_MONITOR_SAMPLING', 1.0),
    ],

    // scale-monitor:heartbeat {key} is meant to run on a schedule
    // (Schedule::command("scale-monitor:heartbeat scheduler")->everyMinute()
    // in the host app's own routes/console.php — this package doesn't
    // register the schedule itself, since the cadence and key naming are the
    // host app's own decision). `queues` is an optional list of queue names
    // this app wants a per-queue heartbeat key expected for (purely
    // descriptive metadata passed in the heartbeat body; Scale Monitor's own
    // HeartbeatExpectation records are configured on its side).
    'heartbeats' => [
        'queues' => [],
    ],

];
