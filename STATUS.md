# STATUS — WPGuard Nextcloud Backup plugin

Live gate checklist. Every checked box below was verified by an actual
command run against the live disposable stack during this build, not
assumed. Evidence commands are in `tests/e2e/run-e2e.sh`,
`docker/scripts/bootstrap.ps1`, and `tests/unit/`.

## Environment

- [x] Docker Desktop running, confirmed via `docker info`.
- [x] Repo relocated outside the Nextcloud-synced folder to `C:\Users\Claude\dev\wpguard` (the original location, `PKA/Projects/WPGuard`, syncs to the owner's real Nextcloud — secrets/archives must never land there).
- [x] Image tags confirmed pullable: `wordpress:7.1-php8.3-apache`, `nextcloud:34-apache`, `mariadb:11.4`.
- [x] Full disposable stack provisioned via `docker/scripts/bootstrap.ps1`: source WP + MariaDB, Nextcloud + MariaDB behind a local-CA-fronted nginx TLS proxy, clean restore-target WP + MariaDB, wp-cli driver. All bound to 127.0.0.1, project-scoped (`wpguard-test`).

## Phase 0

- [x] ADRs 0001–0009 (`docs/adr/`), two documenting live-discovered fixes (0009: SSRF-blocklist exception) not just upfront design.
- [x] Threat model (`docs/threat-model.md`), backup format (`docs/backup-format.md`), restore runbook (`docs/restore-manual.md`), architecture (`docs/architecture.md`).

## Phase 1 (MVP) — release gate, against brief section 15

1. [x] Installable ZIP built and verified clean (`dist/wpguard-nc-backup-0.1.0.zip`, contents checked — plugin files only, no dev/test cruft). Installed via `wp plugin install <zip> --activate` on a fresh instance (not the dev bind-mount) and confirmed `is_plugin_active()` true.
2. [x] Admin can enter URL/username/app password, Test Connection, Back Up Now — exercised via the real settings-save path (`Settings::save()`) and the real `Nextcloud_Client`, not mocked.
3. [x] Archive + manifest written to the configured Nextcloud folder over authenticated HTTPS WebDAV; verified via independent `curl` listing — `manifest.json`, `database.sql.gz`, `files.part-0001.zip`, `COMPLETE.json` all present, `COMPLETE.json` confirmed written last.
4. [x] **End-to-end restore to a clean disposable WordPress instance performed and verified** — `tests/e2e/run-e2e.sh` runs the full drill: 3 successive backups → independent download → SHA-256 verification against the manifest → clean DB → SQL import → file extraction → serialization-safe `wp search-replace` → content assertions (post count, sentinel option, active theme, sentinel serialized postmeta, media file byte-for-byte checksum). **Exit 0, "E2E DRILL PASSED."** **Separately confirmed on the owner's own real, hands-on backup** (not synthetic fixtures): same runbook, same result — restored login correctly showed the owner's own pre-existing WordPress admin account (overwriting the fresh install's placeholder account), exactly as DB-import-based restore should behave.
5. [x] Failure injection covered live: wrong app password (classified `auth`, HTTP 401), unreachable host (`network`, DNS resolution failure), TLS error with no trusted CA (`network`, certificate verify failure), interrupted job (stays `running` forever in MVP — see known limitation below; never reports `verified`). All three prior good backups remained present and untouched on Nextcloud after the interruption.
6. [x] Credential redaction verified against real artifacts: the stored app password does not appear in the downloaded SQL dump, the extracted file archive, `wp-config.php` inside that archive, or `manifest.json`.
7. [x] Automated checks run and recorded: 20/20 unit tests pass (`tests/unit/run-all.php`, including a static grep that `sslverify=>false` never appears in plugin source); full plugin PHP-lints clean. CI YAML is written (`.github/workflows/ci.yml`) but **has not executed** — no CI runner connected this session.
8. [x] This document plus `tests/e2e/run-e2e.sh`'s output constitute the MVP READY report's evidence.

## Two real bugs found and fixed via this live testing (not theoretical)

- **SQL export corruption**: `$wpdb->prepare('%s', $val)` / `esc_sql($val)` both apply WordPress's internal placeholder-escape token wrapping (meant to be undone by a later `prepare()` call that was never made), which silently corrupted any exported value containing a literal `%` — found via a post slug with percent-encoded emoji bytes, which overflowed the `post_name` column on restore import. Fixed with `$wpdb->remove_placeholder_escape()`. See `class-db-exporter.php`.
- **Nextcloud URL validation (SSRF blocklist)**: `wp_http_validate_url()` rejects private-IP-range hosts by default, which would have rejected the plugin's own primary use case (self-hosted Nextcloud on a private network). Fixed in ADR 0009 with a narrower, purpose-fit validator.
- Also fixed during integration: a double-prefixed remote path (ADR'd in `Nextcloud_Client::relative_path()`), a missing `WORDPRESS_CONFIG_EXTRA`/CA-mount on the wp-cli test container, and `classify_failure()` not receiving HTTP status data from `mkcol()`/`propfind`/`get`/`delete` errors.

## Explicitly deferred (not blocking MVP, per brief)

- Scheduling/retention/one-click restore/multisite/chunked-upload-v2 (Phase 2+).
- **Stale-job-lock recovery** (JOB-05, [H]): found live — an interrupted job blocks all future backups until an admin manually clears its row. Disclosed in `docs/operations.md`.
- Vex independent security audit (SOP-023) — required before any production use, not before this MVP test handoff, since no production deployment occurred.
- CI actually executing — written, not run.
