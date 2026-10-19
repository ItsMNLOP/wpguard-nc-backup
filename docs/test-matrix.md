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
| Nextcloud auth/TLS/WebDAV round trip | integration | `tests/e2e/run-e2e.sh` step 1 (Test Connection) | yes, against local disposable Nextcloud 34 |
| Three successive backups | integration | `tests/e2e/run-e2e.sh` step 2 | yes |
| Full backup -> download -> restore -> content assertions | E2E | `tests/e2e/run-e2e.sh` step 3 | yes, against local disposable stack (one version combination) |
| Credential not present in SQL/file export | integration | `tests/e2e/run-e2e.sh` step 4 (grep dump + archive for app password) | yes |
| Non-admin cannot trigger backup / read settings | integration | `tests/e2e/run-e2e.sh` step 5 | yes |
| Invalid app password / unreachable host / TLS error / corrupted remote part | failure injection | `tests/e2e/run-e2e.sh` step 6 | yes, subset — see STATUS.md for exact list covered vs deferred |
| Multi-version WP/PHP/Nextcloud/MariaDB matrix | CI matrix | `.github/workflows/ci.yml` | **not executed** — no CI runner connected |
| Upgrade-from-previous-version / schema migration | — | n/a yet | **not applicable** — 0.1.0 is the first version |
