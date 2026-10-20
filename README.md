# WPGuard Nextcloud Backup

A WordPress plugin that backs up your site's database and files to a
Nextcloud server you control, over authenticated HTTPS WebDAV — with
read-back SHA-256 verification on every component before anything is
ever reported as a successful backup.

**Status:** v1.0.0, Phase 1 (MVP). Manual, on-demand backups and a
manual restore procedure. Independently security-reviewed (see
`docs/threat-model.md`). Scheduling, retention, one-click restore, and
multisite are Phase 2 — see `CHANGELOG.md` for the full roadmap.

## Why this exists

Most WordPress-to-cloud backup plugins ask you to trust that a backup
succeeded. This plugin never reports success until it has independently
read every uploaded component back from Nextcloud and verified its
SHA-256 checksum against the one recorded at archive time, with a final
completion marker uploaded and verified last. See `docs/requirements.md`
for the full design rationale and `docs/threat-model.md` for the
security model.

## Features

- Manual, on-demand backup of the WordPress database and `wp-content`
  to a self-hosted or any Nextcloud instance you control.
- Authenticated HTTPS WebDAV transport — no plaintext credentials, no
  TLS verification bypass; supports a custom CA bundle for self-signed
  or private-CA Nextcloud deployments.
- Read-back checksum verification of every uploaded component before a
  backup is ever marked complete.
- Versioned manifest format (`docs/backup-format.md`) for forward
  compatibility.
- Non-blocking, resumable job runner — a backup checkpoints its
  progress and can resume rather than restart after an interruption.
- Admin UI with live job status/log, plus a WP-CLI command
  (`wp wpguard-nc-backup run` / `status`).
- Documented, tested restore procedure (`docs/restore-manual.md`).

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
3. Go to **Settings > Nextcloud Backup**.
4. Enter your Nextcloud URL, the dedicated account's username, its app
   password, and a destination folder.
5. Click **Test Connection**, then **Back Up Now**.

See `plugin/wpguard-nc-backup/readme.txt` for the WordPress.org-style
plugin readme, and `docs/restore-manual.md` for how to restore a backup.

## Repo layout

```
plugin/wpguard-nc-backup/   The installable plugin (zip this directory's contents).
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

**Gotcha:** `wp-source` (8081) bind-mounts the `plugin/wpguard-nc-backup/`
dev folder directly into its container. WP admin's "Delete" on that
install deletes the actual files on your host disk (recoverable via
`git checkout -- plugin/wpguard-nc-backup/`), and uploading the packaged
ZIP there collides with the existing folder. To test the packaged ZIP's
real install/activate flow, use **8082** (`wp-restore`, no bind mount)
instead — that's also what `package.ps1`'s own smoke test does.

## Documentation map

- `docs/architecture.md` — module boundaries, request flow.
- `docs/adr/` — numbered architectural decision records.
- `docs/threat-model.md` — STRIDE-style threat model and residual risks.
- `docs/backup-format.md` — manifest schema, remote layout, compatibility policy.
- `docs/restore-manual.md` — the current (manual) restore procedure.
- `docs/maintenance.md`, `docs/operations.md`, `docs/test-matrix.md` — ongoing-support docs.

## Security

See `SECURITY.md` for the vulnerability-reporting policy and
`docs/threat-model.md` for the full threat model. This plugin has gone
through independent security review; see `CHANGELOG.md` for the fix
history.

## Contributing

See `CONTRIBUTING.md`.

## License

GPLv2 or later. See `LICENSE`.
