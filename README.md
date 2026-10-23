# WPThistle Backup and Restore

[![CI](https://github.com/WPThistle/wpthistle-backup-restore/actions/workflows/ci.yml/badge.svg)](https://github.com/WPThistle/wpthistle-backup-restore/actions/workflows/ci.yml)

A WordPress plugin that backs up your site's database and files to a
Nextcloud server you control, over authenticated HTTPS WebDAV — with
read-back SHA-256 verification on every component before anything is
ever reported as a successful backup.

**Status:** v1.2.0, Phase 2.5. Manual or scheduled backups, retention
pruning, incremental file backups, one-click in-place restore (from
either the configured Nextcloud destination or an uploaded backup
bundle), and cross-site restore with automatic URL migration. Every
feature has gone through independent security audit, fixes, and
re-verification before shipping — see `docs/threat-model.md` and
`CHANGELOG.md` for the full history. Multisite is not yet supported
(backlogged — see `STATUS.md`).

## Why this exists

Most WordPress-to-cloud backup plugins ask you to trust that a backup
succeeded. This plugin never reports success until it has independently
read every uploaded component back from Nextcloud and verified its
SHA-256 checksum against the one recorded at archive time, with a final
completion marker uploaded and verified last. See `docs/requirements.md`
for the full design rationale and `docs/threat-model.md` for the
security model.

## Features

- Manual or scheduled (daily/weekly) backup of the WordPress database
  and `wp-content` to a self-hosted or any Nextcloud instance you
  control, with retention pruning and opt-in incremental file backups.
- Authenticated HTTPS WebDAV transport — no plaintext credentials, no
  TLS verification bypass; supports a custom CA bundle for self-signed
  or private-CA Nextcloud deployments.
- Read-back checksum verification of every uploaded component before a
  backup is ever marked complete.
- Versioned manifest format (`docs/backup-format.md`) for forward
  compatibility.
- Non-blocking, resumable job runner — a backup (or restore)
  checkpoints its progress and can resume rather than restart after an
  interruption, including automatic stale-lock recovery and a WP-Cron
  fallback so a closed browser tab doesn't strand a job.
- One-click in-place restore, from either the configured Nextcloud
  destination or an uploaded backup bundle. Restoring a backup from a
  different site can migrate every URL it contains (including inside
  PHP-serialized data) to this site's own address, so the restored
  site actually works — see `docs/operations.md`.
- Self-hosted update feed via GitHub Releases — no wordpress.org
  listing required.
- Admin UI with live job status/log, plus a WP-CLI command
  (`wp wpthistle-backup-restore run` / `status`).

## Requirements

- WordPress 6.4+, PHP 8.1+.
- A Nextcloud server reachable over HTTPS with a valid certificate (or
  a private CA whose bundle you supply).
- A dedicated, non-admin Nextcloud account with an app password
  generated under Personal Settings > Security. Do not use your main
  Nextcloud account's password.

## Installation

1. Download the latest release ZIP, or build one yourself with
   `docker/scripts/package.ps1`.
2. Upload and activate the plugin in WordPress.
3. Go to **WPThistle > Backup and Restore**.
4. Enter your Nextcloud URL, the dedicated account's username, its app
   password, and a destination folder.
5. Click **Test Connection**, then **Back Up Now**.

Restoring is one-click from the same settings page, from either the
configured Nextcloud destination or an uploaded backup bundle — check
"this backup is from a different site" if the backup came from
somewhere other than this exact site/domain, which also migrates every
URL it contains to this site's own address. See
`plugin/wpthistle-backup-restore/readme.txt` for the WordPress.org-style
plugin readme, and `docs/restore-manual.md` for the manual (non-UI)
restore procedure, kept as a documented fallback.

## Repo layout

```
plugin/wpthistle-backup-restore/   The installable plugin (zip this directory's contents).
docs/                        ADRs, threat model, backup format, restore runbook, architecture.
tests/unit/                  Pure-PHP logic tests, no WordPress/Docker needed.
tests/e2e/                   Fixture seeding + full backup/restore drill scripts.
docker/                      Disposable local test stack (WordPress source + Nextcloud + clean restore target).
.github/workflows/ci.yml     CI definition.
```

## Local development

Requires Docker Desktop and PowerShell (Windows). All resources are
project-scoped, bound to `127.0.0.1`, and torn down by `cleanup.ps1` —
nothing here touches any real WordPress/Nextcloud instance.

```powershell
docker/scripts/bootstrap.ps1     # one-time: generates secrets+TLS CA, brings up the stack, seeds fixtures
docker/scripts/run-e2e.ps1       # runs a backup, verifies it, restores it to a clean instance, asserts content
docker/scripts/package.ps1       # builds the installable ZIP into dist/
docker/scripts/cleanup.ps1       # tears down only this project's containers/volumes/networks
```

Source site: `http://127.0.0.1:8081/wp-admin` · Restore target:
`http://127.0.0.1:8082/wp-admin` · Nextcloud: `https://127.0.0.1:8443`
(self-signed via a generated local CA at `docker/certs/wpguard-test-ca.crt`
— trusted by the test containers only, never added to the host's trust
store).

**Gotcha:** `wp-source` (8081) bind-mounts the `plugin/wpthistle-backup-restore/`
dev folder directly into its container. WP admin's "Delete" on that
install deletes the actual files on your host disk (recoverable via
`git checkout -- plugin/wpthistle-backup-restore/`), and uploading the packaged
ZIP there collides with the existing folder. To test the packaged ZIP's
real install/activate flow, use **8082** (`wp-restore`, no bind mount)
instead — that's also what `package.ps1`'s own smoke test does.

## Documentation map

- `docs/architecture.md` — module boundaries, request flow.
- `docs/adr/` — numbered architectural decision records.
- `docs/threat-model.md` — STRIDE-style threat model and residual risks.
- `docs/backup-format.md` — manifest schema, remote layout, compatibility policy.
- `docs/restore-manual.md` — the manual (non-UI) restore procedure, kept as a fallback.
- `docs/operations.md` — what each admin-facing setting/checkbox actually does, including restore migration semantics.
- `docs/maintenance.md`, `docs/test-matrix.md` — ongoing-support docs.
- `docs/releasing.md` — how a version is cut and published to GitHub Releases.

## Security

See `SECURITY.md` for the vulnerability-reporting policy and
`docs/threat-model.md` for the full threat model. This plugin has gone
through independent security review; see `CHANGELOG.md` for the fix
history.

## Contributing

See `CONTRIBUTING.md`.

## License

GPLv2 or later. See `LICENSE`.
