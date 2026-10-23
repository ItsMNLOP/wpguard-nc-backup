# Operations (MVP)

## Running a backup

Admin UI: WPThistle > Backup and Restore > "Back Up Now". Progress polls via
AJAX every 500ms; a WP-Cron fallback (`wpthistle_backup_cron_advance`) keeps the
job moving even if the admin tab is closed (ADR 0003). Reloading or
reopening the settings page while a job is in progress resumes reporting
it automatically (fixed 2026-10-19 — previously the status box went blank
on reload with no way to tell a still-running backup from a stalled one
without checking Nextcloud or the DB directly).

CLI (used by the E2E harness, also usable by an admin with shell access):
```
wp wpthistle-backup-restore run [--destination=nextcloud|download]
wp wpthistle-backup-restore status
```

`--destination=download` runs a credential-free backup and reports the
local bundle path on success — the WP-CLI equivalent of "Download Now"
(see below), fixed 2026-10-22 (previously CLI `run` hard-required
Nextcloud credentials unconditionally, even though the admin UI's AJAX
path already didn't).

## Downloadable backups (Phase 3)

"Download Now" packages the exact same backup (database + files, same
manifest/COMPLETE.json format) into one flat zip and lets you save it
straight to your own computer, instead of uploading it to Nextcloud. No
Nextcloud configuration is required for this — it's offered unconditionally
alongside "Back Up Now", not just as a fallback when Nextcloud isn't set up.
A download-destination backup is always a full backup (no incremental chain
to diff against without a persistent remote location); retention pruning
doesn't apply to it either.

The bundle is held in a random-token directory under
`wp-content/wpthistle-backup-restore-tmp/downloads/<token>/`, guarded the same way
as every other tmp/staging directory this plugin creates (`.htaccess`,
excluded from the next backup's own file enumeration). It is never deleted
on download (the server can't know the browser received every byte) —
instead:
- A new "Download Now" backup replaces any still-pending one (at most one
  pending bundle exists at a time).
- "Delete local copy" removes it immediately.
- An hourly sweep (and one on every settings-page load) removes it after
  24 hours regardless (`wpthistle_backup_download_bundle_ttl_seconds` filter, 300s
  floor).
- Deactivating the plugin purges it immediately rather than leaving it
  until reactivation.

The download itself is served by a dedicated `admin_post_` endpoint
(capability + nonce gated, navigable GET rather than AJAX/fetch, so the
browser streams it instead of buffering the whole zip in memory first).

**A downloaded bundle's `wp-config.php` has the site's real DB credentials
stripped** (`DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_HOST`, in addition to this
plugin's own app-password/CA-bundle constants, which are already stripped
on every backup regardless of destination) — a downloadable bundle leaves
the admin's controlled storage entirely the moment it's saved locally, a
materially wider exposure than the Nextcloud path (documented, accepted
residual exposure: `docs/threat-model.md` T9). AUTH_KEY/AUTH_SALT/etc are
deliberately left untouched (WP 2FA and encryption plugins store real
recovery material keyed to those values). **Restoring a downloaded bundle
onto different infrastructure requires re-entering those four DB values by
hand** — restoring it back onto the exact site it came from needs nothing
extra, since that site already has its own real values.

**A downloaded bundle restores through the existing "Upload a backup
bundle" restore path with no separate restore code** — it's built in the
exact same flat layout (`manifest.json`, `COMPLETE.json`, `database.sql.gz`,
`files.part-*.zip` at the zip root) `ajax_upload_restore_bundle()` already
accepts. Round-trip is bounded by the server's own `upload_max_filesize`/
`post_max_size` on the restoring site, same as any other manual bundle
upload.

## Statuses (NFR-01)

`queued` -> `running` -> (`uploaded-unverified` briefly, while `COMPLETE.json`
itself is being uploaded and verified) -> `verified`, or `failed` at any
point. There is no "success" status that skips verification — `verified`
is only reached in `step_finalize()` after `COMPLETE.json` round-trips.

## Finding a backup on Nextcloud

`/<configured-folder>/<site-id>/backups/<backup-id>/` — site-id is shown
nowhere in the UI yet in this MVP (gap — the admin page should display it;
tracked as a follow-up, not a release blocker since the manual restore
runbook's `curl`/WebDAV-client approach still works if you browse the
configured folder directly in Nextcloud's web UI).

## Transport (ADR 0004, ADR 0010)

Each component (database export, each `files.part-NNNN.zip`) is
uploaded, then independently read back and SHA-256-verified before a
backup is ever reported `verified`. Parts bigger than one chunk (8
MiB) use resumable WebDAV chunking v2 on servers that support it
(confirmed live: Nextcloud, ownCloud) — if the connection drops
mid-upload, the next attempt resumes from whatever chunks the server
already has rather than re-sending the whole part. OpenCloud doesn't
support this dialect (confirmed live, not assumed) and always uses a
single whole-part PUT instead; this is automatic, not a setting.

A job that's cancelled or fails mid-upload can leave an orphaned
chunked-upload session on the server (confirmed live: doesn't count
against storage quota on either server). Nextcloud cleans these up on
its own, automatically, once a day. **ownCloud does not** — if you're
using ownCloud as the destination, periodically run
`occ dav:cleanup-chunks` on it (an operator action, not something this
plugin can trigger remotely).

## Logs

Per-job structured log (last 200 entries), redacted at write time
(`Logger::redact()`), visible in the admin page's log pane and via
`wp wpthistle-backup-restore status`. No secrets should ever appear there — this
is tested (`tests/unit/LoggerRedactionTest.php`), not just asserted.

## Stale-lock recovery (JOB-05) — automatic, plus a manual override

JOB-01 requires preventing overlapping backups, implemented via
`Job_Store::has_running_job()` refusing a new job while one is
`queued`/`running`/`uploaded-unverified`. A job that is genuinely
interrupted (PHP killed, container restarted, browser tab closed with
WP-Cron also failing to pick it up — the real-world case this was first
found through, both during this build's own E2E testing and live on a
real site) would otherwise stay stuck forever and block every future
"Back Up Now".

**Automatic recovery (Phase 2, 2026-10-20)**: `Job_Orchestrator::reap_stale_lock()`
marks a job `failed` if its checkpoint hasn't been touched in over
`STALE_LOCK_SECONDS` (600s default, filterable via
`wpthistle_backup_stale_lock_seconds`, floored at 60s — the follow-up security audit
LOW-JOB05-3 — so a filtered value can't shrink below the one genuinely
lock-free moment in a job's life, between `create_job()` returning and
the first `advance()` call acquiring the lock). It uses the same
`try_lock()`/`release_lock()` pair as `cancel()` (the security audit's H-01
primitive), so it can never race a genuinely active process: if the
lock is held, the job is left alone regardless of how stale its
timestamp looks. **The lock is the actual protection here, not the
600s margin** (the follow-up security audit LOW-JOB05-2, 2026-10-20) — a single
`step_upload_parts()` iteration can legitimately run on the order of
1800s once every per-attempt timeout and retry in
`class-nextcloud-client.php` is counted (`put_bytes`: up to 120s x 4;
`propfind_size`: up to 30s x 4; `download_to_file`: up to 300s x 4),
far longer than 600s — but the lock stays held for that entire
duration, so `try_lock()` correctly fails and the job is never touched.
`STALE_LOCK_SECONDS` only governs how long a job with no active
process at all (lock already free) sits before being reaped. Called from
`create_job()` — so the fix applies the instant anyone next tries to
back up, no admin action and no real server-cron (JOB-02, separate
item) required — and from a recurring hourly WP-Cron event
(`wpthistle_backup_stale_lock_check`) as a safety net for a site nobody
visits for a while. That recurring event is still WP-Cron pseudo-cron
under the hood (fires on the next traffic after it's due), so it
doesn't need real server-cron to exist, but inherits the same
"only fires on site traffic" limitation as everything else here until
JOB-02 lands. Regression test: `tests/e2e/run-e2e-stale-lock-recovery.sh`
(fresh job left alone; aged-past-threshold job reaped; a job whose lock
is genuinely held by a real concurrent process is correctly left alone
however stale its clock looks, then reaped once that lock frees up).

**Manual override, kept** (2026-10-19): the settings page also has a
**Cancel / Reset Stuck Job** button (`Job_Orchestrator::cancel()`),
capability/nonce-gated like every other admin action, for an admin who
doesn't want to wait out the staleness threshold, or wants to cancel a
job that's healthy but no longer wanted. Same cleanup path as any other
failure — nothing already uploaded to Nextcloud is touched. Regression
test: `tests/e2e/run-e2e-cancel-job.sh`.

**Related advisory (the security re-audit, 2026-10-19, LOW-R2):** the H-01 fix
(`Job_Store::try_lock()`/`release_lock()`, a MySQL `GET_LOCK`/
`RELEASE_LOCK` pair keyed by job_id) is session-scoped — it's
automatically released if the PHP process's MySQL connection drops.
On a host configured with an aggressive `wait_timeout` (well under
MySQL's 8-hour default — seen on some tight shared-hosting plans),
WordPress's `$wpdb` can silently reconnect mid-request during a long
upload-with-retries (each `upload_parts` attempt can take up to the
30s request timeout, times up to 4 retries). A silent reconnect drops
the advisory lock on the old connection, and a second process could in
principle acquire it while the first is still mid-step. This is not a
new condition introduced by the H-01 fix — any session-scoped lock in
a PHP plugin has the same exposure — but it's worth knowing before
relying on this plugin under non-default MySQL timeout settings.

**the follow-up security audit, 2026-10-20 (LOW-JOB05-1):** the automatic stale-lock
reaper (above) widens this same condition's practical surface — the
hourly safety-net cron now provides a second actor that could acquire
a dropped lock, not only a second concurrent `advance()` call. The
outcome stays bounded exactly as before: the job gets marked `failed`,
never incorrectly `verified` — `advance()`'s terminal-state check
means the original in-flight call can't continue past a job that's
already been marked `failed` out from under it. Still a LOW, still
requires non-default MySQL `wait_timeout` configuration.

The cron fallback relies on WP-Cron, which only fires on a page load on a
low-traffic site. If no one visits the site and the admin closes the tab
mid-backup, the job can stall indefinitely in `running`. Real server-cron
or WP-CLI-driven scheduling is Phase 2 (JOB-02).

## Automatic scheduling (Phase 2, 2026-10-20)

Settings page has an "Automatic backup schedule" dropdown: Disabled
(default), Daily, Weekly. Backed by a real recurring WP-Cron event
(`wpthistle_backup_scheduled_backup`) reconciled against the stored setting
by `sync_scheduled_backup()` (`wpthistle-backup-restore.php`) — called on every
boot and immediately after a settings save, so a schedule change takes
effect without waiting for the next page load. 'Weekly' is a custom
recurrence registered via the `cron_schedules` filter (WP core only
ships hourly/twicedaily/daily).

The handler, `run_scheduled_backup()`, reuses `Job_Orchestrator::create_job()`
as-is — including its JOB-05 stale-lock reap — so a scheduled run
automatically clears out a genuinely abandoned previous job before
starting, and correctly skips (no-op) rather than overlapping if a
healthy backup is still legitimately in progress. Same WP-Cron
pseudo-cron limitation as everything else here (only fires on site
traffic) until JOB-02 (real server-cron) lands. Default is Disabled
specifically so activating or updating the plugin never silently starts
recurring jobs an admin didn't ask for. Regression test:
`tests/e2e/run-e2e-scheduling.sh`.

## Retention (Phase 2, 2026-10-20)

Settings page has a "Keep last N backups" number field, default **0 =
keep every backup forever**. A positive value deletes the oldest
backup folder(s) under `<site-id>/backups/` on Nextcloud once more
than that many exist, right after each new backup verifies
successfully (`Job_Orchestrator::maybe_prune_old_backups()`, called
from `step_finalize()` — only ever after the job above it is already
`verified`). Deletion is oldest-first by `backup_id` (timestamp-
prefixed, so lexicographic order is chronological order); the
just-finished backup is always the newest and therefore always
survives, by construction. A pruning failure is logged as a warning
but never flips a verified backup back to failed — same
never-affects-job-status discipline as the diagnostic job-log upload.
Default is 0 specifically so no plugin update or activation can ever
silently start deleting existing backups. Only a folder matching the
real `backup_id` format *and* carrying a `COMPLETE.json` counts as a
verified backup for both the keep-N count and as a deletion candidate
(the follow-up security audit, 2026-10-20, RETENTION-2) — a failed/interrupted
job's folder is neither counted (so it can't silently consume one of
the N "keep" slots a real backup should occupy) nor deleted (cleaning
up non-backup remnants is a separate concern). Regression test:
`tests/e2e/run-e2e-retention.sh` (against a real, isolated Nextcloud
test folder — proves `list_children()` and `Retention::prune()` both
against the real server, not a mock, including a failed-job stub that
must be neither counted nor touched, and a real end-to-end run through
`step_finalize()` itself, not just a direct call to `prune()`).

**Request cost (the security re-verification, 2026-10-20):** the COMPLETE.json
check above adds one PROPFIND (Depth: 0) per candidate folder under
the backups parent, so a pruning run now costs N+1 HTTP requests (1
Depth:1 listing + N Depth:0 existence checks) instead of 1. Each is
small and cheap, but scales with however many backup folders exist at
prune time (only ever once per successful backup, not per request).
Not a timeout concern at realistic backup counts; worth knowing if
retention is ever configured with a very large keep count on a very
slow Nextcloud server.

**Confirmed against the real Nextcloud test server, 2026-10-20**: a
WebDAV `DELETE` lands in that Nextcloud account's own trashbin
(`/remote.php/dav/trashbin/<user>/trash/`), not permanent erasure —
verified live by deleting a probe file and finding it there via
PROPFIND. A retention-pruned backup is therefore recoverable from the
Nextcloud side (by that account, or a Nextcloud admin) for however
long that server's trashbin retention/quota policy keeps it — a
server-side setting this plugin has no visibility into or control
over. Meaningfully reduces the blast radius of a mistaken or malicious
`retention_count` setting (see `docs/threat-model.md` T13), but don't
rely on this as the only safety net: a server configured with trashbin
disabled, or one whose retention window has already passed, has none
of this protection.

## Incremental file backups (Phase 2, 2026-10-20)

Off by default ("Incremental file backups" checkbox on the settings
page). When on, a file whose size and modified time exactly match the
immediately-previous chain backup is referenced rather than
re-archived and re-uploaded — real speed-up on a site with many
mostly-unchanged files (measured: on this project's own ~8,500-file,
~82MB test fixture, file archiving/export alone was ~97% of total
backup time even after the Phase 1 `Archive_Writer` fix, confirming
this stayed the single biggest remaining lever before building it).
The database is **never** incremental — always a full `mysqldump`-style
export every single run, full or incremental file mode alike.

**Not a diff/patch chain.** Every backup's `manifest.json` has a
`files` array that is always a *complete, resolved* index of every
current file — not a delta relative to its parent — each entry naming
`source_backup_id`/`source_part`: wherever that file's actual bytes
physically live, whether that's this backup's own freshly archived
part or an earlier chain member's part carried forward verbatim. A
deleted file simply has no entry (it won't be in that run's
filesystem walk), so restoring from the latest manifest alone can
never resurrect it — no separate deletion-tracking needed. See
`class-backup-chain.php`'s docblock for the full design rationale;
`docs/restore-manual.md` step 1a covers the manual restore procedure
this implies (fetch each distinct referenced source part once,
extract only the needed entries).

**Fails closed to a full backup, never guesses or fails the backup.**
Before trusting any chain state, `Backup_Chain::plan()` re-verifies
against the real server: the previous chain backup's `manifest.json`
must still be fetchable, and every part it transitively references
must still exist (a PROPFIND per distinct referenced part). Any
failure anywhere in that check — local chain-state option missing
(fresh install, migration), the chain's own max depth or max age
exceeded, the parent manifest gone or unreadable, a referenced
ancestor part deleted — falls back to doing a full backup for that
run instead, decided once up front in `step_init()` before any file
is skipped (never discovered to be unsafe partway through
`export_files`, by which point skipped files would be unrecoverable
for that run). A full backup is also forced periodically regardless of
any failure: every `wpthistle_backup_incremental_max_chain` backups (7,
filterable) or `wpthistle_backup_incremental_max_chain_days` days (7,
filterable), whichever comes first — this bounds the (size, mtime)
unchanged-check's known blind spot (a file whose content changed but
whose modified time was preserved, deliberately or incidentally, by
whatever wrote it) to at most one chain's worth of exposure.

**Chain-aware retention.** `Retention::prune()` (above) now computes,
for every backup within the keep-N window, the full set of
`source_backup_id`s its own `manifest.json` depends on, and never
deletes any backup in that set — regardless of how old it is by name.
A backup that's fully superseded (nothing newer still references it)
remains freely prunable even if it sits between two protected backups
in the chain. Regression test: `tests/e2e/run-e2e-incremental.sh`
proves, against the real Nextcloud test server, that an unchanged file
is never re-uploaded at all (not merely logically marked unchanged —
checked by confirming zero `files.part-*.zip` objects exist for a
no-change incremental run), a changed/new/deleted file is handled
correctly across a 3-backup chain, a real byte-for-byte restore
reconstruction from the final manifest alone succeeds by pulling from
whichever backup each file's bytes actually live in, and chain-aware
retention protects a backup a kept one still depends on while
reclaiming one that's fully superseded.

## One-click restore (Phase 2, 2026-10-20)

**RST-04.** Built with the owner's explicit approval given the original
brief's own gate on this specific feature ("no one-click destructive
restore" at MVP, requiring "threat review and explicit owner approval"
before any build work) — see STATUS.md for that decision. Restores
this site, **in place**, from a chosen backup — either picked from the
configured Nextcloud destination, or a single uploaded `.zip` bundle
(a self-contained/full backup only; an incremental chain needs the
Nextcloud source to reach its ancestor backups). The upload path has
no Nextcloud dependency at all — `ajax_start_restore()` never checks
`Settings::has_credentials()` for it, and nothing in that path ever
touches `Nextcloud_Client` — so a site can be restored from a
downloaded bundle even with no Nextcloud connection configured, or a
connection that's since broken, lost its credentials, or been
rotated. Reuses the exact same
job-state-machine shape backups use (same table, same status
vocabulary, same `try_lock()`/`release_lock()` mutual exclusion via
`Job_Store`), so a restore in progress blocks a new backup from
starting and vice versa automatically.

CLI equivalent (fixed 2026-10-22 — previously CLI `restore` only ever
pulled from Nextcloud, with no counterpart to the admin UI's upload
path at all):
```
wp wpthistle-backup-restore restore <backup_id> [--confirm-url-mismatch]
wp wpthistle-backup-restore restore_local <path-to-zip> [--confirm-url-mismatch]
```
`restore_local` extracts the given zip and drives the restore exactly
like an uploaded bundle would (`Restore_Orchestrator::extract_bundle_zip()`
is the one shared implementation both paths call), so it has the same
zero-Nextcloud-dependency property — pairs naturally with
`run --destination=download`'s local bundle path above.

**Two-phase design** (the only way this was judged safe to build at
all): `restore_acquire` and `restore_preflight` are entirely
read-only with respect to the live site — fetches and integrity-
verifies everything (part-level sha256 for this backup's own parts,
per-file sha256 where available, size-bound checks on ancestor parts)
into a staging area before anything live is touched, and runs every
check that can fail safely (home_url mismatch, WordPress version
newer than this core, disk space) before the point of no return.
`restore_apply_db` onward is that point of no return: the database
import goes into **shadow-prefixed tables** (never the live ones
directly), file changes are staged then applied with anything they'd
replace moved into a timestamped rollback directory first (nothing
ever deleted outright), and the final `restore_swap` step is a single
atomic multi-table `RENAME TABLE` — live tables preserved under a
`wpgbak…` prefix, shadow tables promoted to live. A failure at or
after this point leaves clearly-labeled recovery artifacts rather
than attempting automatic rollback, which would be its own large
source of new bugs — the job log names the exact rollback table
prefix and rollback files directory.

**Hard exclusions, never touched under any circumstance** — found
live, during this feature's own E2E testing against a real production
backup:
- **This plugin's own directory.** An older backup predates files
  this plugin has added since (a real, demonstrated failure: an older
  backup's file list simply doesn't mention `class-update-checker.php`,
  so the ordinary "live but not in the backup -> quarantine" rule
  would otherwise move the plugin's own currently-running code out
  of place mid-restore, breaking its autoloader). Plugin code updates
  are a separate, independent mechanism (`Update_Checker`).
- **`wp-config.php`.** RST-04's own explicit directive. The backup's
  copy carries the *source* site's DB credentials (redacted only of
  the plugin's own constant-override credential, nothing else) —
  restoring onto the same site, these are already correct and must
  never be replaced.

**Known, expected, and not a bug:** a real backup's restored content
can include active third-party plugins that write to `wp-config.php`
themselves as part of their own normal operation (confirmed live: a
security plugin — WP 2FA — legitimately writes its own random
encryption key into `wp-config.php` the first time it runs genuinely
active again after restore). This has nothing to do with the restore
mechanism, which never touches that file itself — it's the same thing
that plugin would do on any WordPress site. The regression test
checks the hash immediately after the restore completes, before any
later, unrelated page load can trigger this, specifically to keep
this distinction clean.

**Quarantine, not deletion.** A live file not part of the backup
being restored is moved into the rollback directory, never deleted.

**Settings preserved across the restore.** The plugin's own Nextcloud
connection settings, `site_id`, incremental-chain state, and the
retention-error notice option are captured before `restore_swap` and
re-applied via `update_option()` immediately after — otherwise the
dump's own `wp_options` table (which only ever excludes the single
`Settings::OPTION_KEY` row, nothing else plugin-related) would
silently replace them wholesale.

**Checking "this backup is from a different site" means migration,
not merely "proceed anyway" (Phase 2.5, 2026-10-21).** Earlier in
Phase 2, that checkbox only suppressed the `home_url` mismatch refusal
below — the restored site was left thinking it was still the backup's
original site, which meant every URL it generates for itself (login
redirects, form actions) pointed at the old domain, confirmed live
during real cross-site restore testing: logging into the *new* site's
wp-admin kept bouncing back toward the *old* site's login screen.
Checking the box now runs a full migration step
(`restore_migrate_urls`, `DB_Migrator`) between `restore_apply_db` and
`restore_apply_files`: every text column of every table under the
restore's own shadow-table prefix is scanned, and every occurrence of
the backup's own `home_url` — both its recorded scheme and the
opposite one, to also catch any pre-TLS-migration links the backup's
own content might still carry — is rewritten to this site's own
`home_url`, before anything is promoted live. A naive byte-level
find/replace would corrupt any PHP-serialized value (widget settings,
several core options) whose replacement text is a different length
than the original, since a serialized string carries an explicit
byte-length prefix a naive replace never updates — every value is
checked for being serialized first, and if so, unserialized, walked
recursively (replacing only string leaves/keys), and re-serialized,
which recomputes every length prefix correctly, rather than ever
touching the serialized bytes directly. Deliberately scoped to
literal, scheme-qualified URL occurrences only, never a bare hostname
— replacing a bare hostname would also mangle an unrelated plain-text
mention of the same domain (an email address, a quoted sentence),
which a URL occurrence never would. A table with no usable
single-column primary key is skipped (logged, not guessed at) rather
than risk an ambiguous `UPDATE` targeting the wrong row. A no-op
whenever the backup's own `home_url` already matches this site's
(the ordinary same-site restore, and restoring onto a fresh install at
the same, now-dead domain the backup came from) — unchanged behavior,
confirmed by the existing real-backup E2E suite staying green.
Leaving the box unchecked, as always, restores this backup only onto
the exact site it came from, refusing outright on any `home_url`
mismatch. See `tests/e2e/run-e2e-restore-migration.sh`.

**Found live, by the existing real-backup regression test, not a synthetic one:**
a real site's own Action Scheduler data (used by WooCommerce and many
other plugins) stores a serialized object (`ActionScheduler_IntervalSchedule`)
whose class isn't loaded at this point in a restore. `unserialize()`
safely falls back to a `__PHP_Incomplete_Class` stand-in for that --
but PHP forbids writing a property on one outright (a fatal error),
which the first version of this feature did anyway, crashing the
whole migration on any real site with Action Scheduler installed.
Fixed by calling `unserialize()` with `allowed_classes => false`
(makes that stand-in the guaranteed outcome for every object,
regardless of which classes happen to be loaded at this exact moment)
and by never attempting to write to one -- it survives the migration
completely unchanged, a narrow and documented limitation (a URL
embedded inside such an object's own property isn't migrated) rather
than a crash.

Regression test: `tests/e2e/run-e2e-restore.sh`, run against a real
backup from the owner's own production site (not a synthetic
fixture), restored in place onto a disposable WordPress install with
the plugin genuinely active and running on it.

**security audit round (findings fixed before ship):** the first build
(commit `6a652d2`) came back BLOCKED. Fixes, briefly:
- The path-exclusion check above now normalizes a path (splitting on
  separators, dropping `.`/empty segments, case-insensitive compare)
  *before* comparing it against the plugin-directory/`wp-config.php`
  exclusions — the un-normalized exact-string comparison let
  `./wp-config.php` or a doubled separator leading into the plugin's
  own directory bypass them. Proven directly (reflection-based test
  against the actual private method) in
  `tests/unit/RestoreNeverTouchedPathTest.php`.
- Large per-job data (the manifest, target-file list, quarantine
  candidates, DB statement list) no longer lives in the DB-backed
  checkpoint column — each is written to a per-job disk-backed "blob"
  file instead, with the checkpoint holding only small cursors/counts.
  A large site's checkpoint could otherwise silently exceed
  `max_allowed_packet`, and a failed write would make the *next*
  `advance()` call reload a stale checkpoint and potentially
  re-execute an already-applied destructive step. Every checkpoint
  write's return value is now checked; a failed write stops the job
  rather than risking that.
- The restore job now has a WP-Cron fallback (`wp_schedule_single_event`
  on creation), matching backups — it previously had none at all, so
  closing the browser tab mid-restore left it stuck.
- Every AJAX/WP-Cron batch is a genuinely fresh WordPress bootstrap
  (unlike the CLI path, which runs the whole restore in one
  continuous process) — if an earlier batch had already quarantined
  or replaced part of a still-"active" third-party plugin's files, the
  very next bootstrap could fatal loading it, stalling the restore
  with no way forward. Fixed by temporarily restricting live
  `active_plugins` to just this plugin during the file-apply phase,
  restored on any failure. Proven directly, not just argued:
  `tests/e2e/run-e2e-restore-ajax-safety.sh` engineers a synthetic
  plugin whose live version depends on a file an older backup
  predates, then drives the restore from that backup through roughly
  a dozen genuinely separate `wp eval` processes (each its own fresh
  bootstrap), asserting zero intermediate fatals.
- The DB-rewrite step's identifier substitution is now quote/escape
  aware (`DB_Exporter::replace_outside_strings()`) rather than a plain
  `str_replace()`, which could have corrupted a string literal value
  happening to contain the same backtick-quoted substring (e.g. a post
  discussing `wp_posts` by name).
- The upload-bundle holding directory is now a random per-upload
  token with explicit cleanup on both the success and failure paths,
  replacing a fixed, predictable, never-cleaned-up path that exposed
  an uploaded DB dump and `wp-config.php` indefinitely on a
  misconfigured web server.
- Several smaller fixes: a swap step now fails explicitly rather than
  silently "verifying" with nothing actually swapped if the
  shadow-table list came back empty; a table name used in a `LIKE`
  pattern is now escaped; an uploaded bundle's own `.htaccess` no
  longer overwrites the deny-all guard after extraction; a restore
  job's lock is now reaped before checking for a running job.

Full unit suite (86/86) and the entire existing E2E suite re-run after
this round with zero regressions.

## Updates (Phase 2, 2026-10-20)

This plugin isn't on the WordPress.org directory, so wp-admin's normal
"update available" nag is instead driven by `Update_Checker`, which
polls `github.com/ItsMNLOP/wpthistle-backup-restore`'s Releases (public repo,
read-only, no credentials needed) through WP's own
`pre_set_site_transient_update_plugins`/`plugins_api` filters — the
same mechanism WordPress.org-hosted plugins use, so "Update now" on
the Plugins page works exactly as it would for any other plugin.

An update is only ever offered when the release has an explicit
`.zip` asset attached (name starting with `wpthistle-backup-restore`) — never
GitHub's own auto-generated zipball, which contains the whole repo
(docs, tests, CI config), not just the plugin folder, and would get
installed as-is if used. No qualifying asset means no update offered,
full stop — see `docs/releasing.md` for how a release gets that asset
attached. Checked at most every 12h (cached in a transient); a
failed/missing-release check caches for 1h so a flaky GitHub or a
release-less period (true today — no release has been cut yet) doesn't
trigger a real outbound request on every admin page load. Regression
test: `tests/e2e/run-e2e-update-checker.sh` (runs against the real
public GitHub API for the no-release-yet case, and against a seeded
transient for the offer/no-offer logic itself).

## Disk space

No preflight disk-space check ships in MVP (NFR-10 mentions it; deferred).
A failed export due to disk exhaustion surfaces as a `failed` job with a
redacted PHP warning in the log, not a clean "out of disk" message yet —
known gap, not a silent-success risk (the job still correctly fails
rather than reporting success).
