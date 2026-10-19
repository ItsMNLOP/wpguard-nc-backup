# WPGuard Nextcloud Backup

A WordPress plugin that backs up this site's files and database to a
user-controlled Nextcloud server over authenticated HTTPS WebDAV, with
read-back checksum verification before anything is reported successful.

**Status: MVP (Phase 1), local-only testing. Not security-audited. Not
deployed anywhere.**

## Why

See `docs/requirements.md` (and the fuller original brief delivered to
this project) for the full why/what. Short version: third-party backup
plugins exist, but independently verified recoverability was the whole
point of building this instead of relying on one.

## Repo layout

```
plugin/wpguard-nc-backup/   The installable plugin (zip this directory's contents).
docs/                        ADRs, threat model, backup format, restore runbook, architecture.
tests/unit/                  Pure-PHP logic tests, no WordPress/Docker needed.
tests/e2e/                   Fixture seeding + full backup/restore drill scripts.
docker/                      Disposable local test stack (WordPress source + Nextcloud + clean restore target).
.github/workflows/ci.yml     CI definition — written, not yet executed (no CI runner connected this session).
```

## Local development

Requires Docker Desktop and PowerShell (Windows). All resources are
project-scoped, bound to 127.0.0.1, and torn down by `cleanup.ps1` —
nothing here touches any real WordPress/Nextcloud instance.

```powershell
docker/scripts/bootstrap.ps1     # one-time: generates secrets+TLS CA, brings up the stack, seeds fixtures
docker/scripts/run-e2e.ps1       # runs a backup, verifies it, restores it to a clean instance, asserts content
docker/scripts/package.ps1       # builds the installable ZIP into dist/
docker/scripts/cleanup.ps1       # tears down only this project's containers/volumes/networks
```

Source site: http://127.0.0.1:8081/wp-admin · Restore target:
http://127.0.0.1:8082/wp-admin · Nextcloud: https://127.0.0.1:8443
(self-signed via a generated local CA at `docker/certs/wpguard-test-ca.crt`
— trusted by the test containers via the `WPGUARD_NC_CA_BUNDLE` constant,
never added to the host's trust store).

## Documentation map

- `docs/architecture.md` — module boundaries, request flow.
- `docs/adr/` — numbered decision records, including two documented
  deviations from the WPGuard specialist's standing contract (credential
  storage, job runner) made because this build's own brief took
  precedence — see ADR 0002/0003.
- `docs/threat-model.md` — STRIDE-style pass + residual risks + Vex handoff note.
- `docs/backup-format.md` — manifest schema v1, remote layout, compatibility policy.
- `docs/restore-manual.md` — the only restore path in this release; the E2E test executes it literally.
- `docs/maintenance.md`, `docs/operations.md`, `docs/test-matrix.md` — ongoing-support docs.
- `STATUS.md` — live gate checklist with evidence links.

## Security

Not audited. See `SECURITY.md` and `docs/threat-model.md`.
