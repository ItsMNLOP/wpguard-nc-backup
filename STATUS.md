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
- CI actually executing — written, not run.

## Real-production restore drill, performed by Holt — 2026-10-19

Restored the owner's first real backup (`20261019T232522Z-2469c25c`, real
the owner's production site content — 142 `wp_posts` rows, 3 users, 12 active plugins)
to the disposable `wp-restore` target, following `docs/restore-manual.md`
exactly: downloaded all 5 components via the dedicated Nextcloud test
account (shared read access), SHA-256 verified every one against the
manifest before trusting anything, path-safety checked both archive parts,
confirmed `wp-config.php` redaction behavior (no credential present to
redact — this site uses the `wp_options` credential path, not the
constant-override one), imported the real DB, extracted real files
(121MB), ran serialization-safe `search-replace` for the URL.

**Verified**: exact 142/142 row-count match against the manifest's
per-status breakdown, both real published posts intact (non-empty,
correct titles), a real media file intact on disk, 3/3 users, correct
active theme and all 12 active plugins matching the live site.

**the owner's own confirmation, browsing the restored instance himself**: his
real account logged in correctly, **including his 2FA state** (wp-2fa
plugin) — full fidelity on a genuinely sensitive, stateful security
feature, not just static content. Strongest restore validation to date —
real production data, not synthetic fixtures. `.restore-scratch/` (holds
the real downloaded backup) added to `.gitignore`, commit `bae95cb`.

## Performance: time-boxed step batching — 2026-10-19

the owner's hands-on backup against his real site (the owner's production site /
the owner's Nextcloud instance) flagged the run as noticeably slower than his existing
solution. Root cause: `export_db`/`export_files` did exactly one table or
file per `advance()` call — on a real site with many small files that's
thousands of separate AJAX round trips, each a full WordPress bootstrap
plus the admin UI's 500ms poll delay. Fixed: each call now keeps working
through additional tables/files for up to 2 seconds (`STEP_TIME_BUDGET_SECONDS`)
before returning, while still persisting the checkpoint after every single
item — same crash-safety as before, just far fewer round trips. Commit
`aa7ed28`. 25/25 unit tests and the full E2E drill re-verified green.

The other flagged cost — every uploaded part is fully re-downloaded and
re-hashed to verify, doubling upload-stage transfer — is intentional
(ADR 0006: Nextcloud's ETag isn't a reliable content hash).

**Investigated and rejected, 2026-10-19**: Nextcloud's `OC-Checksum`
upload header + `oc:checksums` WebDAV property looked like a cheaper
alternative (server-side hash check, no full re-download). Tested live
against the real target server (`the owner's Nextcloud instance`, dedicated test
account) before building anything, per standing practice of not inventing
API semantics from memory:
- Upload with a correct `OC-Checksum` header: stored, echoed back on
  PROPFIND. Looked promising in isolation.
- Upload with a **deliberately wrong** checksum: still `201 Created`,
  stored and echoed back unchanged — **no server-side rejection**.
- Upload with no checksum header at all: `oc:checksums` comes back empty
  (404) — the server never computes one on its own either.

**Conclusion: on this server, `OC-Checksum` is pure client-asserted
metadata with zero independent verification** — it would only ever catch
an error the client already knew about, not transit/storage corruption,
which is the entire point of verification. Not implemented. The existing
full re-download-and-rehash in `step_upload_parts()` remains the only
proven-safe verification method; no cheaper substitute holds up against
the real target server. Do not re-attempt this without first re-testing
live, same as above.

## Vex security audit (SOP-023) — 2026-10-19

First independent security pass. Verdict was **BLOCKED** pending two HIGH
findings; both fixed same-day, re-verified (22/22 unit tests, full E2E
drill green), committed in `0310976`. See `docs/threat-model.md` (T9,
T12, T14) for the updated per-threat detail.

- **H-01** (concurrent AJAX+cron job-advance race could mark a
  structurally corrupt backup `verified`) — fixed: MySQL advisory lock
  around `Job_Orchestrator::advance()`.
- **H-02** (constant-override credential path backed up `wp-config.php`
  containing the very credential protecting the backup) — fixed:
  `wp-config.php` redacted before archiving; stale `wp_options` copy
  purged once the constant takes over.
- **M-01** (WebDAV client followed HTTP redirects) — fixed: `redirection
  => 0`.
- **L-01** (temp files not cleaned up on job failure, web-accessible by
  URL on Nginx) — fixed: `cleanup_tmp()` now runs on the failure path too.
- **L-02** (archive-bomb check was post-download, not a true streaming
  abort) — fixed: `download_to_file()` now passes `limit_response_size`
  to abort mid-stream, with the original post-hoc check kept as
  defense in depth.
- **L-03** (`simplexml_load_string` without explicit `LIBXML_NONET`) —
  fixed.
- **L-04** (CA-bundle file read has no size cap) — fixed: 1 MiB ceiling
  checked via `filesize()` before the content is ever read.

**All findings from the 2026-10-19 audit are now fixed** (both HIGHs,
the one MEDIUM, all four LOWs) — see commits `0310976`, `31068b6`.
- **Vex's two re-verification conditions were met** (2026-10-19, same
  day): `tests/e2e/run-e2e-lock-race.sh` proves the H-01 lock actually
  excludes a genuinely concurrent second OS process/MySQL connection
  (the waiter blocks for multiple attempts and only acquires at or
  after the holder's release — not just a code-review argument);
  `tests/e2e/run-e2e-const-override.sh` runs a real backup through the
  constant-override credential path specifically and confirms the
  archived `wp-config.php` carries the redaction marker instead of the
  literal credential, with unrelated content intact. Both PASSED.
## Real-host bug found via the owner's own hands-on activation test — 2026-10-19

The automated packaging smoke test (`wp plugin install <zip> --activate`
against a Docker `wordpress:cli` instance) passed, but installing the same
ZIP on the owner's real production host produced a white screen: **"Plugin file
does not exist."** Root cause: `docker/scripts/package.ps1` built the ZIP
with PowerShell's `Compress-Archive`, which stores path separators as
backslashes — non-compliant with the ZIP spec, which requires forward
slashes. `unzip` tolerates this with a warning (hence the Docker smoke test
passing), but raw PHP `ZipArchive::extractTo()` — confirmed directly inside
a `wordpress:cli` container, side-by-side against both the old and fixed
ZIPs — takes the stored name literally: every file lands flat with a
literal backslash *in* the filename, no real `includes/` subdirectory is
ever created, and `wpguard-nc-backup.php` never exists at the path
WordPress's activation check expects.

**Fixed:** `package.ps1` now builds the ZIP with `System.IO.Compression.ZipFile`
directly, writing each entry name with an explicit forward slash. Verified:
the rebuilt ZIP extracts cleanly via real `ZipArchive` with the correct
nested layout; the old ZIP, tested the same way, reproduces the owner's exact
symptom (main file absent at the expected path). The Docker-based packaging
smoke test did not and could not have caught this — it only caught it
because the owner tested hands-on against a real host, exactly per the
hands-on-test ask. `docs/test-matrix.md` updated with a new check
(raw-`ZipArchive` extraction, not just WP-CLI's install path) so this
class of bug is caught automatically going forward.

Action for the owner: remove whatever got left in `wp-content/plugins/` on
the owner's production site from the failed install attempt before re-uploading the
rebuilt `dist/wpguard-nc-backup-0.1.0.zip` — the flat, backslash-named
files are not a normal plugin folder and a stray leftover could collide
with the next upload.

- **Vex's final sign-off (2026-10-19): CLEAR TO SHIP** for
  testable/controlled deployment, consistent with this document's own
  standing caveats (CI not yet executing, scheduling/retention/
  one-click-restore are Phase 2, JOB-05 manual clear is a known
  limitation). Verified by independent line-by-line code review of
  commits `0310976`, `4cee50c`, `31068b6` — not taken on Holt's word.
  Surfaced two non-blocking advisories (LOW-R1: fail-closed check in
  case the wp-config.php redaction regex ever misses a credential;
  LOW-R2: document a MySQL `wait_timeout`/lock-reconnect edge case) —
  both fixed same day, commit `fa12bf5`, 25/25 unit tests and all E2E
  drills re-verified green.
