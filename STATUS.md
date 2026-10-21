# STATUS — WPGuard Nextcloud Backup plugin

## Phase 3 candidates (not yet scoped, for later discussion)

- Nextcloud-free backup: let a site with no configured Nextcloud
  destination still create a backup and download it straight to the
  browser, rather than requiring Nextcloud credentials just to run
  one at all. Raised during real-world Phase 2 testing: restore
  already works with zero Nextcloud dependency when driven from an
  uploaded bundle (see docs/operations.md, "One-click restore"); the
  backup side has no equivalent today — `ajax_start_backup()` requires
  `Settings::has_credentials()` unconditionally, and the local staging
  directory is always deleted (`cleanup_tmp()`) once a backup
  finishes, success or failure, so nothing persists locally either
  way. Not scoped or started.
- Other WebDAV-compatible destinations (ownCloud, OpenCloud, and
  similar): today's `Nextcloud_Client`/OCS app-password flow is
  Nextcloud-specific, but the actual transport is plain WebDAV
  (PUT/PROPFIND), which ownCloud and OpenCloud both also speak, since
  they share Nextcloud's lineage. Likely means a destination
  abstraction (pluggable transport/auth, not a Nextcloud-only client)
  rather than a one-off addition. Phase 3 or 4, once base features are
  settled. Not scoped or started.

## Phase 2 progress — one-click restore: security re-verification CLEAR TO SHIP, 2026-10-20

The security audit independently re-verified every HIGH/MEDIUM/LOW finding from the
audit below against commit `cd816a1`, re-ran the full unit suite
(86/86) themselves, and judged the new AJAX-safety E2E test adequate.
**Verdict: CLEAR TO SHIP.** One new LOW found during re-verification —
not security-impacting, user-recoverable: if the single checkpoint
write that persists `captured_active_plugins` (right after capturing
and restricting `active_plugins` at the start of the file-apply phase)
itself failed, `fail()`'s own DB-reload wouldn't see that value yet,
so a failure at that exact moment would leave every other plugin
deactivated with no recorded way back. Fixed immediately — that write
is now itself checked, restoring `active_plugins` directly and
failing the job if it can't persist, rather than proceeding with an
unrecorded restriction. Unit suite re-run clean (86/86) after this
fix. One-click restore is now shippable.

## Phase 2 progress — one-click restore: the security audit findings fixed, awaiting re-verification, 2026-10-20

the full security audit of the first build (commit `6a652d2`, see the entry
below) came back **BLOCKED**: 4 HIGH, 3 MEDIUM, 4 LOW findings. Per
The owner's standing instruction, moved forward with fixes automatically
rather than waiting for a go-ahead:

- **HIGH-PATH-1** — `is_never_restored_path()` compared paths by
  exact string before normalizing, so `./wp-config.php` and a doubled
  separator leading into the plugin's own directory both bypassed the
  "never touched" exclusions this plugin's whole restore-safety
  argument rests on. Fixed with `normalize_relative_path()`
  (segment-split, drop `.`/empty segments, rejoin single-separator,
  case-insensitive compare) — proven directly via reflection in the
  new `RestoreNeverTouchedPathTest.php`, not just argued.
- **HIGH-CHECKPOINT-1** — large per-job data (manifest, target-file
  list, quarantine candidates, DB statement list) lived in the
  DB-backed checkpoint column, risking silent `max_allowed_packet`
  truncation on a large site; a failed write would make the next
  `advance()` reload a stale checkpoint and could re-execute an
  already-applied destructive step. Fixed by externalizing all of
  these to per-job disk-backed blob files (checkpoint now holds only
  small cursors/counts), and every checkpoint write's return value is
  now checked, failing the job outright rather than risking
  re-execution.
- **HIGH-AJAX-1** — the restore's AJAX/browser path had no WP-Cron
  fallback at all (backups already had one). Fixed —
  `wp_schedule_single_event` on job creation, plus `.catch()` retry
  handlers on the JS poll loops instead of silently halting on a
  network blip.
- **HIGH-AJAX-2** — every AJAX/cron batch is a genuinely fresh
  WordPress bootstrap, unlike the CLI path's one continuous process;
  a third-party plugin mid-way through having its files
  quarantined/replaced could fatal the very next bootstrap, stalling
  the restore with no way forward. Fixed by temporarily restricting
  live `active_plugins` to just WPGuard during the file-apply phase,
  restored on any failure. The security audit explicitly asked this be proven via
  separate fresh-bootstrap processes, not just the CLI's single
  process — built `tests/e2e/run-e2e-restore-ajax-safety.sh` for
  exactly that: a synthetic plugin engineered to require a file an
  older backup doesn't have, restored via ~12 separate `wp eval`
  processes each a fresh bootstrap, asserting zero intermediate
  fatals. Passes.
- **MEDIUM-RENAME-1** — the DB-rewrite step's plain `str_replace()`
  on backtick-quoted table names could have corrupted a string
  literal value containing the same substring (e.g. a post discussing
  `wp_posts` by name). Fixed via a new quote/escape-aware
  `DB_Exporter::replace_outside_strings()`.
- **MEDIUM-UPLOAD-1** — the upload-bundle holding directory used a
  fixed, predictable, never-cleaned-up path, exposing an uploaded DB
  dump + `wp-config.php` indefinitely on a misconfigured web server.
  Fixed via a random per-upload token with explicit cleanup on both
  success and failure paths.
- **MEDIUM-RENAME-2** — the rollback-rename before overwriting a live
  file didn't check its own return value, so a failure there could
  silently proceed to overwrite the live file with no rollback copy.
  Fixed — now fails the job instead. (This is what the new AJAX-safety
  test's ownership bug first surfaced in testing — a real rename
  failure now correctly stops the job rather than continuing blind.)
- **LOW-SWAP-1 / LOW-LIKE-1 / LOW-HTACCESS-1 / LOW-RACE-1** — a swap
  step that could silently "verify" with nothing actually swapped if
  the shadow-table list came back empty; a raw table name used
  unescaped in a `LIKE` pattern; an uploaded bundle's own `.htaccess`
  overwriting the deny-all guard after extraction; a lock-reaping race
  on restore job creation. All fixed.

Full unit suite: 86/86 passing (added `RestoreNeverTouchedPathTest.php`,
expanded `ArchivePathSafetyTest.php` and `SplitStatementsTest.php` for
the fixes above). `tests/e2e/run-e2e-restore.sh` re-run end to end
against the same real production backup, fully green after this large
a restructuring (blob externalization, cursor-based consumption,
active-plugins isolation, upload-token flow) — confirms no regression.
New `tests/e2e/run-e2e-restore-ajax-safety.sh` passes, directly proving
HIGH-AJAX-2's fix. Full existing E2E suite re-run for regression
confirmation — all 10 green, zero regressions: main drill, cancel-job,
cancel-lock-contention, const-override, incremental, lock-race,
retention, scheduling, stale-lock-recovery, update-checker.

Docs updated: `docs/threat-model.md` T20 now documents the security audit's findings
and fixes inline, not just the original design. Not yet committed —
re-dispatching the security audit for re-verification before shipping, per established
pattern (audit → fix → re-verify → repeat until CLEAR TO SHIP).

## Phase 2 progress — one-click restore built, two self-caught bugs fixed, awaiting the security audit, 2026-10-20

Sixth Phase 2 item, and the highest-stakes one in the whole project:
one-click, in-place restore (RST-04). The original brief treats this
differently from the rest of Phase 2 — explicitly listed as an MVP
non-goal and gated on "threat review and explicit owner approval"
before any build work, separate from the general autonomy already
granted. Flagged this distinction to the owner before starting rather than
building on an assumed scope; his answer: build it, test with real
backup copies already accessible on cloud.tomcat.casa, restored onto
the local disposable stack — never against a live DB until proven
there first. That's exactly how this was built and tested.

Design came out of an advisor consult: two-phase (verify-and-stage,
entirely read-only, before the point of no return; then apply —
shadow-prefixed DB import, atomic multi-table `RENAME TABLE` swap,
quarantine/rollback directories instead of deletion), because an
in-place restore's tightest constraint is that it can destroy its own
runtime.

Tested against a REAL backup from the owner's own production site
(mikelautner.com, via the dedicated Nextcloud test account on
cloud.tomcat.casa — confirmed it holds real backup folders from
earlier real-world testing), restored onto a disposable,
freshly-provisioned WordPress install with the plugin genuinely
active and running on it (never onto wp-source, the stable fixture
other E2E scripts depend on). This surfaced two real, previously-
undetected self-destruction risks that synthetic test fixtures would
never have caught:

1. Restoring an older backup quarantined the plugin's OWN
   currently-running files (ones added since that backup was taken,
   like `class-update-checker.php`) — the ordinary "live but not in
   the backup" quarantine rule applied to the plugin's own source
   broke its autoloader mid-restore.
2. The backup's own `wp-config.php` silently overwrote the target's
   DB credentials with the source site's.

Both fixed as hard exclusions (`Restore_Orchestrator::is_never_restored_path()`)
— the plugin's own directory and `wp-config.php` are never touched by
a restore, checked at both planning time and again at the actual
write site. Also found and fixed two real test/environment gaps along
the way: `Inventory`'s exclusion patterns didn't cover the restore's
own tmp/upload staging directories (same self-reference class of bug,
one step earlier); and the docker-compose `wpcli` service was missing
a plugin bind-mount for its `html-restore` path entirely, so an
emptied test volume made every plugin class simply vanish for that
service. One apparent third bug (`wp-config.php` changing within the
full test script's own run) turned out not to be a bug at all after
careful isolation: the real backup's own content includes the active
"WP 2FA" plugin, which legitimately writes its own encryption key
into `wp-config.php` the first time it runs genuinely active again
after restore — normal third-party plugin behavior, nothing to do
with the restore mechanism, which never touches that file. The test
now checks the hash immediately after the restore completes,
specifically to keep that distinction clean.

62/70 -> 70/70 unit tests (new `SplitStatementsTest.php` for the
SQL-dump statement splitter the DB-apply step needs, since `$wpdb->query()`
only ever runs one statement and shelling out to the `mysql` CLI was
rejected as unavailable on a lot of real shared hosting), the full
`tests/e2e/run-e2e-restore.sh` suite (plugin settings/site_id/jobs-row
preserved, wp-config.php untouched by the restore itself, pre-existing
non-backup file quarantined not deleted, rollback tables preserved,
no leftover shadow tables, real content restored and queryable), and
the complete existing E2E suite (main drill, incremental, retention,
scheduling, stale-lock, update-checker) all green with no regression.
Sent to security audit before this is considered shippable — not self-certified,
and given the stakes, reviewed more heavily than anything else in
this project so far, not less.

## Phase 2 progress — incremental backups CLEAR TO SHIP (final), 2026-10-20

Third the security re-verification: **CLEAR TO SHIP**, no new findings. Traced
every commit point in `step_export_files()` line by line (the skip
path and the archive path both now flush into `resolved_files` only
at the same two confirmed-successful-close points; no path persists
`files_remaining` while anything sits uncommitted), confirmed
`restore-manual.md` step 1a's `wp-config.php` exclusions are
syntactically correct in both the extraction and verification loops
and that step 6's reconciliation procedure makes sense entered via
1a's new item 4a, confirmed the size-check and the two-phase test
fix, independently re-ran the full 62-test unit suite and the
incremental E2E suite (both green), and did a dedicated adversarial
pass specifically hunting for a THIRD instance of the
"persist-before-durable" bug class across `step_export_files()`,
`step_package_manifest()`, and `step_finalize()` — found none.

**Incremental file backups (Phase 2 item 5) is done** — three full
audit/fix rounds (HIGH-ZIP-1/MEDIUM-ZIP-2/MEDIUM-RESTORE-1
BLOCKED→fixed; re-verification found NEW-HIGH-1 in the fix itself
plus MEDIUM-RESTORE-2→fixed; final re-verification CLEAR TO SHIP with
an explicit adversarial hunt for a repeat). The most heavily audited
item in Phase 2 so far, proportionate to being the one feature where
getting it wrong means a "verified" backup that silently can't be
restored.

**Phase 2 status: five items fully shipped and security-audit-cleared** — JOB-05
automatic stale-lock recovery, automatic scheduling, retention
pruning, the self-hosted update feed, and incremental file backups.
Remaining, not yet started: one-click restore, WebDAV v2 chunking,
the CI bash port. Multisite remains parked (2026-10-20 decision).

## Phase 2 progress — incremental backups the security re-verification: NEW-HIGH-1 found, fixed, 2026-10-20

the security re-verification of the HIGH-ZIP-1 fix found the fix itself had
introduced a new gap of the exact same class (**NEW-HIGH-1**): the
unchanged-file fast path in `step_export_files()` committed
`files_remaining` immediately, which could already be missing an
earlier-in-batch *changed* file still uncommitted in the archive
path's pending list — a crash in that window (most plausibly during
`close()` itself) would persist a checkpoint where that file exists
in neither list. Fixed by routing the skip path through the same
deferred-commit mechanism as the archive path. Also found
**MEDIUM-RESTORE-2**: `wp-config.php` is a normal entry in every
manifest's `files[]` list, so step 1a's generic extraction loop would
have blindly overwritten a restore target's `wp-config.php` with the
*source* site's DB credentials on any incremental restore, bypassing
step 6's RST-04 reconciliation entirely. Fixed — `wp-config.php` now
explicitly excluded from step 1a's loops, routed through step 6's
existing procedure instead. Two LOWs also fixed:
`verify_own_parts_contain_claimed_files()` now also checks archived
size, not just presence; the HIGH-ZIP-1 regression test's rollback
assertion was conditionally skippable, rewritten as an unconditional
two-phase proof. 62/62 unit tests and the full incremental/retention/
scheduling/stale-lock/main-restore E2E suites re-verified green.
Second re-verification sent for security audit.

## Phase 2 progress — incremental backups security audit returned BLOCKED, fixed same-day, 2026-10-20

the first security audit of incremental backups came back **BLOCKED** with a
real HIGH and MEDIUM, both proven (not theoretical): **HIGH-ZIP-1** —
`ZipArchive::close()`'s return value was discarded entirely, and
proven live that libzip rolls back the *entire* pending batch (every
file since the last successful close, not just the one that
triggered it) if any one added file's source disappears or becomes
unreadable before `close()` runs — the checkpoint had already
recorded those files as archived, so a job could reach `verified`
with files silently missing. This is exactly the "looks complete,
isn't" failure mode called out as the worst case for a backup plugin.
**MEDIUM-ZIP-2** — a part holding only zero-byte files after a
size-rollover could never get finalized/uploaded/restored.
**MEDIUM-RESTORE-1** — the manual restore procedure for incremental
chains had a filename-collision risk and no warning against the one
mistake that would resurrect deleted files. Plus 3 LOWs.

All fixed same-day per standing instruction: `close_part()` now
returns `true|WP_Error`, checked everywhere; `step_export_files()`
restructured so nothing is committed to the checkpoint until the
`close()` backing it is confirmed successful; a new pre-upload
invariant check (`verify_own_parts_contain_claimed_files()`) verifies
every claimed file is actually inside its claimed part before any
upload begins; zero-byte-only parts now correctly finalize (tracked
via a new pure, directly unit-tested `should_finalize_trailing_part()`
rather than a filesystem-iteration-order-dependent e2e repro);
`docs/restore-manual.md` step 1a rewritten with collision-safe
naming, an explicit resurrection warning, and a per-file sha256
verification step. 62/62 unit tests (including a new test that
reproduces HIGH-ZIP-1's exact libzip rollback behavior live, not
assumed) and the full incremental/retention/scheduling/stale-lock/
main-restore E2E suites all re-verified green. Sent back for
re-verification before this is considered shippable.

## Phase 2 progress — update feed CLEAR TO SHIP (final), 2026-10-20

Second the security re-verification of the L-001 regex fix: **CLEAR TO SHIP**,
no new findings. Independently tried to break the new anchored regex
(URL-encoded/mixed traversal, query-string/fragment injection, null
bytes, Unicode lookalike slash characters, ReDOS, case-folding) and
confirmed each is blocked; confirmed the regex doesn't falsely reject
a real semver tag or release-candidate shape (`v1.2.3-rc.1`, etc.);
independently re-ran the full 57-test unit suite and all 7
update-checker E2E cases, both green. **Update feed (Phase 2 item 4)
is done** — four full audit/fix rounds (H-001 BLOCKED→fixed,
re-verification found L-001 still BLOCKED→fixed, final re-verification
CLEAR TO SHIP), the most heavily audited item so far, proportionate to
being the first feature in this project to introduce a remote code-
execution trust boundary.

## Phase 2 progress — update feed re-verification: H-001 cleared, L-001 still BLOCKED, fixed, 2026-10-20

the security re-verification of the H-001/L-001 fixes: **H-001 confirmed
cleared** (and caught a misstatement in our own commit message along
the way — the `Update URI:` header doesn't actually stop this
plugin's slug from being sent to `api.wordpress.org`; `check_for_update()`'s
`unset()` is the real defense, confirmed live by replaying a mocked
WP.org squatter response through the real `wp_update_plugins()`).
**L-001 was still BLOCKED**: the `strncasecmp()` prefix check on the
package URL was proven bypassable with a `../` path-traversal tag
segment — confirmed live with a real HTTP 302 to an unrelated public
repo's release asset. Fixed same-day: replaced the prefix check with a
full anchored regex (tag segment must start alphanumeric, no
`%`/`?`/`#`/`@`/`\`/`/` in either segment, `\z` not `$` to block a
trailing newline). 6 new unit tests, 1 new E2E case (the exact
proven-live bypass). 57/57 unit tests and the full 7-case update-checker
E2E suite re-verified green. Full detail: `docs/threat-model.md` T18.

## Phase 2 progress — incremental file backups built, awaiting the security audit sign-off, 2026-10-20

Fifth Phase 2 item: incremental file backups, opt-in (default off).
Before writing any code, measured the real bottleneck first (per
advisor guidance) rather than assuming the 1.0.0 CHANGELOG's claim
still held after the `Archive_Writer` fix shipped in the same
release: ran a real backup against an ~8,500-file/~82MB scaled-up
fixture and confirmed `export_files` is still ~97% of total runtime,
upload itself now trivial. Confirmed the lever is real before building
it.

Design (not a diff/patch chain — every manifest's new `files` field is
the complete resolved current index every time, so a deleted file has
no entry and can never be resurrected on restore) and the
retention/chain interaction (naive oldest-first pruning would delete a
chain's base first, since it's always oldest — fixed by making
`Retention::prune()` compute and respect the full transitive
dependency set of every kept backup) both came out of an advisor
consult before writing code, specifically flagging that retention-
breaks-chain is the default outcome of a naive implementation, not an
edge case, and that a silent partial restore is the worst possible
failure mode for a backup plugin. `format_version` bumped 1 -> 2
(global, not per-manifest-flag-only, so old restore tooling fails
closed on ANY new-shape manifest, full or incremental). New
`Backup_Chain` class decides full-vs-incremental once up front in
`step_init()`, re-verifying the previous chain backup's manifest and
every part it transitively references against the real server before
trusting any of it — falls back to a full on any gap found, never
guesses, never fails the backup outright. Forces a full periodically
regardless (7 backups or 7 days, both filterable) to bound the
(size, mtime) unchanged-check's known blind spot.

9 new unit tests (Settings' new setting, `format_version` backward-
compat). New `tests/e2e/run-e2e-incremental.sh` against the real
Nextcloud test server: builds a real 3-backup chain (full, no-change
incremental, change+delete+add incremental), confirms on the real
server that a no-change incremental uploads literally zero
`files.part-*.zip` objects (not just a logical "unchanged" marking),
confirms a deleted file is absent from the manifest entirely, performs
a real byte-for-byte restore reconstruction by walking the final
manifest alone and pulling each file from whichever backup it actually
lives in, and confirms chain-aware retention protects a backup a kept
one still depends on while reclaiming one that's fully superseded.
51/51 unit tests, the incremental E2E suite, and the existing
retention/scheduling/stale-lock-recovery/main-backup-restore E2E
suites all re-verified green (the retention E2E fixtures needed a
real manifest.json added, since the new chain-aware logic reads one
for every kept backup — a gap caught during this verification pass,
not shipped). Sent to security audit before considering this shippable — higher
integrity stakes than any prior Phase 2 item, since getting backup/
restore correctness wrong is the worst possible failure for a backup
plugin. Full detail: `docs/operations.md` ("Incremental file
backups"), `docs/threat-model.md` T19, `docs/restore-manual.md` step 1a.

## Phase 2 progress — update feed security audit returned BLOCKED, fixed same-day, 2026-10-20

The security audit of the update feed came back **BLOCKED**: a real, externally
exploitable HIGH (H-001) — the plugin had no `Update URI:` header, so
WordPress core would still query `api.wordpress.org` for the unclaimed
`wpguard-nc-backup` slug; a squatter registering that slug there could
have had their malicious package offered as a legitimate update to
every site running this plugin, with no GitHub or database access
needed. Proven exploitable as written, not theoretical. Plus one LOW
(L-001): a cached/API-sourced package URL wasn't checked against this
repo's own release path before being trusted. Both fixed same-day per
standing instruction: added the `Update URI:` header; `check_for_update()`
now strips any pre-existing transient entry under this plugin's
basename before deciding whether to replace it; `plugin_info()` returns
a `WP_Error` instead of passing through a prior filter's (potentially
WP.org's) data when no release is found; added
`Update_Checker::is_trusted_package_url()` (prefix match against this
exact repo's release-download path), applied at cache-read and both
emission points. 5 new unit tests, 2 new E2E cases (E: simulated
pre-existing WP.org entry correctly stripped; F: cached different-repo
package URL correctly never offered). 48/48 unit tests, the full
update-checker E2E suite (6 cases), and the full backup/restore E2E
drill all re-verified green. Re-sent for security audit for re-verification before
considering this shippable — not self-certified. Full detail:
`docs/threat-model.md` T18.

## Phase 2 progress — update feed built, awaiting the security audit sign-off, 2026-10-20

Fourth Phase 2 item: self-hosted update feed, built as GitHub Releases
on the now-public repo (decided with the owner earlier — public repo, no
credentials needed). New `Update_Checker` class hooks WP's normal
`pre_set_site_transient_update_plugins`/`plugins_api` filters. Design
is fail-closed: never offers an update unless the release has an
explicit `.zip` asset attached, specifically because GitHub's own
auto-generated zipball contains the whole repo (docs/tests/CI config),
not just the plugin folder, and would be wrong to install even absent
any attacker. 9 new unit tests (pure logic: version comparison, asset
selection, release-JSON parsing) and a new E2E test
(`tests/e2e/run-e2e-update-checker.sh`) covering a real call against
the live public GitHub API (no release exists yet — confirmed that's
handled as a clean no-op, not an error) plus the offer/no-offer logic
through WP's real transient filter chain. 43/43 unit tests green.
Documented in `docs/operations.md` ("Updates"), a new
`docs/releasing.md` (manual release process until the CI bash port can
automate it), and `docs/threat-model.md` T18 — this is a
higher-blast-radius feature than the other three shipped so far (an
update source is, by nature, a path to full code execution on every
site running the plugin), so it's being sent for security audit before being
considered shippable, per standing practice, rather than self-certified.

## Phase 2 progress — retention re-verified, CLEAR TO SHIP, 2026-10-20

The security audit's independent re-verification of the retention fixes below: all
four RETENTION-* findings confirmed fixed by direct source read AND a
live run against the real Nextcloud test server (not taken on word) —
**CLEAR TO SHIP**. Found two further non-blocking items, both fixed
same-day: the regression test's Case E only checked for the *absence*
of an error, which can't distinguish a real success from a different
silently-caught failure landing in the same log line (the same shape
that let the original arity bug through) — tightened to require the
explicit "Retention: removed..." success phrase and to fail outright
on either caught-error phrase, with two deterministic fixtures now
seeded under the real backups folder so a real deletion is guaranteed
rather than possibly landing in the ambiguous no-op case. Also
documented the retention fix's own added request cost (one PROPFIND
per candidate folder, for the COMPLETE.json check) in
`docs/operations.md`. 34/34 unit tests and the retention E2E test
re-verified green.

## Phase 2 progress — scheduling CLEAR TO SHIP; retention was BLOCKED, now fixed, 2026-10-20

the follow-up security audit of commits `07ab212` (scheduling) and `6a85eb8`
(retention): **scheduling CLEAR TO SHIP, no findings.** **Retention
was initially BLOCKED** — caught two implementation bugs that had
silently disabled it entirely in production (`maybe_prune_old_backups()`
called `Retention::prune()` with the wrong argument count; `prune()`'s
declared `: array` return type didn't match its `WP_Error` return —
both PHP 8 errors silently swallowed by the calling try/catch, so
retention never actually ran despite a direct-call-only test of
`prune()` passing), one HIGH (the folder-listing self-exclusion check
compared URL-encoded paths and silently broke for any Nextcloud
username containing a character PHP's `rawurlencode()` escapes but
Nextcloud's own encoder doesn't — e.g. `@` — meaning the parent
folder's own name could leak into the deletion candidate list), and
one MEDIUM (failed/interrupted job folders were counted toward the
keep-N total instead of being ignored, so fewer real backups survived
than configured). All four fixed same-day per standing instruction.
The regression test (`run-e2e-retention.sh`) now includes a real
end-to-end run through `step_finalize()` itself with retention
configured — not just a direct call to `prune()` — specifically
because that's exactly how the arity bug hid from the original test.
Also confirmed live, not assumed: a WebDAV `DELETE` on the real
Nextcloud test server lands in that account's own trashbin, not
permanent erasure — meaningfully reduces (doesn't eliminate) the
blast radius of a malicious/careless `retention_count` setting.
34/34 unit tests and the full backup/restore E2E drill re-verified
green. Full detail: `CHANGELOG.md` "Unreleased" → "Fixed",
`docs/threat-model.md` T13.

## Phase 2 progress — retention pruning shipped, 2026-10-20

Third Phase 2 item complete: a "Keep last N backups" setting (default
0 = keep all) that deletes the oldest backup folder(s) on Nextcloud
once more than N exist, right after a new backup verifies
(`Job_Orchestrator::maybe_prune_old_backups()`, called from
`step_finalize()`, best-effort and never affects job status on
failure). New `Nextcloud_Client::list_children()` (PROPFIND Depth: 1)
and a small `Retention` class (`prune()` takes the parent path as a
parameter rather than hardcoding the site's real backups folder, so
it's directly testable against an isolated folder). 3 new unit tests
and a new E2E regression test `tests/e2e/run-e2e-retention.sh` — both
passed against a real, isolated Nextcloud test folder (not a mock):
`keep=0` is a true no-op, `keep=N` deletes exactly the oldest excess,
`keep` higher than what exists is a no-op, and the real remote
listing after pruning independently confirms the result. 34/34 unit
tests and the full backup/restore E2E drill re-verified green
afterward. Full detail: `docs/operations.md` ("Retention").

## Phase 2 progress — JOB-05 security re-audit fixes, 2026-10-20

the follow-up security audit of the stale-lock reaper came back **CLEAR TO SHIP**
with 3 LOWs and 2 non-blocking observations, all fixed same-day per
standing instruction: corrected the `STALE_LOCK_SECONDS` comment and
`docs/operations.md`'s claim that it's "comfortably above" the
worst-case `advance()` call (it understated the real worst case by
~10x; the advisory lock, not the timestamp margin, is the actual
protection), added a 60s floor on the `wpguard_nc_stale_lock_seconds`
filter, added `docs/threat-model.md` T17 for the new unattended-cron
surface, and made the hourly safety-net cron reconcile on every
`boot()` so an in-place plugin update doesn't silently lose it.
Full findings and fixes: `CHANGELOG.md` "Unreleased" → "Fixed".

## Phase 2 progress — automatic scheduling shipped, 2026-10-20

Second Phase 2 item complete: a Disabled/Daily/Weekly dropdown on the
settings page, backed by a real recurring WP-Cron event
(`wpguard_nc_scheduled_backup`) kept in sync with the stored setting by
`sync_scheduled_backup()` — called on boot and immediately after a
settings save. 'Weekly' registered as a real custom cron recurrence
(604800s) since WP core doesn't ship one. The handler reuses
`create_job()` as-is, so a scheduled run gets the JOB-05 stale-lock
reap for free and correctly skips rather than overlaps a healthy job
still in progress. Default is Disabled (no silent auto-start on
activation/update). New unit tests (3, in `SettingsValidationTest.php`)
and a new E2E regression test `tests/e2e/run-e2e-scheduling.sh`
(disabled→daily→weekly→disabled reschedule proven against the real
WP-Cron array; run_scheduled_backup() starts a job when none is
running and correctly skips when one already is) — both passed. 31/31
unit tests and the full backup/restore E2E drill re-verified green
afterward. Full detail: `docs/operations.md` ("Automatic scheduling").
Not yet sent for security audit — see CHANGELOG.md "Unreleased".

## Phase 2 progress — JOB-05 automatic lock-expiry shipped, 2026-10-20

First Phase 2 item complete: `Job_Orchestrator::reap_stale_lock()`
automatically marks a genuinely abandoned job `failed` once its
checkpoint has gone untouched past a threshold (600s default,
filterable), without racing a job whose advisory lock is actually still
held by a live process. Wired into `create_job()` (applies the instant
anyone next tries to back up) plus a recurring hourly WP-Cron safety
net. The existing manual "Cancel / Reset" button stays as an immediate
human override. New regression test
`tests/e2e/run-e2e-stale-lock-recovery.sh` (fresh job left alone; stale
job reaped; a job whose lock is genuinely held by a real concurrent
process correctly left alone however stale its clock looks, then reaped
once that lock frees up) — passed against the live disposable stack.
28/28 unit tests and the full backup/restore E2E drill re-verified green
afterward (this touches `create_job()`, the entry point for every
backup). Full detail: `docs/operations.md` ("Stale-lock recovery").
Not yet sent for security audit for sign-off — see CHANGELOG.md "Unreleased".

## Scope decision — multisite parked, 2026-10-20

Multisite support pulled off the active Phase 2 list and parked in
backlog. No multisite install exists to build or test against (none of
the owner's current WordPress sites are multisite), and inventing the
network-DB/per-site-table/file-scoping behavior without a real target
to verify against isn't acceptable per this plugin's own
verify-before-build standard. Revisit only if the owner stands up a
real multisite target, or a public-repo issue asks for it.

## Phase 1 MVP: COMPLETE — 2026-10-20

Every original Phase 1 gate below is checked and re-verified multiple
times, including against real production data (the owner's production site) rather
than only synthetic fixtures — real install, real connection, repeated
real backups, a real restore with the owner's own account and 2FA state
confirmed intact. Two full security audits, both **CLEAR TO
SHIP**, zero open HIGH/MEDIUM findings. Real-world bugs found via hands-on
testing (ZIP packaging separators, stuck-job UI visibility, WP-Cron
reliability, the actual file-count performance bottleneck) were all
found, fixed, and verified live, not just theorized. 28/28 unit tests,
full E2E drill, green.

**One remaining operator action, not code**: revoke the dedicated
Nextcloud test account's standing read-share on the real backup folder
once no longer needed for testing (security-audit LOW-C2).

**Explicitly out of Phase 1, now Phase 2 scope** (not gaps, by design):
real server-cron trigger (JOB-02), automatic lock-expiry (JOB-05 —
`cancel()` is a manual stopgap, not the real fix), incremental/delta
backups (the one lever left for the file-count-bound performance floor
found 2026-10-20), a self-hosted update feed, scheduling/retention/
one-click-restore/multisite, CI actually executing (written, never run).

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

## Second optimization round, delegated — 2026-10-20

The owner delegated choosing and implementing the next round directly (3m17s
total, down from ~17 min, after the zip-handle fix below). Picked two
safe, no-new-attack-surface changes:

- **Skip DEFLATE for already-incompressible extensions** (jpg/jpeg/png/
  gif/webp/avif/ico/bmp/mp4/mov/avi/webm/mkv/mp3/ogg/wav/flac/zip/gz/bz2/
  rar/7z/pdf/woff/woff2) via `ZipArchive::CM_STORE` — real CPU savings
  for near-zero size cost on those files; text-ish assets (css/js/svg/
  etc.) are deliberately left DEFLATEd since they're small, compress
  fast, and actually shrink. New unit test
  `tests/unit/ArchiveWriterCompressionTest.php` proves both the right
  compression method gets applied *and* -- more importantly -- that
  stored and deflated entries both extract byte-identical (a correctness
  bug here would corrupt every JPEG/video/PDF in every backup).
- **`STEP_TIME_BUDGET_SECONDS` raised 2.0 → 5.0** — the real per-step log
  showed the per-batch archive open/close was still a measurable share of
  `export_files` time even after the previous fix; fewer, bigger batches
  amortize it further. Still well inside shared-hosting execution limits.

Neither touches security-relevant surface (no new external calls, same
lock/checkpoint discipline as already security-audit-reviewed) — no re-audit
dispatched for this round. 28/28 unit tests (3 new), full E2E drill
green.

## Real bottleneck found and fixed: zip handle reopened per file — 2026-10-20

The diagnostic `job-log.json` feature (above) immediately paid for
itself: a real backup's per-step timestamps showed `export_db` took ~3
seconds, `upload_parts` (52MB up, plus a full re-download to verify) took
~3-4 seconds, and **`export_files` took ~16 minutes 37 seconds** — nearly
the entire ~17-minute runtime. Counting the actual archived entries
confirmed why: **8,559 files** across both parts for only ~50MB of
content (mostly small plugin/theme assets, ~6KB average). `Archive_Writer::add_file()`
opened, wrote one file, and closed the ZIP — rewriting its whole central
directory — on *every single file*, which gets slower as the directory
grows. With 8,559 files this dominated the entire runtime; the upload
stage I'd originally suspected was never the bottleneck.

**Fixed**: `Archive_Writer` now exposes `open_part()`/`add_file_to()`/
`close_part()`; `step_export_files()` opens the archive once per batch
(same `STEP_TIME_BUDGET_SECONDS` batching from the earlier perf fix),
reopening only on the rare 64MB part-rollover boundary, and closes once
before returning to the AJAX/cron caller — still no handle held open
*across* separate requests (ADR 0003's actual requirement), just not
reopened needlessly within one. Per-file checkpoint persistence is
unchanged. 25/25 unit tests and the full E2E drill green.

## the follow-up security re-audit (commits 93052f3..c9a30bc) — 2026-10-19

The security audit reviewed all four commits since the last sign-off (ZIP packaging fix,
batching, resume-on-load UI, and especially the new Cancel/Reset job
capability). **Verdict: CLEAR TO SHIP.** No HIGH/MEDIUM findings. Two
LOWs:

- **LOW-C1**: `cancel()` reported success to the admin even when
  `advance()` held the H-01 lock and nothing actually changed. **Fixed
  same day** (commit `e1ba495`): `cancel()` now tags its outcome
  explicitly, and the AJAX handler sends an error response on
  `lock_contended` instead of a silent no-op success. Verified live via a
  new regression test, `run-e2e-cancel-lock-contention.sh` (genuine
  concurrent holder + canceller processes).
- **LOW-C2** (operator hygiene, not a code finding): the dedicated
  Nextcloud test account's standing read-share on the real backup folder
  should be revoked once no longer needed for testing. Flagged to the owner
  directly.

The security audit confirmed by direct code trace: `cancel()` cannot be triggered by a
non-admin/no-nonce request, cannot corrupt a job genuinely mid-advance
(respects the H-01 lock), never touches anything already uploaded to
Nextcloud, and `public_job_view()`'s response shape is unchanged (no new
disclosure).

## New: diagnostic job-log.json uploaded alongside every backup — 2026-10-19

The owner's request, for the ongoing performance investigation — the admin UI
only ever showed the last 10 log entries, and WP-CLI's `status` command
doesn't print the log at all, so there was no way to see real per-step
timestamps after the fact. `Job_Orchestrator` now uploads the full
per-step log as `job-log.json` next to every backup (both the success and
failure paths), best-effort — upload failure here never affects job
status. Every entry already passes through `Logger::redact()` at write
time, same exposure as what's already visible in the admin UI. Not part
of `manifest.json`'s `components[]`, not part of the restorable backup
set — pure diagnostics. Verified live: 21 real timestamped entries
confirmed present and correct after an E2E run. Commit `e1ba495`.

## Correction: the second backup did not actually stall — 2026-10-19

Reassessed after the owner spotted `files.part-*.zip` had appeared on
Nextcloud with a recent timestamp. Direct WebDAV check (test account)
confirmed `COMPLETE.json` present — the job finished and passed its own
internal verification. Actual timeline: folder created (step_init)
23:51:35, `COMPLETE.json` written 00:53:53 — **~62 minutes total**, not a
permanent stall, just ~3x slower than backup #1's ~20 minutes. This ran
entirely on the pre-batching plugin build with no admin tab open, so
progress depended solely on WP-Cron's pseudo-cron, which only advances
when something happens to hit the site — real idle gaps between bursts,
not a steady 5-second cadence, explaining the elapsed time without
needing an actual stall. The earlier "genuinely stalled" conclusion was
asserted before checking Nextcloud for a second time and was wrong; the
Cancel/Reset feature built in response is still a legitimate, needed fix
for the case where a job really doesn't resume, but this specific
instance resolved on its own.

## Real-production restore drill — 2026-10-19

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

**The owner's own confirmation, browsing the restored instance himself**: his
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

## Security audit — 2026-10-19

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
- **The security audit's two re-verification conditions were met** (2026-10-19, same
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

- **the final security sign-off (2026-10-19): CLEAR TO SHIP** for
  testable/controlled deployment, consistent with this document's own
  standing caveats (CI not yet executing, scheduling/retention/
  one-click-restore are Phase 2, JOB-05 manual clear is a known
  limitation). Verified by independent line-by-line code review of
  commits `0310976`, `4cee50c`, `31068b6` — not taken on the implementer's word.
  Surfaced two non-blocking advisories (LOW-R1: fail-closed check in
  case the wp-config.php redaction regex ever misses a credential;
  LOW-R2: document a MySQL `wait_timeout`/lock-reconnect edge case) —
  both fixed same day, commit `fa12bf5`, 25/25 unit tests and all E2E
  drills re-verified green.
