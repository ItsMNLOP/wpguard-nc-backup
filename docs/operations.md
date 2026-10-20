# Operations (MVP)

## Running a backup

Admin UI: Settings > Nextcloud Backup > "Back Up Now". Progress polls via
AJAX every 500ms; a WP-Cron fallback (`wpguard_nc_cron_advance`) keeps the
job moving even if the admin tab is closed (ADR 0003). Reloading or
reopening the settings page while a job is in progress resumes reporting
it automatically (fixed 2026-10-19 — previously the status box went blank
on reload with no way to tell a still-running backup from a stalled one
without checking Nextcloud or the DB directly).

CLI (used by the E2E harness, also usable by an admin with shell access):
```
wp wpguard-nc-backup run
wp wpguard-nc-backup status
```

## Statuses (NFR-01)

`queued` -> `running` -> (`uploaded-unverified` briefly, while `COMPLETE.json`
itself is being uploaded and verified) -> `verified`, or `failed` at any
point. There is no "success" status that skips verification — `verified`
is only reached in `step_finalize()` after `COMPLETE.json` round-trips.

## Finding a backup on Nextcloud

`/<configured-folder>/<site-id>/backups/<backup-id>/` — site-id is shown
nowhere in the UI yet in this MVP (gap — the admin page should display it;
tracked as a follow-up, not a release blocker since the manual restore
runbook's `curl`/WebDAV-client approach still works if you browse the
configured folder directly in Nextcloud's web UI).

## Logs

Per-job structured log (last 200 entries), redacted at write time
(`Logger::redact()`), visible in the admin page's log pane and via
`wp wpguard-nc-backup status`. No secrets should ever appear there — this
is tested (`tests/unit/LoggerRedactionTest.php`), not just asserted.

## Stale-lock recovery (JOB-05) — automatic, plus a manual override

JOB-01 requires preventing overlapping backups, implemented via
`Job_Store::has_running_job()` refusing a new job while one is
`queued`/`running`/`uploaded-unverified`. A job that is genuinely
interrupted (PHP killed, container restarted, browser tab closed with
WP-Cron also failing to pick it up — the real-world case this was first
found through, both during this build's own E2E testing and live on a
real site) would otherwise stay stuck forever and block every future
"Back Up Now".

**Automatic recovery (Phase 2, 2026-10-20)**: `Job_Orchestrator::reap_stale_lock()`
marks a job `failed` if its checkpoint hasn't been touched in over
`STALE_LOCK_SECONDS` (600s default, filterable via
`wpguard_nc_stale_lock_seconds`) — comfortably above the worst-case
single `advance()` call (an `upload_parts` retry loop can legitimately
run up to ~120s). It uses the same `try_lock()`/`release_lock()` pair as
`cancel()` (Vex audit H-01's primitive), so it can never race a
genuinely active process: if the lock is held, the job is left alone
regardless of how stale its timestamp looks. Called from
`create_job()` — so the fix applies the instant anyone next tries to
back up, no admin action and no real server-cron (JOB-02, separate
item) required — and from a recurring hourly WP-Cron event
(`wpguard_nc_stale_lock_check`) as a safety net for a site nobody
visits for a while. That recurring event is still WP-Cron pseudo-cron
under the hood (fires on the next traffic after it's due), so it
doesn't need real server-cron to exist, but inherits the same
"only fires on site traffic" limitation as everything else here until
JOB-02 lands. Regression test: `tests/e2e/run-e2e-stale-lock-recovery.sh`
(fresh job left alone; aged-past-threshold job reaped; a job whose lock
is genuinely held by a real concurrent process is correctly left alone
however stale its clock looks, then reaped once that lock frees up).

**Manual override, kept** (2026-10-19): the settings page also has a
**Cancel / Reset Stuck Job** button (`Job_Orchestrator::cancel()`),
capability/nonce-gated like every other admin action, for an admin who
doesn't want to wait out the staleness threshold, or wants to cancel a
job that's healthy but no longer wanted. Same cleanup path as any other
failure — nothing already uploaded to Nextcloud is touched. Regression
test: `tests/e2e/run-e2e-cancel-job.sh`.

**Related advisory (Vex re-audit, 2026-10-19, LOW-R2):** the H-01 fix
(`Job_Store::try_lock()`/`release_lock()`, a MySQL `GET_LOCK`/
`RELEASE_LOCK` pair keyed by job_id) is session-scoped — it's
automatically released if the PHP process's MySQL connection drops.
On a host configured with an aggressive `wait_timeout` (well under
MySQL's 8-hour default — seen on some tight shared-hosting plans),
WordPress's `$wpdb` can silently reconnect mid-request during a long
upload-with-retries (each `upload_parts` attempt can take up to the
30s request timeout, times up to 4 retries). A silent reconnect drops
the advisory lock on the old connection, and a second process could in
principle acquire it while the first is still mid-step. This is not a
new condition introduced by the H-01 fix — any session-scoped lock in
a PHP plugin has the same exposure — but it's worth knowing before
relying on this plugin under non-default MySQL timeout settings.

The cron fallback relies on WP-Cron, which only fires on a page load on a
low-traffic site. If no one visits the site and the admin closes the tab
mid-backup, the job can stall indefinitely in `running`. Real server-cron
or WP-CLI-driven scheduling is Phase 2 (JOB-02).

## Disk space

No preflight disk-space check ships in MVP (NFR-10 mentions it; deferred).
A failed export due to disk exhaustion surfaces as a `failed` job with a
redacted PHP warning in the log, not a clean "out of disk" message yet —
known gap, not a silent-success risk (the job still correctly fails
rather than reporting success).
