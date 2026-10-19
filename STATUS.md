# STATUS — WPGuard Nextcloud Backup plugin

Live gate checklist. Updated as work proceeds. Do not hand-wave a checkbox —
each one links to the exact command/evidence used to check it.

## Environment

- [x] Docker Desktop running (`docker version`, `docker info`) — confirmed 2026-10-19.
- [x] Repo relocated outside Nextcloud-synced folder: `C:\Users\Claude\dev\wpguard` (was at risk of syncing secrets/archives to real Nextcloud from `PKA/Projects/WPGuard`).
- [x] Image tags confirmed pullable via `docker manifest inspect`: `wordpress:7.1-php8.3-apache`, `nextcloud:34-apache`, `mariadb:11.4`.

## Phase 0

- [x] ADRs 0001–0008 written (`docs/adr/`).
- [x] Threat model written (`docs/threat-model.md`).
- [x] Backup format / manifest schema written (`docs/backup-format.md`).
- [x] Restore runbook written (`docs/restore-manual.md`).
- [x] Architecture doc written (`docs/architecture.md`).
- [ ] Local disposable Docker stack provisioned and reachable.
- [ ] TLS: local CA + Nextcloud-fronting proxy cert generated.
- [ ] Transport spike: PUT/GET/PROPFIND/DELETE against app-password account from inside WP container over verified TLS, proven with a throwaway script before the real client class is written.

## Phase 1 (MVP) — release gate (brief section 15 "MVP release gate")

1. [ ] Installable plugin ZIP + source repo + setup instructions + sample non-secret config.
2. [ ] Admin can enter URL/username/app password, Test Connection, start manual backup.
3. [ ] Archive+manifest written to configured Nextcloud dir over authenticated HTTPS WebDAV; success only after checks pass.
4. [ ] Backup includes recoverable SQL export + in-scope files; **end-to-end restore to a clean disposable WP instance performed and verified.**
5. [ ] Failure injection (bad auth, unreachable host, TLS error, interrupted upload, corrupt/missing part) yields explicit failed/incomplete status, never false success.
6. [ ] Credentials redacted from logs/exports/errors/HTTP-visible settings; non-admin cannot trigger backup or read secrets.
7. [ ] Automated tests + static checks + compatibility smoke tests run, commands/results recorded; gaps disclosed, not hidden.
8. [ ] MVP READY FOR TESTING report delivered.

## Explicitly deferred (not blocking MVP)

- Scheduling/retention/one-click restore/multisite/chunked-upload-v2 (Phase 2+).
- Vex independent security audit (SOP-023) — required before any production use, not before this MVP test handoff, since no production deployment occurs.
- CI actually executing (no CI runner connected this session) — CI YAML is written and reported as **not executed**.
