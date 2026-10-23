# Agent operating notes — WPThistle Backup and Restore plugin repo

For a replacement agent (human or AI) picking this up cold.

## What this is

MVP WordPress plugin + disposable Docker test harness. Built against a
written project brief (v1.3) covering scope, phased rollout, and the
guardrails below. This repo deliberately lives outside any
cloud-synced folder on the machine it was developed on — do not move
it into one; secrets, generated certs, and test archives would
otherwise sync somewhere they shouldn't.

## Guardrails (do not relax these without a new ADR + explicit approval)

- Never set `sslverify => false` anywhere. `tests/unit/NoInsecureTlsTest.php`
  greps for this; keep it passing.
- Never let `database.sql*` or any archive component contain the live
  `wpthistle_backup_settings` option row — see `class-db-exporter.php`'s
  `$exclude_sql` handling and threat-model T9.
- Never report a backup `verified` without `COMPLETE.json` itself having
  been uploaded and read-back verified (`class-job-orchestrator.php`,
  `step_finalize`).
- Never delete a prior backup or its components (MVP ships no deletion
  code at all — RET-01 is satisfied by absence, not by a careful check).
- Everything under `docker/` is project-scoped (`wpguard-test` compose
  project name). `cleanup.ps1` must only ever touch that project's
  resources.

## Commands

```powershell
docker/scripts/bootstrap.ps1
docker/scripts/run-e2e.ps1
docker/scripts/package.ps1
docker/scripts/cleanup.ps1
```

```bash
docker compose -p wpguard-test -f docker/docker-compose.yml run --rm wpcli wp --path=/var/www/html-source <wp-cli args>
```

## Acceptance gates

See `STATUS.md` — do not mark a checkbox done without the command/evidence
that proves it.

## Known open items for the next agent/session

- CI (`.github/workflows/ci.yml`) is connected and executing on every push
  to the real GitHub repo (confirmed via `gh run list` 2026-10-21) — the
  "written, never executed" note in earlier docs is stale. It does not yet
  build/attach a release zip on tag push (still manual, `docs/releasing.md`)
  and does not run the `multi-backend` compose profile (manual only).
- Phase 2+ items not yet started are tracked in `STATUS.md`.
