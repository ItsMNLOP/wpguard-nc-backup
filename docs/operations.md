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
`wpguard_nc_stale_lock_seconds`, floored at 60s — Vex delta audit
LOW-JOB05-3 — so a filtered value can't shrink below the one genuinely
lock-free moment in a job's life, between `create_job()` returning and
the first `advance()` call acquiring the lock). It uses the same
`try_lock()`/`release_lock()` pair as `cancel()` (Vex audit H-01's
primitive), so it can never race a genuinely active process: if the
lock is held, the job is left alone regardless of how stale its
timestamp looks. **The lock is the actual protection here, not the
600s margin** (Vex delta audit LOW-JOB05-2, 2026-10-20) — a single
`step_upload_parts()` iteration can legitimately run on the order of
1800s once every per-attempt timeout and retry in
`class-nextcloud-client.php` is counted (`put_bytes`: up to 120s x 4;
`propfind_size`: up to 30s x 4; `download_to_file`: up to 300s x 4),
far longer than 600s — but the lock stays held for that entire
duration, so `try_lock()` correctly fails and the job is never touched.
`STALE_LOCK_SECONDS` only governs how long a job with no active
process at all (lock already free) sits before being reaped. Called from
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

**Vex delta audit, 2026-10-20 (LOW-JOB05-1):** the automatic stale-lock
reaper (above) widens this same condition's practical surface — the
hourly safety-net cron now provides a second actor that could acquire
a dropped lock, not only a second concurrent `advance()` call. The
outcome stays bounded exactly as before: the job gets marked `failed`,
never incorrectly `verified` — `advance()`'s terminal-state check
means the original in-flight call can't continue past a job that's
already been marked `failed` out from under it. Still a LOW, still
requires non-default MySQL `wait_timeout` configuration.

The cron fallback relies on WP-Cron, which only fires on a page load on a
low-traffic site. If no one visits the site and the admin closes the tab
mid-backup, the job can stall indefinitely in `running`. Real server-cron
or WP-CLI-driven scheduling is Phase 2 (JOB-02).

## Automatic scheduling (Phase 2, 2026-10-20)

Settings page has an "Automatic backup schedule" dropdown: Disabled
(default), Daily, Weekly. Backed by a real recurring WP-Cron event
(`wpguard_nc_scheduled_backup`) reconciled against the stored setting
by `sync_scheduled_backup()` (`wpguard-nc-backup.php`) — called on every
boot and immediately after a settings save, so a schedule change takes
effect without waiting for the next page load. 'Weekly' is a custom
recurrence registered via the `cron_schedules` filter (WP core only
ships hourly/twicedaily/daily).

The handler, `run_scheduled_backup()`, reuses `Job_Orchestrator::create_job()`
as-is — including its JOB-05 stale-lock reap — so a scheduled run
automatically clears out a genuinely abandoned previous job before
starting, and correctly skips (no-op) rather than overlapping if a
healthy backup is still legitimately in progress. Same WP-Cron
pseudo-cron limitation as everything else here (only fires on site
traffic) until JOB-02 (real server-cron) lands. Default is Disabled
specifically so activating or updating the plugin never silently starts
recurring jobs an admin didn't ask for. Regression test:
`tests/e2e/run-e2e-scheduling.sh`.

## Retention (Phase 2, 2026-10-20)

Settings page has a "Keep last N backups" number field, default **0 =
keep every backup forever**. A positive value deletes the oldest
backup folder(s) under `<site-id>/backups/` on Nextcloud once more
than that many exist, right after each new backup verifies
successfully (`Job_Orchestrator::maybe_prune_old_backups()`, called
from `step_finalize()` — only ever after the job above it is already
`verified`). Deletion is oldest-first by `backup_id` (timestamp-
prefixed, so lexicographic order is chronological order); the
just-finished backup is always the newest and therefore always
survives, by construction. A pruning failure is logged as a warning
but never flips a verified backup back to failed — same
never-affects-job-status discipline as the diagnostic job-log upload.
Default is 0 specifically so no plugin update or activation can ever
silently start deleting existing backups. Regression test:
`tests/e2e/run-e2e-retention.sh` (against a real, isolated Nextcloud
test folder — proves `list_children()` and `Retention::prune()` both
against the real server, not a mock, including that the real remote
listing after pruning matches what was asked for).

## Disk space

No preflight disk-space check ships in MVP (NFR-10 mentions it; deferred).
A failed export due to disk exhaustion surfaces as a `failed` job with a
redacted PHP warning in the log, not a clean "out of disk" message yet —
known gap, not a silent-success risk (the job still correctly fails
rather than reporting success).
