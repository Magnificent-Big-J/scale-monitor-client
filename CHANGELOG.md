# Changelog

## v1.3.1 — 2026-10-15

- Identify S3 providers from the endpoint resolved by Laravel's AWS client when no disk endpoint is explicitly configured. Default AWS endpoints identify Amazon S3; recognized SDK endpoint overrides preserve Supabase and DigitalOcean Spaces identification.
- Keep unrecognized endpoints and unsupported adapters unverified. Public URLs, disk names and credential variable names do not establish provider identity.
- Endpoint inspection performs no storage request and does not resolve credentials. Measured write/read/delete health remains independent of provider identification; consumer requirements and telemetry contracts are unchanged.

## v1.3.0 — 2026-10-12

- Register an enabled-only local scheduler callback every minute. Its persistent UTC marker records actual schedule execution independently of queued telemetry; manual heartbeats do not refresh it. Stopped cron remains critical until real execution recovers. Legacy markers remain supported; missing, invalid, future and cache-failure evidence retain their distinct states.
- Add explicitly named storage disk checks labelled by provider (local, Amazon S3, Supabase or DigitalOcean Spaces). Only configured, selected disks are probed; a named default replaces the generic check.
- Probe reserved unique objects with verified write/read/delete results, cleanup after failures, latency, isolated S3 connect/request timeouts and disabled transport retries. Missing, unsupported and read-only disks stay unmeasured; failed cleanup is critical.
- Keep telemetry contracts and PHP/Laravel requirements unchanged.

## v1.2.1 — 2026-10-11

- Correct scheduler heartbeat age when Carbon returns signed date differences: stale evidence now reports critical instead of a negative healthy age.
- Treat malformed or excessively future heartbeat timestamps as unverified/degraded; missing evidence remains skipped.
- Correct the Laravel API route example to account for its existing `/api` prefix.
- PHP/Laravel compatibility requirements and the telemetry contract are unchanged.

## v1.2.0 — 2026-10-08

- Attach bounded, read-only runtime evidence to scheduler heartbeats: client/PHP/framework versions, release SHA when available, telemetry queue backlog and Horizon status.
- Distinguish Horizon's reported worker count from actual worker children observed in the application process directory. Unverifiable counts stay unknown.
- Never report credentials, host paths, process arguments or user data. Collection failures preserve heartbeat delivery; existing large metadata is retained without exceeding the receiver's limit.
- Optional `SCALE_MONITOR_RUNTIME_METADATA=false` keeps the original metadata behavior. No new schedules or business jobs are installed.

## v1.1.2 — 2026-10-08

- Send metric samples with their original observation time and preserve zero values.
- Send custom-event kind/title/message fields required by the server; existing event calls remain supported, with an optional message argument.
- Map legacy low severity to the server's info severity, and preserve string-zero messages/codes.
- Configure metric thresholds in Scale Monitor; client-supplied threshold fields do not create or alter server definitions.

## v1.1.1 — 2026-10-08

- Capture UTC occurrence time and event ID before queueing, preserving both through serialization and retries. Delayed heartbeats now retain their original time.
- Use the queue envelope creation time for older serialized jobs when available.
- Ignore failed Scale Monitor transport jobs in the failed-job listener to prevent recursive telemetry. Business job failures continue to report.
- Verified with 45 tests and 76 assertions.

## v1.1.0 — 2026-09-28

Added `HealthCheckRegistry::registerCheckGroup()` — found while switching SHC over from its own
hand-written implementation to this package: SHC's deep-health payload comes from one existing
scheduled command's cached results, a variable, not-known-ahead-of-time number of checks from one
source. `registerCheck()` can't express that (one key maps to exactly one result, always). A
group's runner returns `list<CheckResult>`; each one still appears individually in the payload,
exactly as if registered on its own, and a broken group reports one critical entry instead of
crashing the whole response (same safety net `registerCheck()`'s closures already had).

## v1.0.0 — 2026-09-28

Initial release. Extracted from Smart Helpers Center's own hand-written Scale Monitor integration
(`Shc` repo, SM-306) once it was proven working end to end in production — see that repo's own
CLAUDE.md "Scale Monitor integration (SM-306)" section for the acceptance history this package
inherits.

- `ScaleMonitor::heartbeat()/error()/failedJob()/metric()/event()/deployment()` — the six
  telemetry endpoints, all fail-open.
- `VerifyScaleMonitorSignature` middleware + `HealthController` + `HealthCheckRegistry` for the
  deep-health pull (full integration mode), with seven built-in checks.
- `ReportsToScaleMonitor` exception-handler trait (min-severity + sampling gates).
- `ReportFailedJobToScaleMonitor` listener.
- `scale-monitor:ping`, `scale-monitor:heartbeat`, `scale-monitor:deployment` artisan commands.
