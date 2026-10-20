# Changelog

All notable changes to this project are documented here.

## [Unreleased] — Phase 2, in progress

### Added
- Automatic stale-lock recovery (JOB-05): a genuinely abandoned backup
  job is now marked `failed` on its own once its checkpoint has gone
  untouched past a threshold (600s default, filterable via
  `wpguard_nc_stale_lock_seconds`), instead of requiring an admin to
  notice and click "Cancel / Reset Stuck Job" (which remains available
  as an immediate manual override). Applied the moment anyone next
  tries to back up, plus a recurring hourly WP-Cron safety net for a
  site nobody visits for a while. Never races a job whose advisory lock
  is genuinely still held by a live process. Pending Vex security
  sign-off before this is considered shippable.

## [1.0.0] — 2026-10-20 (Phase 1 MVP complete)

Two full independent security audits (SOP-023), both **CLEAR TO SHIP**,
zero open HIGH/MEDIUM findings. Verified against real production
WordPress/Nextcloud data (not just synthetic fixtures), including a full
real restore with the owner's own account and 2FA state confirmed intact.

### Fixed
- H-01: concurrent AJAX+WP-Cron job-advance race could mark a
  structurally corrupt backup `verified` — MySQL advisory lock around
  `Job_Orchestrator::advance()`.
- H-02: the constant-override credential path backed up `wp-config.php`
  containing the very credential protecting the backup — redacted before
  archiving.
- M-01/L-01 through L-04: WebDAV redirect following, failure-path temp
  cleanup, streaming archive-bomb abort, XML entity loading, CA-bundle
  size cap.
- ZIP packaging used backslash path separators (a PowerShell
  `Compress-Archive` artifact), which real PHP `ZipArchive` extraction on
  a live host takes literally instead of as a directory separator —
  caused "Plugin file does not exist." on activation on a real site.
- `Archive_Writer` reopened and rewrote the ZIP's entire central
  directory on every single file — the dominant cost of a real ~17-minute
  backup for only ~50MB of content (8,559 files). Now opens once per
  batch.
- Admin settings page showed a blank status box after navigating away
  and back during a running job, indistinguishable from a crashed
  backup.

### Added
- Time-boxed batching for `export_db`/`export_files` — cuts AJAX round
  trips dramatically on file-heavy sites.
- Manual "Cancel / Reset Stuck Job" admin action (JOB-05 partial
  mitigation) for a job the WP-Cron fallback never picks back up.
- Diagnostic `job-log.json` uploaded alongside every backup with the
  full per-step timestamped log.
- Skip DEFLATE for already-incompressible file extensions
  (jpg/png/zip/pdf/mp4/woff2/etc.) in the file archive.

### Known limitations (Phase 2, by design)
- No real server-cron trigger — relies on WP's pseudo-cron, which only
  fires on site traffic.
- No automatic lock-leasing/expiry (JOB-05 proper) — the Cancel action is
  a manual stopgap.
- No incremental/delta backups — every run re-archives every file,
  identified as the primary remaining performance lever.
- No scheduling, retention, one-click restore, multisite, or a
  self-hosted update feed.
- CI workflow written, never executed (no runner connected).

## [0.1.0] — 2026-10-19 (MVP)

### Added
- Manual, on-demand WordPress-to-Nextcloud backup (database + wp-content
  + wp-config.php) over authenticated HTTPS WebDAV.
- Versioned manifest (`format_version: 1`) with per-component SHA-256
  hashes; read-back verification after every upload; `COMPLETE.json`
  completion marker written and verified last.
- Admin settings page: connection fields, Test Connection, Back Up Now,
  live job status/log.
- WP-CLI command `wp wpguard-nc-backup run` / `status`.
- Manual disaster-recovery runbook (`docs/restore-manual.md`).
- Local disposable Docker test stack (WordPress source + Nextcloud +
  clean restore target), TLS via a generated local CA.

### Known limitations (see docs/ for detail)
- Manual backups only — no scheduling, retention, or one-click restore
  (Phase 2+).
- No Nextcloud chunked-upload v2 — upload parts are bounded (default
  64 MiB) but read fully into memory per part.
- No at-rest archive encryption.
- Not yet independently security-audited (Vex / SOP-023 pending).
