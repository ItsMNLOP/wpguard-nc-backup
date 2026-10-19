# Operations (MVP)

## Running a backup

Admin UI: Settings > Nextcloud Backup > "Back Up Now". Progress polls via
AJAX every 500ms; a WP-Cron fallback (`wpguard_nc_cron_advance`) keeps the
job moving even if the admin tab is closed (ADR 0003).

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

## WP-Cron limitation (disclosed, not fixed in MVP)

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
