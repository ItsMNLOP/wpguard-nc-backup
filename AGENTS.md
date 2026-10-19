# Agent operating notes — WPGuard Nextcloud Backup plugin repo

For a replacement agent (human or AI) picking this up cold.

## What this is

MVP WordPress plugin + disposable Docker test harness. Built autonomously
per the brief at the PKA inbox (`CLAUDE_WORDPRESS_NEXTCLOUD_BACKUP_BRIEF.md`,
v1.3) under the Holt specialist identity. This repo lives at
`C:\Users\Claude\dev\wpguard`, deliberately **outside** the Nextcloud-synced
`PKA/` folder — do not move it back in; secrets, generated certs, and test
archives would otherwise sync to the owner's real Nextcloud.

## Guardrails (do not relax these without a new ADR + explicit approval)

- Never set `sslverify => false` anywhere. `tests/unit/NoInsecureTlsTest.php`
  greps for this; keep it passing.
- Never let `database.sql*` or any archive component contain the live
  `wpguard_nc_settings` option row — see `class-db-exporter.php`'s
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

- Vex security audit (SOP-023) not yet performed — required before any
  production use.
- CI (`.github/workflows/ci.yml`) written, never executed — no CI runner
  connected during this build.
- Phase 2+ (scheduling, retention, chunked upload v2, encryption) not
  started, intentionally.
