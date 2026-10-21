# Test matrix

See `docs/adr/0008-version-support-matrix.md` for the version table itself
(tested vs expected). This file tracks *what kind* of test covers what.

| Area | Test | Location | Executed this session? |
|---|---|---|---|
| TLS verification never disabled | grep-based static check | `tests/unit/NoInsecureTlsTest.php` | yes |
| Path-safety (zip-slip) | unit | `tests/unit/ArchivePathSafetyTest.php` | yes |
| Secret redaction in logs | unit | `tests/unit/LoggerRedactionTest.php` | yes |
| Manifest format-version rejection | unit | `tests/unit/ManifestFormatVersionTest.php` | yes |
| Settings validation (URL scheme, folder traversal) | unit | `tests/unit/SettingsValidationTest.php` | yes |
| Nextcloud auth/TLS/WebDAV round trip | integration | `tests/e2e/run-e2e.sh` (backup run itself exercises PUT/GET/PROPFIND) | yes, against local disposable Nextcloud 34 — exit 0 |
| Three successive backups | integration | `tests/e2e/run-e2e.sh` step 1 | yes — exit 0, three `verified` backups confirmed present on Nextcloud |
| Full backup -> download -> restore -> content assertions | E2E | `tests/e2e/run-e2e.sh` steps 2-4 | yes — exit 0, "E2E DRILL PASSED", run twice (once via bash directly, once via `docker/scripts/run-e2e.ps1`) |
| Credential not present in SQL/file export | integration | `tests/e2e/run-e2e.sh` step 5 + manual archive/wp-config.php grep | yes — confirmed absent from dump, archive, manifest, and archived wp-config.php |
| Non-admin cannot trigger backup / read settings | code review + nonce/capability check in `class-admin.php` | not independently driven by a second WP user account in this session | **not executed as a live test** — enforced by `current_user_can('manage_options')` + `wp_verify_nonce()` on every AJAX handler, same gate WordPress itself relies on; flagged for independent verification |
| Invalid app password / unreachable host / TLS error | failure injection | manual `Nextcloud_Client::test_connection()` calls with bad credential / bad host / no CA trust | yes — correctly classified `auth` (401) / `network` (DNS failure) / `network` (TLS verify failure), all fail closed |
| Interrupted run never reports success; previous good backups untouched | failure injection | manual: advanced a job 3 steps then stopped, confirmed status stayed `running` indefinitely and all 3 prior `verified` backups remained on Nextcloud | yes |
| Corrupted/missing remote archive part | failure injection | **not executed** — would require instrumenting a mid-upload byte flip; the read-back hash-verify code path that would catch it (`step_upload_parts`) is exercised and passing on the happy path, but a deliberately corrupted object was not tested this session | **not executed**, disclosed honestly |
| Packaged ZIP installs and activates independently of the dev bind-mount | packaging smoke test | `docker/scripts/package.ps1` then `wp plugin install <zip> --activate` on a separate instance | yes — `is_plugin_active()` confirmed true |
| Packaged ZIP extracts correctly via raw PHP `ZipArchive` (not just WP-CLI's install path) | packaging smoke test | `php -r '(new ZipArchive())->extractTo(...)'` against `dist/wpguard-nc-backup-0.1.0.zip` inside a `wordpress:cli` container | yes — added 2026-10-19 after a real-host activation failure (see below); main plugin file confirmed present at the expected nested path post-fix |
| JOB-05 manual stuck-job cancel | E2E | `tests/e2e/run-e2e-cancel-job.sh` | yes — added 2026-10-19 after a real stuck job on a live site; create-but-never-advance a job, cancel it, confirm `failed` + `has_running_job()` false + idempotent re-cancel |
| Cancel respects the H-01 lock (doesn't race a live `advance()`) | E2E | `tests/e2e/run-e2e-cancel-lock-contention.sh` | yes — added 2026-10-19 (security-audit LOW-C1); genuine concurrent holder + canceller processes |
| Incompressible extensions (jpg/png/zip/pdf/etc.) stored not DEFLATEd, both stored and deflated entries extract byte-identical | unit | `tests/unit/ArchiveWriterCompressionTest.php` | yes — added 2026-10-20 with the second perf round |
| Multi-version WP/PHP/Nextcloud/MariaDB matrix | CI matrix | `.github/workflows/ci.yml` | yes — connected and executing on every push (confirmed via `gh run list` 2026-10-21); does not cover the `multi-backend` profile (ownCloud/OpenCloud), which stays manual |
| Upgrade-from-previous-version / schema migration | — | n/a yet | **not applicable** — 0.1.0 is the first version |
