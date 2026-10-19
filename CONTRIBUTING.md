# Contributing

Solo/internal project at MVP stage. If this ever opens up:

1. Read `docs/architecture.md` and the ADRs in `docs/adr/` before changing
   module boundaries — they record *why*, not just *what*.
2. Any change to `docs/backup-format.md`'s manifest schema needs a
   `format_version` bump and a compatibility note (MNT-04).
3. Every change touching credential handling, upload validation, or
   file-path handling needs a security pass before merge (see `SECURITY.md`).
4. Add a test alongside the feature, not after. `tests/unit` for pure PHP
   logic, `tests/integration` for anything touching the disposable Docker
   stack, `tests/e2e` for full backup/restore drills.
5. Run `docker/scripts/bootstrap.ps1` to get a local stack, and
   `docker/scripts/run-e2e.ps1` before claiming a change doesn't break
   restore.
