# Changelog

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
