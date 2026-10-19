# Changelog

All notable changes to this project are documented here.

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
