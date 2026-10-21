# Changelog

All notable changes to this project are documented here.

## [Unreleased] — Phase 2.5, in progress

### Changed
- **One-click restore's "this backup is from a different site" checkbox
  now means migration, not merely "proceed anyway".** Previously it
  only suppressed the `home_url` mismatch refusal; the restored site
  was left thinking it was still the backup's original site, so every
  URL it generated for itself (login redirects, form actions) pointed
  back at the old domain -- confirmed live during real cross-site
  restore testing as a genuine inability to complete a browser login
  on the new site. Checking the box now runs a new
  `restore_migrate_urls` step (`DB_Migrator`) between
  `restore_apply_db` and `restore_apply_files`, still against the
  shadow tables only, still before anything is promoted live: every
  scheme-qualified occurrence of the backup's own `home_url` (and its
  opposite-scheme variant, to catch pre-TLS-migration links the
  backup's own content might still carry) is rewritten to this site's
  own `home_url`, across every text column of every table.
  Serialization-safe -- a value is unserialized, walked recursively
  (replacing only string leaves/keys), and re-serialized rather than
  ever touched at the byte level, since a naive replace would corrupt
  any PHP-serialized value (widget settings, several core options)
  whose replacement text is a different length than the original.
  Deliberately scoped to literal URL occurrences only, never a bare
  hostname, which would also mangle an unrelated plain-text mention of
  the same domain. A table with no usable single-column primary key is
  skipped and logged rather than risk an ambiguous `UPDATE`. A no-op
  whenever the backup's own `home_url` already matches this site's --
  unchanged behavior for the ordinary same-site restore, confirmed by
  the existing real-backup E2E suite staying green. See
  `docs/operations.md` ("Checking... means migration"),
  `docs/threat-model.md` T20, and the new
  `tests/e2e/run-e2e-restore-migration.sh`, which seeds a real
  https:// link, a scheme-mismatched legacy http:// link, a
  PHP-serialized widget option, and a bare-hostname plain-text mention
  all referencing the same old domain, then asserts every URL
  occurrence migrates correctly while the bare-hostname mention
  survives completely untouched. Checkbox label and help text updated
  to state this explicitly.
  - Found live by the existing real-backup regression test, not a
    synthetic one: a real site's own Action Scheduler data (used by
    WooCommerce and many other plugins) stores a serialized object
    whose class isn't loaded at this point in a restore; the first
    version of this feature crashed the entire migration trying to
    write a property on PHP's safe `__PHP_Incomplete_Class` stand-in
    for it, which PHP forbids outright. Fixed with
    `allowed_classes => false` on every `unserialize()` call (makes
    that stand-in the guaranteed outcome regardless of which classes
    happen to be loaded at that moment) and by never writing to one --
    it survives unchanged instead.
  - Sent to the security audit (commit `c7564d6`): BLOCKED, 1 HIGH,
    2 MEDIUM, 1 LOW, every one proven live via direct probes, not
    theoretical review. All four fixed:
    - HIGH: a `C:`-format object (the `Serializable` interface,
      common before PHP 8.1) loses its entire payload through the
      unserialize/reserialize round trip, silently corrupting any
      row containing one. Fixed with a lossless-round-trip guard --
      the value is left completely untouched if re-serializing it
      straight back (no replacement applied) doesn't reproduce the
      original bytes exactly.
    - MEDIUM: plain `str_replace()` had no URL-boundary awareness,
      wrongly matching `https://example.com` inside
      `https://example.community` or `https://example.com.evil.net`.
      Fixed with a boundary-aware regex (two negative lookaheads --
      reject a direct domain-continuation character, or a `.`
      followed by another domain-label character, while still
      matching an ordinary sentence-ending period).
    - MEDIUM: the replacement wasn't idempotent when the new URL is
      itself prefixed by the old one (subdirectory migration, e.g.
      `https://example.com` -> `https://example.com/wordpress`) --
      replaying an already-migrated value on a checkpoint resume
      would append the suffix again. Fixed by refusing to generate
      any pair shaped that way at all.
    - LOW: a failed `$wpdb->update()` was silently counted as
      success. Fixed by failing the job outright on any write error.
    New unit tests directly reproduce each of the three fixable-in-code
    findings. 110/110 unit tests; both E2E suites and the full
    existing 9-suite regression set re-run clean.
  - Re-verification (commit `45738a7`): BLOCKED again -- one HIGH
    that was a genuine regression introduced by the MEDIUM-MIG-2 fix
    itself, one MEDIUM pre-existing gap the fix round hadn't touched:
    - HIGH (regression): the subdirectory-migration refusal's bare
      string-prefix check also wrongly refused four safe shapes that
      merely share a long prefix for unrelated reasons -- an
      ordinary scheme-only migration (producing an identity pair the
      check misclassified as unsafe), a port being added, a longer
      but unrelated hostname, and a ccTLD extension. Each got
      silently refused, silently no-opping the whole migration and,
      for three of the four shapes, leaving the restored site's
      siteurl/home pointing at the backup's old host -- broken in
      exactly the way this feature exists to prevent, with the job
      still reporting verified. Fixed by dropping identity pairs
      outright rather than checking them, and by checking
      specifically for a `/` or `?` continuation (an actual
      path/query extension) rather than a bare string prefix. A
      refusal now surfaces as an explicit WP_Error, failing the
      restore at restore_preflight (still read-only) instead of
      silently falling through past the point of no return.
    - MEDIUM (pre-existing): a string leaf inside a serialized
      structure can itself be a serialized value -- WordPress's own
      maybe_serialize() does this deliberately for a caller that
      mistakenly pre-serializes before calling update_option().
      Treating that leaf as a plain string left its own
      length-prefixed encoding stale while only the outer wrapper's
      length got recomputed, corrupting it invisibly until something
      unserializes it a second time. Fixed by having the string-leaf
      case recurse back into the same serialization-aware check the
      top-level value already gets.
    Six new unit tests. 115/115 unit tests; both E2E suites re-run
    clean again.
  - Second re-verification (commit `42e2ccb`): BLOCKED a third time --
    a fresh adversarial pass, not a re-check of what was already
    fixed, found a PHP serialization backreference (`R:N;`) producing
    a genuinely circular reference that unserialize() reconstructs
    faithfully and that survives the HIGH-MIG-1 round-trip guard
    losslessly, so the recursive replace walked it forever -- proven
    live to exhaust PHP's memory limit and fatal. A PHP Fatal error
    isn't a catchable exception and skips every `finally` block, so
    the restore's advisory lock stayed held, unrecoverable without
    manual intervention. Fixed with a depth cap threaded through
    every recursive entry point; the cap alone stopped the fatal but
    initially still returned a differently-shaped (though finite)
    reconstruction rather than the untouched original bytes a
    circular, URL-less value should get back -- corrected by
    unwinding all the way back to the outermost call on a depth-cap
    hit, rather than returning from wherever in the structure the cap
    was reached. One new unit test. 116/116 unit tests; both E2E
    suites and the full existing 9-suite regression set re-run clean.
  - Third re-verification (commit `d1f8c94`): the circular-reference
    fix confirmed working, but found one more related issue (a fresh
    adversarial pass, not a re-check) -- a SHARED (not circular)
    reference, the same array referenced from two different parent
    slots, never exceeds the recursion depth cap on any single path,
    but a plain recursive walk still visits it once per parent,
    doubling total work at every level of sharing. Proven live: an
    18-level-deep version (a few hundred bytes) exhausted a 256MB
    memory limit, same fatal/stuck-lock consequence via a different
    mechanism the depth cap alone can't catch. Fixed with a node-visit
    budget (50,000), independent of depth, reusing the same
    throw/catch-at-depth-0 unwind already built for the depth cap.
    One new unit test (built via real PHP reference assignment, to
    avoid a serialization-format mistake that made the audit's own
    first proof attempt look like a false pass), verified under a
    constrained 128MB PHP memory limit. 117/117 unit tests; both E2E
    suites re-run clean again.
  Pending security audit sign-off before this is considered
  shippable.

## [1.1.0] — 2026-10-20 — Phase 2

### Added
- Automatic stale-lock recovery (JOB-05): a genuinely abandoned backup
  job is now marked `failed` on its own once its checkpoint has gone
  untouched past a threshold (600s default, filterable via
  `wpguard_nc_stale_lock_seconds`), instead of requiring an admin to
  notice and click "Cancel / Reset Stuck Job" (which remains available
  as an immediate manual override). Applied the moment anyone next
  tries to back up, plus a recurring hourly WP-Cron safety net for a
  site nobody visits for a while. Never races a job whose advisory lock
  is genuinely still held by a live process. Security-audit-cleared.
- Automatic backup scheduling: a Disabled/Daily/Weekly dropdown on the
  settings page, backed by a real recurring WP-Cron event kept in sync
  with the stored setting. Reuses `create_job()`'s JOB-05 stale-lock
  reap, so a scheduled run clears a genuinely abandoned previous job
  before starting and skips rather than overlaps a healthy one still in
  progress. Defaults to Disabled so no plugin update or activation ever
  silently starts recurring jobs. Security-audit-cleared.
- Retention pruning: a "Keep last N backups" setting (default 0 = keep
  all) that deletes the oldest backup folder(s) on Nextcloud once more
  than N exist, right after a new backup verifies. Oldest-first by
  `backup_id` order among verified backups only; the just-finished
  backup always survives by construction. A pruning failure is logged
  but never flips a verified backup back to failed. New
  `Nextcloud_Client::list_children()` (PROPFIND Depth: 1) and
  `Retention` class, verified against a real Nextcloud account via
  `tests/e2e/run-e2e-retention.sh`. Defaults to keep-all since pruning
  is destructive and must be an explicit admin opt-in.
- One-click restore (RST-04): built with the owner's explicit approval given
  the brief's own gate on this specific feature. Restores this site,
  in place, from a backup chosen either from the configured Nextcloud
  destination or a single uploaded `.zip` bundle. Two-phase design:
  `restore_acquire`/`restore_preflight` are entirely read-only
  (fetches, integrity-verifies, and runs every failable check before
  anything live is touched); `restore_apply_db` onward is the point of
  no return, importing into shadow-prefixed tables and swapping in via
  one atomic multi-table `RENAME TABLE`, with the previous live tables
  renamed aside (never dropped) and any replaced/quarantined files
  moved into a rollback directory (never deleted). The plugin's own
  directory and `wp-config.php` are hard exclusions, never touched
  under any circumstance — both were real, demonstrated self-
  destruction risks found live during this feature's own testing
  against a real production backup, now closed. The plugin's own
  Nextcloud settings/site_id/chain state are captured and re-applied
  after the swap; its own job-tracking table is never imported at
  all. See `docs/operations.md` ("One-click restore") and
  `docs/threat-model.md` T20. New `tests/e2e/run-e2e-restore.sh`,
  verified against a real backup from the owner's own production
  site, restored onto disposable infrastructure only. Security-audit-
  cleared — the highest-stakes feature in this project, reviewed
  accordingly.
  - The full security audit of the first build came back BLOCKED (4 HIGH, 3
    MEDIUM, 4 LOW); all fixed — path-exclusion normalization (a
    bypass via `./wp-config.php` or a doubled separator into the
    plugin's own directory), externalizing large per-job state out of
    the DB-backed checkpoint into disk-backed blobs with cursor-based
    consumption (closing a stale-checkpoint re-execution risk against
    a destructive step), a WP-Cron fallback for the restore job
    (backups already had one, restore didn't), temporarily isolating
    `active_plugins` to just WPGuard during the file-apply phase so a
    third-party plugin mid-replacement can't fatal the next AJAX/cron
    batch's fresh WordPress bootstrap, a quote-aware SQL identifier
    rewrite (plain `str_replace` could have corrupted a string literal
    value), and a randomized/auto-cleaned upload-staging directory
    (the old fixed path exposed a DB dump + `wp-config.php`
    indefinitely on a misconfigured server). New
    `tests/e2e/run-e2e-restore-ajax-safety.sh` directly proves the
    fresh-bootstrap-per-batch fix by driving a real restore through
    ~12 separate processes against a plugin engineered to fatal on
    exactly that gap. Full unit suite and entire E2E suite re-run,
    zero regressions.
- Incremental file backups: an opt-in "Incremental file backups"
  setting (default off) that skips re-archiving/re-uploading a file
  whose size and modified time match the previous chain backup,
  referencing it instead. Not a diff/patch chain — every manifest's
  new `files` field is always the complete, resolved current file
  index, so a deleted file simply has no entry and can never be
  resurrected by a restore. Fails closed to a full backup (never
  guesses, never fails the backup outright) whenever the previous
  chain backup's manifest or any part it depends on can't be
  re-verified against the real server, decided once up front before
  any file is skipped; also forces a full periodically regardless
  (every 7 backups or 7 days, both filterable). `format_version`
  bumped 1 -> 2 so older restore tooling refuses a manifest shape it
  doesn't understand rather than silently restoring a partial file
  set. `Retention::prune()` is now chain-aware: never deletes a
  backup any kept backup still depends on, verified live (not just a
  direct-call test) by the new `tests/e2e/run-e2e-incremental.sh`,
  which also proves an unchanged file is never re-uploaded at all (not
  merely logically marked unchanged) and that a full byte-for-byte
  restore reconstruction from the final manifest alone succeeds
  across a real 3-backup chain. See `docs/operations.md` ("Incremental
  file backups"), `docs/threat-model.md` T19, and `docs/restore-manual.md`
  step 1a. Security-audit-cleared.
- Self-hosted update feed: `Update_Checker` polls the now-public
  `github.com/ItsMNLOP/wpguard-nc-backup` Releases (no credentials
  needed) through WP's normal `pre_set_site_transient_update_plugins`/
  `plugins_api` filters, so "Update now" on the Plugins page works like
  any WordPress.org-hosted plugin despite this one not being listed
  there. Only ever offers an update when the release carries an
  explicit `.zip` asset — never falls back to GitHub's own
  auto-generated zipball, which contains the whole repo rather than
  just the plugin folder. See `docs/releasing.md` for the (currently
  manual, pending the CI bash port) release process and
  `docs/threat-model.md` T18 for the trust-boundary discussion.
  Security-audit-cleared.

### Fixed
- Incremental backups, the security re-verification (2026-10-20): the HIGH-ZIP-1
  fix had introduced **NEW-HIGH-1**, a gap of the same class -- the
  unchanged-file fast path in `step_export_files()` committed
  `files_remaining` immediately, which could already be missing an
  earlier-in-batch changed file still uncommitted in the archive
  path's pending list. Fixed by routing both paths through the same
  deferred-commit mechanism. **MEDIUM-RESTORE-2**: `wp-config.php` is
  a normal `files[]` entry, so step 1a's extraction loop would have
  blindly overwritten a restore target's `wp-config.php` with the
  source site's DB credentials, bypassing RST-04 reconciliation
  entirely on an incremental restore. Fixed. Two LOWs also fixed
  (size check added to the pre-upload invariant check; a test
  assertion made unconditional).
- Incremental backups, the security audit (2026-10-20): **BLOCKED, then fixed
  same-day. HIGH-ZIP-1**: `ZipArchive::close()`'s return value was
  discarded entirely; proven live that libzip rolls back the *entire*
  pending batch (not just the file that triggered it) if any one
  added file's source disappears or becomes unreadable before
  `close()` runs, while the checkpoint had already recorded those
  files as archived -- a job could reach `verified` with files
  silently missing from the uploaded part. Fixed: `close_part()` now
  returns `true|WP_Error`, checked at every call site;
  `step_export_files()` restructured so a file is only ever committed
  into the persisted checkpoint once the `close()` backing it has
  already succeeded. Added `verify_own_parts_contain_claimed_files()`,
  a pre-upload backstop that confirms every file this job claims to
  have archived is actually present inside its claimed part -- the
  existing part-level sha256 read-back could never catch this, since a
  part missing entries still round-trips its own (smaller) bytes
  perfectly. **MEDIUM-ZIP-2**: a part holding only zero-byte files
  (real: empty `.log`/`.gitkeep`/sentinel files) after a size-based
  rollover could read `current_part_bytes === 0` and never get
  finalized/uploaded/restored. Fixed by also tracking a per-part file
  count. **MEDIUM-RESTORE-1**: `docs/restore-manual.md` step 1a had a
  filename collision risk across ancestor parts sharing the same
  `files.part-NNNN.zip` name, no warning against whole-archive
  extraction of an ancestor part (which would resurrect deleted
  files), and no per-file integrity verification instruction. Fixed.
  Three LOWs also fixed: `Backup_Chain::record_result()` re-read
  `base_created_at` from storage instead of using `plan()`'s own
  snapshot; `Retention::prune()`'s fail-closed abort had no visible
  symptom beyond a per-job log line (added a persistent admin
  notice); `Archive_Writer::open_part()` had a dead-code ternary.
- Update feed, the security re-verification (2026-10-20): L-001 was **still
  BLOCKED** after the first fix — the `strncasecmp()` prefix check on
  the package URL was proven bypassable with a `../` path-traversal
  tag segment (confirmed live with a real HTTP 302 to an unrelated
  repo's release asset). Replaced with a full anchored regex. H-001
  confirmed cleared on re-verification.
- Update feed, the security audit (2026-10-20): **BLOCKED, then fixed same-day.**
  H-001 (HIGH, proven exploitable): no `Update URI:` header meant
  WordPress core still queried `api.wordpress.org` for this plugin's
  unclaimed slug; a squatter registering it there could have had a
  malicious package offered as a legitimate update to every installed
  site. Added the header; `check_for_update()` now strips any
  pre-existing transient entry for this plugin before deciding whether
  to replace it; `plugin_info()` now returns an error instead of
  passing through a prior filter's data when no release is found.
  L-001 (LOW): a cached/API-sourced package URL wasn't checked against
  this repo's own release path — added
  `Update_Checker::is_trusted_package_url()`, applied at cache-read and
  both emission points.
- Corrected two the security audit delta-audit documentation findings on the JOB-05
  stale-lock reaper (LOW-JOB05-1/2): `STALE_LOCK_SECONDS`'s "comfortably
  above worst-case" comment understated the real worst case by roughly
  10x once `class-nextcloud-client.php`'s actual per-attempt timeouts
  and retries are counted -- the advisory lock, not the timestamp
  margin, is what actually prevents reaping an active job, now stated
  plainly. Added a `MIN_STALE_LOCK_SECONDS` floor (60s) on the
  `wpguard_nc_stale_lock_seconds` filter (LOW-JOB05-3). Added `threat-model.md`
  T17 for the new unattended-cron surface and removed the now-stale
  "no WP-Cron-triggered unattended runs" out-of-scope line (OBS-2).
  The hourly stale-lock-check cron is now also reconciled on every
  `boot()`, not only on fresh plugin activation, so an in-place plugin
  update doesn't silently lose the safety-net cron until a deactivate/
  reactivate cycle (OBS-1).
- the follow-up security audit of scheduling and retention: **scheduling CLEAR TO
  SHIP, no findings. Retention was initially BLOCKED** — two
  implementation bugs had silently disabled it entirely
  (`maybe_prune_old_backups()` called `Retention::prune()` with the
  wrong number of arguments, and `prune()`'s declared return type
  didn't match its `WP_Error` return path; both are PHP 8
  errors silently caught by the calling try/catch, so retention never
  actually ran despite a direct-call-only test of `prune()` passing)
  plus a HIGH finding (the folder-listing self-exclusion check
  compared URL-encoded paths, which silently broke for any Nextcloud
  username containing a character PHP's `rawurlencode()` escapes but
  Nextcloud's own encoder doesn't, such as `@` — the parent folder's
  own name then leaked into the deletion candidate list) and a MEDIUM
  (failed/interrupted job folders were counted toward the keep-N total
  instead of being ignored). All four fixed; regression test now
  exercises the real `step_finalize()` integration path specifically,
  not just a direct call to `prune()`, so this class of bug can't hide
  again. Also confirmed live against the real Nextcloud test server:
  WebDAV `DELETE` lands in that account's trashbin, not permanent
  erasure — documented in `docs/threat-model.md` T13.

## [1.0.0] — 2026-10-20 (Phase 1 MVP complete)

Two full independent security audits, both **CLEAR TO SHIP**,
zero open HIGH/MEDIUM findings. Verified against real production
WordPress/Nextcloud data (not just synthetic fixtures), including a full
real restore with the owner's own account and 2FA state confirmed intact.

### Fixed
- H-01: concurrent AJAX+WP-Cron job-advance race could mark a
  structurally corrupt backup `verified` — MySQL advisory lock around
  `Job_Orchestrator::advance()`.
- H-02: the constant-override credential path backed up `wp-config.php`
  containing the very credential protecting the backup — redacted before
  archiving.
- M-01/L-01 through L-04: WebDAV redirect following, failure-path temp
  cleanup, streaming archive-bomb abort, XML entity loading, CA-bundle
  size cap.
- ZIP packaging used backslash path separators (a PowerShell
  `Compress-Archive` artifact), which real PHP `ZipArchive` extraction on
  a live host takes literally instead of as a directory separator —
  caused "Plugin file does not exist." on activation on a real site.
- `Archive_Writer` reopened and rewrote the ZIP's entire central
  directory on every single file — the dominant cost of a real ~17-minute
  backup for only ~50MB of content (8,559 files). Now opens once per
  batch.
- Admin settings page showed a blank status box after navigating away
  and back during a running job, indistinguishable from a crashed
  backup.

### Added
- Time-boxed batching for `export_db`/`export_files` — cuts AJAX round
  trips dramatically on file-heavy sites.
- Manual "Cancel / Reset Stuck Job" admin action (JOB-05 partial
  mitigation) for a job the WP-Cron fallback never picks back up.
- Diagnostic `job-log.json` uploaded alongside every backup with the
  full per-step timestamped log.
- Skip DEFLATE for already-incompressible file extensions
  (jpg/png/zip/pdf/mp4/woff2/etc.) in the file archive.

### Known limitations (Phase 2, by design)
- No real server-cron trigger — relies on WP's pseudo-cron, which only
  fires on site traffic.
- No automatic lock-leasing/expiry (JOB-05 proper) — the Cancel action is
  a manual stopgap.
- No incremental/delta backups — every run re-archives every file,
  identified as the primary remaining performance lever.
- No scheduling, retention, one-click restore, multisite, or a
  self-hosted update feed.
- CI workflow written, never executed (no runner connected).

## [0.1.0] — 2026-10-19 (MVP)

### Added
- Manual, on-demand WordPress-to-Nextcloud backup (database + wp-content
  + wp-config.php) over authenticated HTTPS WebDAV.
- Versioned manifest (`format_version: 1`) with per-component SHA-256
  hashes; read-back verification after every upload; `COMPLETE.json`
  completion marker written and verified last.
- Admin settings page: connection fields, Test Connection, Back Up Now,
  live job status/log.
- WP-CLI command `wp wpguard-nc-backup run` / `status`.
- Manual disaster-recovery runbook (`docs/restore-manual.md`).
- Local disposable Docker test stack (WordPress source + Nextcloud +
  clean restore target), TLS via a generated local CA.

### Known limitations (see docs/ for detail)
- Manual backups only — no scheduling, retention, or one-click restore
  (Phase 2+).
- No Nextcloud chunked-upload v2 — upload parts are bounded (default
  64 MiB) but read fully into memory per part.
- No at-rest archive encryption.
