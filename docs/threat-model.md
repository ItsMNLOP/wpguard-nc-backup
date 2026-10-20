# Threat model — WPGuard Nextcloud Backup (MVP)

Status: MVP draft. Security checks below were executed by the implementing
agent (Holt) as part of build-and-test. This is **not** a substitute for
Vex's independent security audit (SOP-023), which is required before any
production use. See "Vex handoff" at the end.

## Assets

1. Nextcloud app password (credential).
2. Full site database export (contains user password hashes, session
   tokens, any PII in posts/comments/users, plugin secrets stored in
   `wp_options`).
3. Full site file export (contains `wp-config.php` — DB credentials, auth
   salts; themes/plugins; media, which may include non-public uploads).
4. The backup archives at rest on Nextcloud.
5. WordPress admin capability to trigger backups / read settings.

## Actors

- **Site admin** (trusted, `manage_options`): intended operator.
- **Other authenticated WP users** (lower capability): must not reach any
  plugin action.
- **Unauthenticated web visitor**: must not reach any plugin action or file.
- **Network attacker** (on path between WP host and Nextcloud, or
  on-path within the shared host): sees only TLS-protected traffic if TLS
  verification holds.
- **Someone with read access to the WordPress database** (e.g., via a
  different vulnerability, or a hosting-provider insider): sees the stored
  app password (ADR 0002 documents this residual risk explicitly) and sees
  job metadata (never the DB/file archive contents themselves, those aren't
  stored locally after upload).
- **Someone with read access to the configured Nextcloud account/folder**:
  this is the intended backup consumer, but if that account is
  over-privileged (e.g., admin, or shared with other data), the full
  archive content is exposed to anyone with equivalent access — hence
  ADR's and the brief's insistence on a *dedicated, non-admin* Nextcloud
  account.

## STRIDE-style pass over the MVP surface

| # | Threat | Where | Mitigation in this build |
|---|---|---|---|
| T1 | CSRF triggering a backup or changing settings | `admin-ajax.php` actions, including `wpguard_nc_cancel_job` (added 2026-10-19) | `wp_verify_nonce` on every write action; nonce scoped per-form, checked server-side, action rejected (403 + logged) without it |
| T2 | Non-admin triggers backup or reads settings/secrets | same actions, including `wpguard_nc_cancel_job` (added 2026-10-19) | `current_user_can('manage_options')` checked on every action handler before any other logic; denied requests return 403 and are logged (sanitized, no secret echo) |
| T3 | Reflected/stored XSS via settings fields (URL, folder path, CA bundle path) | admin settings page render | All output escaped with `esc_html`/`esc_attr`/`esc_url` at render; all input sanitized (`sanitize_text_field`, `esc_url_raw`) and validated (URL scheme must be `https`, folder path restricted to a safe charset) on save |
| T4 | SQL injection in the DB exporter | `class-db-exporter.php` | Table/column identifiers come only from `SHOW TABLES`/`SHOW COLUMNS` against the live schema (never user input) and are backtick-quoted; all values are read via prepared `$wpdb->prepare` style parameter binding when filtering, never string-concatenated from request input |
| T5 | SSRF — plugin fetches an attacker-controlled internal URL | Nextcloud base URL setting | Base URL is admin-only (`manage_options`), not reachable by lower-privileged users or request parameters; URL must be `https`; this is an accepted admin-configurable *destination*, not a general-purpose fetcher (no feature lets a non-admin or a webhook influence the target). Documented residual risk: a malicious admin (already fully trusted in WordPress's model) could point it at an internal HTTPS service — out of scope, admins are already trusted |
| T6 | Path traversal / zip-slip on restore | restore runbook / any future restore tooling | Archive writer never writes paths containing `..` or absolute paths into the zip; restore runbook instructs extraction with a modern `unzip`/`ZipArchive` (both reject `..`-escaping entries) and the manual runbook explicitly tells the operator to verify extracted paths stay under the target root before use. Covered by an automated unit test asserting a crafted `../../evil.php` entry name is rejected by the archive writer at write time (defense in depth: reject at creation, not just at extraction) |
| T7 | Zip/archive bomb on restore or on verification read-back | verification step, restore | Read-back verification streams to a temp file with a byte-size ceiling check against the manifest's declared size *before* full download completes (abort if actual exceeds declared by more than a small tolerance); restore runbook warns to check available disk space against manifest-declared sizes before extracting |
| T8 | Credential disclosure via logs | `class-logger.php`, any error message | A single redaction routine (`Logger::redact()`) strips any string matching the stored app password (compared by value, not just key name) and any string matching common secret-shaped patterns before writing any log line, admin notice, or AJAX response. Unit-tested: logger given a string containing the live test app password emits a log line that does **not** contain it |
| T9 | Credential disclosure via the SQL/file archive itself | DB exporter, file inventory | The exporter explicitly excludes the plugin's own options row(s) that hold the app password/CA path from the `wp_options` table dump (row-level exclusion, not whole-table exclusion, since `wp_options` holds much else that must be backed up); file inventory excludes the plugin's own local staging/temp directory. **Vex audit (2026-10-19) found this was incomplete**: when the admin uses ADR 0002's constant-override escape hatch, the credential lives in `wp-config.php`, which was archived verbatim — the backup that depends on the credential also contained it (H-02). Fixed: `wp-config.php` is redacted (credential-constant `define()` lines stripped) before archiving, regardless of whether the override is active, and `Settings::save()` now purges any stale `wp_options` copy once the constant takes over. Verified by an automated test: generate a backup, grep the resulting SQL export and every archived file for the literal app-password string — must not match |
| T10 | Credential disclosure via browser (settings page re-renders saved password) | settings page | Password field is always rendered empty/masked on load; saving a blank password field means "leave unchanged," never "clear it" — enforced server-side, not just UI convention |
| T11 | Man-in-the-middle due to disabled/weakened TLS verification | Nextcloud client | No code path sets `sslverify => false` (grep-enforced, see `tests/unit/NoInsecureTlsTest.php`); only mitigation available is a verified, admin-supplied private CA bundle (ADR 0007), never a bypass |
| T12 | False success — upload reported OK without real verification | transport/verification | ADR 0006: every part is read back and hashed; `COMPLETE.json` is the only success signal and is itself verified after upload; unit + integration tests assert a corrupted/truncated remote object is detected and the job is marked failed, not verified. **Vex audit (2026-10-19) found a second path to false success**: the AJAX admin-UI polling and the WP-Cron fallback could both call `Job_Orchestrator::advance()` for the same job with no mutual exclusion, racing on the same checkpoint — producing a backup that was structurally corrupt (duplicated/lost table or zip entries) but whose locally-computed hash still matched what got uploaded, so it still passed verification (H-01). The WP-CLI path this plugin's own E2E test uses never hit this, since WP-CLI never schedules WP-Cron. Fixed with a non-blocking MySQL advisory lock (`GET_LOCK`/`RELEASE_LOCK` keyed by job_id) around the whole of `advance()`: a second caller that can't acquire the lock just reports current state instead of racing it. **2026-10-19**: a new `Job_Orchestrator::cancel()` admin override (JOB-05 manual recovery) was built and Vex-reviewed against this same lock — it respects `try_lock()` and reports `lock_contended` (not success) if `advance()` holds it, verified live by `tests/e2e/run-e2e-cancel-lock-contention.sh` (a genuinely concurrent holder process + a canceller process, same shape as `run-e2e-lock-race.sh`). **Still open**: no regression test yet exercises concurrent AJAX+cron *advancement* directly (two `advance()` calls racing each other); current coverage there is still the existing single-process E2E drill plus code-level review — the new test covers `cancel()` vs `advance()` contention, not `advance()` vs `advance()` |
| T13 | A failed/interrupted run destroys the last good backup, or retention pruning deletes the wrong (too many, or still-needed) backups, or a fully-trusted-but-malicious/careless admin uses retention to destroy backup history | retention | MVP performed no deletion of any kind. **Phase 2 (2026-10-20)**: retention pruning (`Retention::prune()`) now exists, default **disabled** (`retention_count = 0`, keep-all) specifically so no plugin update or activation can silently start deleting backups — an admin must explicitly opt into a positive count. Only ever runs after a job is already marked `verified` (`Job_Orchestrator::maybe_prune_old_backups()`, called from `step_finalize()`), and a pruning failure is caught and logged, never flips a verified backup back to failed. Deletes oldest-first by lexicographic `backup_id` order among *verified* backups only (chronological by construction, since `backup_id` is timestamp-prefixed) — a folder must match the real `backup_id` format and carry a `COMPLETE.json` to count at all; a failed/interrupted job's folder is neither counted toward the keep-N total nor touched (**Vex delta audit, 2026-10-20, RETENTION-2** — initially missed: such folders were silently counted, meaning fewer real backups survived than the configured count). The just-finished backup is always the newest verified one and therefore always kept, with no special-casing needed. Verified against a real Nextcloud account (not a mock): `tests/e2e/run-e2e-retention.sh` proves `keep=0` is a true no-op, `keep=N` deletes exactly the oldest excess among verified backups, a failed-job stub is never counted or touched, and a real end-to-end run through `step_finalize()` itself (not just a direct call to `prune()`) completes without the arity error described below. A deliberately interrupted job still leaves a prior `COMPLETE.json`-bearing backup folder untouched regardless of retention. **Same audit also found two implementation bugs that had silently disabled retention entirely**: `maybe_prune_old_backups()` called `Retention::prune()` with the wrong number of arguments (an `ArgumentCountError` in PHP 8, silently caught by its own try/catch — retention never actually ran in production despite passing a direct-call test of `prune()` alone) and `prune()`'s declared `: array` return type didn't match its `WP_Error` return path (also a PHP 8 `TypeError`, also silently caught) — both fixed, and the regression test now exercises the real `step_finalize()` call site specifically so this class of bug can't hide behind a direct-call-only test again (RETENTION-3/4). **Residual, accepted risk**: a fully trusted WP admin (already fully trusted in this plugin's threat model — see "Residual risks" below) could set `retention_count=1` and trigger a backup specifically to destroy prior backup history. Confirmed live against the real Nextcloud test server (2026-10-20): a WebDAV `DELETE` here lands in that account's own trashbin, not permanent erasure — meaningfully reduces, but does not eliminate, this risk (depends on that server's own trashbin retention/quota policy, which this plugin has no visibility into) |
| T14 | Public exposure of backup files or temp archives | local temp storage, Nextcloud folder | Local temp files are written under `wp-content/wpguard-nc-backup-tmp/` guarded by a generated `index.php` (empty, prevents directory listing) and a `.htaccess` deny-all (Apache only — no equivalent protection on Nginx). **Vex audit (2026-10-19, L-01) found `cleanup_tmp()` previously ran only on the success path**; a failed job left partial `database.sql.gz`/`files.part-*.zip` under a `backup_id`-named directory indefinitely, directly web-accessible by URL on Nginx hosts (bounded by the 32-bit random suffix in `backup_id`, and the SQL dump already excludes the credential row, so practical exploitability was judged LOW, but the description was wrong). Fixed: `cleanup_tmp()` now also runs on the failure path |
| T15 | Archive bomb / resource exhaustion from a malicious or corrupted SQL/file component during restore testing | E2E harness | E2E harness runs inside disposable containers with no access to production resources regardless of outcome |
| T16 | Downgrade: admin accidentally points Nextcloud URL at plain `http://` | settings validation | Save-time validation rejects any scheme other than `https`; no setting exists to override this in MVP |
| T17 | Unattended WP-Cron-triggered state changes (Phase 2, 2026-10-20): the automatic stale-lock reaper and automatic scheduled backups both run with no request-level nonce/capability check, reachable (in the WP-Cron pseudo-cron sense) by any site visitor's page load | `Job_Orchestrator::reap_stale_lock()`, `run_scheduled_backup()`, both in `wpguard-nc-backup.php`/`class-job-orchestrator.php` | **Vex delta audit, 2026-10-20**: correct as designed, not a gap — neither function accepts any request input of any kind (no `$_POST`/`$_GET` reach either one), so there is nothing for an unauthenticated visitor to inject; a nonce/capability check exists to gate *user-supplied input*, which doesn't apply to a cron callback with none. The reaper's only observable effect (marking a job `failed`) is the same state transition the existing capability-gated Cancel button already performs. `run_scheduled_backup()` only starts a job if `Settings::has_credentials()` and reuses `create_job()` unchanged. LOW-JOB05-1 (same audit): the reaper's hourly cron widens the pre-existing LOW-R2 `wait_timeout`-lock-drop condition's practical surface (a second actor beyond a concurrent `advance()` call could now acquire a dropped lock), but the outcome stays bounded to the job being marked `failed`, never incorrectly `verified` (`advance()`'s terminal-state check prevents any further transition past a job already marked failed out from under it) |
| T18 | Self-hosted update feed (Phase 2, 2026-10-20): a malicious or compromised update source could get WP to download and install an attacker-controlled package with full plugin-code execution — the highest-impact category this plugin's own feature set can introduce | `Update_Checker`, polls `api.github.com` (public, unauthenticated GET) for the repo's latest Release | Package source is `github.com/ItsMNLOP/wpguard-nc-backup` only — same repo and account that already controls the plugin's own source code, so this introduces no *new* trusted party; TLS to `api.github.com` is handled by WP's own `wp_remote_get()` (standard cert verification, not overridden — see T11's no-`sslverify`-bypass guarantee, which applies here too). **Fail-closed by construction**: an update is only ever offered when the release carries an explicit `.zip` asset (`Update_Checker::select_package_asset()`, name must start with the plugin slug and end in `.zip`) — never GitHub's auto-generated zipball, which contains the whole repo (including `tests/`, `.github/`, `docs/`) rather than just the plugin folder, so using it as the install package would be wrong even with no attacker involved at all; `tests/e2e/run-e2e-update-checker.sh` Case C proves a newer-but-asset-less release is never offered. Release body text is rendered in the "View details" popup through `wp_kses_post()` before display, not trusted as pre-sanitized HTML. Residual: whoever can push a Release to this repo (today, the same single GitHub identity that can push commits to `main` — see the branch-protection discussion in `STATUS.md`) can cause any site running this plugin to install arbitrary code on its next update check; this is the same blast radius as that identity already has by pushing to `main` directly (nothing in this feature expands who is trusted, only how an already-trusted change reaches installed sites) |

## Explicitly out of scope for MVP (and why)

- Encryption at rest of the archive content: NFR-04/Phase 5 — "never invent
  cryptography" and no key-management story exists yet. Documented as a
  limitation: anyone with legitimate access to the Nextcloud destination
  account can read the raw backup content (same as TRN transport once
  stored — Nextcloud's own at-rest protections, if any, apply, not the
  plugin's).
- Multi-tenant/multisite isolation (multisite explicitly out of scope —
  parked in backlog, `STATUS.md` 2026-10-20, no multisite target exists
  to build or test against).

**No longer out of scope as of 2026-10-20 (Phase 2)**: WP-Cron-triggered
unattended runs now exist (automatic stale-lock recovery, automatic
scheduling) — see T17.

## Residual risks accepted for MVP, with owner-visible documentation

- App password at rest in `wp_options` (ADR 0002) unless the operator uses
  the constant override.
- No at-rest encryption of the uploaded archive (NFR-04).
- A fully trusted WordPress admin remains fully trusted (standard WordPress
  threat model; this plugin does not attempt to defend against its own
  administrator).

## Vex handoff

This document, plus the automated tests referenced inline (T8, T9, T11,
T12, T13, T6), constitute the self-review Holt is permitted to do under his
own contract ("produce a data-flow and threat-model summary... deliver to
Vex before the feature ships to any production site"). **No production
deployment has occurred or is proposed as part of this MVP delivery.**
Before any real-world (non-disposable-test) use, this document and the
credential-storage deviation in ADR 0002 must go to Vex for independent
audit per SOP-023. That has not happened yet and is called out explicitly in
the final MVP report as an open item, not a completed gate.
