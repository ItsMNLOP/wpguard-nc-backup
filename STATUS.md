# STATUS — WPThistle Backup and Restore plugin (formerly WPGuard Nextcloud Backup, see 2.0.0 entry)

## 2.0.0 rename: WPGuard Nextcloud Backup -> WPThistle Backup and Restore, 2026-10-23

With Phase 2 and Phase 3 both complete, the question came up of
whether "WPGuard" needed reconsidering now that the family menu (built
this session, see the family-hub entry below) was going to host
plugins that have nothing to do with "guarding" anything. Checked
before building anything further: "WPGuard" turned out to collide with
**WP Guard Pty Ltd** (wpguard.ai), an active, incorporated Australian
company selling managed WordPress security/backup services under
essentially the same name, in the same space. "WPGuards"/"WP Guard"
(CodeCanyon firewall plugin) and "WP Guardian" (wpguardian.com, a
WebPros product in the cPanel store) are close enough to add to the
confusion risk. Not urgent while this stayed personal/self-hosted, but
real enough that renaming now -- before more WPThistle-family plugins
exist to drag along -- was clearly cheaper than renaming later.

New family name, picked after several discarded rounds (WPBulwark/
WPWarden/WPCustodian all re-hit the same "guard" theme problem;
WPForge/WPFoundry turned out to be taken too, by an active WP hosting
company and a real desktop WP-admin app, respectively): **WPThistle**
-- the thistle became Scotland's national emblem as a defensive
symbol, which keeps a faint echo of the original "guard" spirit without
committing every future sibling plugin to a security/protection theme.
Checked clean: no WordPress plugin, company, or product under that
name anywhere; domain unregistered.

**Full rename, not just cosmetic.** Plugin slug/folder/main file ->
`wpthistle-backup-restore`; PHP namespace `WPGuard\NCBackup` ->
`WPThistle\BackupRestore`; every `wpguard_nc_*` option/hook/nonce/
transient name, the `WPGUARD_NC_*` constants, the jobs table, the
WP-CLI command, admin menu slugs, and the (zero-consumer, so free)
`wpguard_family_status_cards` filter all renamed to match. GitHub repo
renamed in place (`gh repo rename`, confirmed the old URL still
redirects) rather than a fresh repo, so history/issues weren't lost.
Display name: "WPThistle Backup and Restore" (dropped "Nextcloud" from
the plugin's own name too, in the same pass, now that ownCloud/
OpenCloud/local-download are equally first-class).

**Why this isn't just a find-and-replace on a live site**: a new slug
is, to WordPress, a different plugin. `activate()` now runs
`migrate_from_legacy_wpguard()` first -- if this plugin's settings
option is absent but the old plugin's is present, it copies settings/
site_id/schema-version/chain-state/retention-error-flag across and
renames the old jobs table in place (`RENAME TABLE`, not a copy),
rather than losing backup/incremental-chain history on cutover.
Non-destructive: nothing under the old names is touched beyond that
copy -- the old plugin's own `uninstall.php` (confirmed: it does
`delete_option()` and `DROP TABLE` on actual uninstall, not mere
deactivation) is what would actually lose that data, so the documented
cutover order is deactivate-old -> activate-new (migration runs) ->
verify -> delete-old, never skipping straight to delete.
`WPGUARD_NC_APP_PASSWORD`/`WPGUARD_NC_CA_BUNDLE` wp-config.php
constants still work as a fallback, so an existing site's wp-config.php
doesn't need editing as part of the cutover itself.

**Found and fixed along the way, a real correctness gap the rename
would otherwise have introduced**: restoring a backup taken under the
*old* plugin, post-rename, would silently leave this plugin out of
`active_plugins` afterward -- the backup's own historical
`active_plugins` snapshot (which the DB swap makes live verbatim, by
design, since restoring a backup is supposed to bring back that
backup's actual plugin state) names a slug that no longer exists once
the old plugin's files are gone. Fixed by ensuring this plugin's own
*current* basename is present in `active_plugins` after every
successful restore (merged in, never replacing what the backup
legitimately had active) -- every other plugin's restored state is
untouched, only this plugin's own self-continuity is guaranteed.
Separately, the same rename-the-literal-filename work also fixed the
related self-preservation line `restore_apply_files` already relied on
(`plugin_basename( ...DIR . 'wpguard-nc-backup.php' )` -- had this
single literal been missed, every mid-restore AJAX/cron bootstrap
would have seen active_plugins restricted to a basename that doesn't
match the actually-running plugin, meaning this plugin's own code
wouldn't load at all during its own restore).

**Found live, unrelated to the rename itself, while rehearsing it**:
confirmed via the first full `run-e2e.sh` rehearsal run that
`wp_source()` (every E2E script's own helper, local and CI alike) never
passed `WORDPRESS_TABLE_PREFIX` to its wpcli container, silently
defaulting to `wp_` -- while the real wp-source apache container reads
the deliberately different `wps_` from `.env` (chosen specifically to
catch prefix-handling bugs). The real installed tables were under
`wp_`, meaning wp-source's own apache container had been returning
"not installed" over real HTTP the entire time this project has been
tested -- every E2E proof up to this point went through wpcli only,
which happened to default around the mismatch. Fixed the data side by
renaming the live tables (and the two prefix-embedded keys,
`wp_user_roles`/`wp_capabilities`/`wp_user_level`) to `wps_` rather
than reinstalling, so real job history wasn't lost; fixed the actual
bug everywhere `wp_source()` is defined. `run-e2e.sh` now passes
end to end with the manifest correctly recording `table_prefix: wps_`.

**Three further credential/correctness gaps found live while rehearsing
the actual cutover on real data** (not synthetic fixtures -- the
disposable stack's wp-source instance already had real settings, real
site_id, and real job history from this session's own earlier testing):

- **A real, previously-latent credential leak, predating the rename**:
  a restore job's own checkpoint legitimately holds a `captured_options`
  snapshot with the real app password/app token in it, for as long as
  that job row exists (needed to re-apply settings after the DB swap).
  `DB_Exporter::list_tables()` never excluded the jobs table from export
  at all -- only the settings option *row* was ever excluded -- so any
  backup taken on a site that had ever run a one-click restore archived
  that checkpoint, plaintext credential included, in `database.sql.gz`.
  Caught by `run-e2e.sh`'s own credential-leak check failing after this
  session's restore rehearsal left two restore-job rows behind on
  wp-source. Fixed by excluding the jobs table from export entirely
  (symmetric with restore already never importing it, per the
  pre-existing T20 design) rather than trying to redact specific fields.
  **This is a pre-existing issue, not introduced by the rename**: any
  v1.x site that has ever run a one-click restore has this same
  credential sitting in its own `database.sql.gz` from every backup
  taken since. Flagged for Vex to disclose explicitly; if this site's
  real app password/token was ever in a backup taken after a restore,
  rotate it.
- **The never-touched-path restore protection didn't recognize the OLD
  plugin's own directory.** `is_never_restored_path()` derives its
  exclusion from the *current* `WPTHISTLE_BACKUP_DIR` -- restoring a
  real pre-rename backup tried to write the old plugin's own archived
  files back to `wp-content/plugins/wpguard-nc-backup/...`, which would
  have silently resurrected the old plugin's dead code verbatim had
  that folder still existed on disk (the documented cutover order
  deliberately leaves it there until confirmed). Fixed with an explicit
  legacy-prefix list, checked alongside the current one.
- **`active_plugins` self-continuity across a cross-version restore.**
  Restoring a backup correctly makes that backup's own historical
  `active_plugins` live (the right general behavior) -- but a pre-rename
  backup's own snapshot names a slug that's gone once the old plugin's
  files are. Fixed by merging this plugin's current basename into
  `active_plugins` after every successful restore, never replacing
  what the backup legitimately had active.
- **The wp-config.php redaction only covered the current constant
  names.** `WPGUARD_NC_APP_PASSWORD`/`WPGUARD_NC_CA_BUNDLE` are kept as
  a read fallback (so a site's wp-config.php doesn't need editing as
  part of the cutover) -- but `redact_wp_config_contents()` only ever
  stripped the current names, so exactly the site that fallback exists
  for would have archived its real credential, unredacted, in its
  wp-config.php copy. Fixed to cover both names; both the unit test
  and `run-e2e-const-override.sh` (H-02's original regression test)
  now prove the legacy name redacts correctly too, not just the current
  one.
- Also found and fixed along the way: the jobs table itself can get
  resurrected on restore the same way (a pre-rename backup's own dump
  names it `wpguard_nc_jobs`, which the import-time skip didn't
  recognize either) -- fixed on both the import-skip and export-exclude
  sides. And several E2E scripts hard-coded a `wp_` table prefix for
  wp-source's own jobs table instead of using `$WP_SOURCE_TABLE_PREFIX`
  (`wps_` on this stack) -- silent no-ops behind `|| true`, never
  actually doing anything, until the full suite was re-run and one
  assertion (checking the restore job's own row survives the swap)
  caught it structurally rather than by accident.

156/156 unit tests (153 going in, +3: the two legacy-constant-fallback
cases above, plus one proving the legacy plugin-dir restore exclusion).
Full 16-suite E2E regression re-run clean after
every fix above, including the specific scenario that found them
(restore a real pre-rename backup, then take another real backup).
Full detail on the migration code's own review: see the Vex
security-audit entry immediately following this one once it's run.

## Family hub landing page (top-level "WPGuard" menu 404 fix), 2026-10-23

The top-level "WPGuard" admin menu item had no page callback at all --
clicking it 404'd. `Admin::render_family_hub()` gives it a real page: a
status-card grid any WPGuard-family plugin (now WPThistle-family,
post-rename) can register into via a new, zero-consumer-until-now
filter (`wpguard_family_status_cards`, renamed to
`wpthistle_family_status_cards` in the 2.0.0 rename above), with this
plugin contributing its own card (config state, last-job status,
time-ago). Verified live: rendered via wp-cli against real job data,
then full browser-path HTTP proof (logged into wp-admin, hit
`admin.php?page=wpguard`, got the real hub instead of a 404; the
Nextcloud Backup submenu still worked).

Also dropped "Nextcloud" from every user-facing name/string (plugin
header, readme, admin menu/page titles, update-feed metadata) in the
same pass, since ownCloud, OpenCloud, and local-download are equally
first-class destinations now -- "WPGuard Backup and Restore" instead
of "WPGuard Nextcloud Backup" (further renamed to "WPThistle Backup
and Restore" in the 2.0.0 entry above).

Side find while verifying this live: wp-source's own apache container
had drifted onto the wrong DB table prefix (intended `wps_` per
`.env.example`, actual tables were `wp_`) -- the whole stack's
browser/HTTP path had been silently untestable all session up to this
point. See the table-prefix fix folded into the 2.0.0 entry above for
the full story and the actual root cause (every E2E script's own
`wp_source()` helper never passed `WORDPRESS_TABLE_PREFIX`).

## WebDAV chunking v2 (TRN-04, the last Phase 2 item), CLEAR TO SHIP, 2026-10-23

The last unstarted Phase 2 hardening item. Real protocol tested live
against all three verified backends before any code was written:
Nextcloud 34.0.5 and ownCloud 10.15 both support WebDAV chunking v2
(MKCOL the uploads collection, numbered PUTs, MOVE with
`OC-Total-Length`) identically; OpenCloud 7.2.4 returns 404 on the same
MKCOL, confirmed live, not assumed.

`Nextcloud_Client::upload_part()` now does the real chunked,
resumable upload for anything bigger than one 8 MiB chunk on a server
that supports it, falling back to ADR 0004's original single-PUT path
otherwise (small files, or OpenCloud). Capability is probed live and
cached, not inferred from the `backend` setting -- deliberately, so
this doesn't reopen T22's "`get_backend()` has zero consumers"
finding. Resumability needed zero new checkpoint fields: the
`$upload_id` is deterministic (derived from job_id + the part's own
remote path), so a process killed outright mid-upload still resumes
correctly on the next `advance()` of the same still-running job, via a
real server-side chunk discovery rather than any local counter.

**Proven live, not just argued**: `tests/e2e/run-e2e-chunked-upload.sh`
round-trips a 20MB file byte-identical on all three backends, and
specifically proves resume by planting a deliberately wrong chunk 0
directly on the server before retrying -- the result's first chunk
stays wrong (proving it was skipped, not re-sent) while everything
after it is correct (proving the rest uploaded properly that call).
9 new unit tests (148/148 total). Full existing E2E regression (16
suites) re-run green -- two suites initially failed from this
session's own new test script leaving wp-source's settings pointed at
its own test folder (same pollution bug class a prior session hit with
a `.env` table-prefix), fixed with the same capture-and-restore trap
pattern, both green on retry. Full detail: `docs/adr/0010-webdav-
chunking-v2-resumable-upload.md`, `docs/threat-model.md` T24.

**Vex security audit: initially BLOCKED, one MEDIUM (M-01), two LOW
(L-01, L-02), all fixed same-day.** M-01: the capability probe treated
HTTP 405 the same as 201 ("supported") -- proven live via a mocked
405 that a fresh-random-session probe getting 405 can only mean the
server refuses MKCOL entirely (dormant against all three tested
backends, but broke the probe's own "works against an unknown future
server" claim -- a false "yes" would cache for a day, failing every
backup with a file over 8 MiB repeatedly). Fixed: only 201 means
supported. L-01: this entry's own "bounded to one part" framing was
wrong (database dumps have no part-size cap) and "real deployments
already run their own GC" was only true for Nextcloud, not ownCloud --
corrected here and in the ADR, plus a new ownCloud operator-action
note in docs/operations.md (`occ dav:cleanup-chunks`). L-02: the
chunk-assembly MOVE used a 30s timeout, too short for a very large
database dump; fixed to 300s, matching download_to_file()'s own
timeout for a similarly large operation. 148 -> 153 unit tests (the
405 bug had zero test coverage before -- closed with a new minimal
wp_remote_request() mock in tests/unit/wp-shim.php). Full regression
re-run green after the fixes. Full detail: `docs/threat-model.md` T24.

Also found and fixed, pure test-infra, unrelated to the feature: this
session's own disposable `wp_wpguard_nc_jobs` table had accumulated
~19MB of job-history checkpoint data over a very long day of testing
across many features, exceeding wp-restore's 16MB
`max_allowed_packet` and breaking `run-e2e.sh`'s restore-import step
with "Server has gone away." Fixed by truncating that disposable
table (job history only, not real backup data) -- not a recurring
product concern, just this session's own accumulated test cruft.

## WP-CLI credential-free paths, CLEAR TO SHIP, 2026-10-22

Fixes the CLI gap found while re-checking the Phase 3 candidates list:
`CLI_Command::run()`/`restore()` hard-required Nextcloud credentials
unconditionally, with no CLI counterpart to the admin AJAX path's
`destination=download`/upload-bundle routes. `run --destination=download`
now mirrors `ajax_start_backup()`'s own gating exactly; a new
`restore_local <path-to-zip>` command mirrors the browser's "Upload a
backup bundle" path, sharing its exact extraction logic
(`Restore_Orchestrator::extract_bundle_zip()`, pulled out of
`ajax_upload_restore_bundle()` so this path-safety-critical code has
one implementation, not two that could drift apart) rather than a
second copy of it.

New `tests/unit/RestoreBundleExtractionTest.php` (6 tests: flat
bundle, wrapper-directory stripping, traversal-entry refusal, mixed-
nesting refusal, non-zip rejection, forced-.htaccess-rewrite) --
133/133 -> 139/139 unit tests. Live-verified against the real
disposable stack: a real `--destination=download` backup with zero
Nextcloud configured, then `restore_local` against that exact bundle,
restored successfully. Also found and fixed, pre-existing/unrelated to
this change: the live `wp-restore` container had `wpmb_` baked into
its own `WORDPRESS_TABLE_PREFIX` from 29 hours earlier (a stale
leftover from before `.env` was corrected -- containers don't pick up
an edited `.env` without a recreate), which broke
`run-e2e-download-backup.sh`'s real-HTTP login step until
`--force-recreate wp-restore` was run. Full regression re-run clean
after both fixes: `run-e2e-download-backup.sh`, `run-e2e.sh`,
`run-e2e-const-override.sh`, `run-e2e-restore.sh`,
`run-e2e-restore-migration.sh`, `run-e2e-cancel-job.sh`. Full detail:
`docs/threat-model.md` T23.

**Vex security audit: CLEAR TO SHIP, one LOW (LOW-CLI-HOLD-1)** --
`restore_local()` extracted the full bundle to disk before checking
for a running job, orphaning it indefinitely on lock contention (no
sweeper covers upload-holding dirs). Fixed same-day: the running-job
check now happens first, and the holding dir is cleaned up on every
early-exit path, including one Vex's own writeup incorrectly said
didn't need it (checked `create_restore_job()`'s source directly --
its `''` return means no checkpoint was ever created, so `fail()`
never runs for it either). Fix proven live: a manufactured
lock-contention case now refuses immediately with zero bytes written.
139/139 unit tests, full regression re-run green. Full detail:
`docs/threat-model.md` T23.

## Doc correction — CI status was stale, 2026-10-21

`gh run list` against the real GitHub repo shows CI has actually been
connected and executing on every push for some time (a long failure
streak — cygpath on Linux, missing `docker/.env.example`, healthcheck
gating — now fixed; the last two runs are green). The "written, never
executed / no CI runner connected" language in `AGENTS.md`, `SECURITY.md`,
and `docs/test-matrix.md` (all now corrected) was stale, left over from
early in this project before a runner was actually connected, and never
updated after. Phase 1 gate item 7 below (checkbox record from that
earlier session) is left as-is — a historical record of that session's
own state, not a current claim.

**Still genuinely missing** (the real remainder of "CI bash port",
Phase 2 item 10): auto-building and attaching the release zip to a
GitHub Release on tag push. `docs/releasing.md`'s manual process is
still required for that specific step.

**Phase 3 candidates list below pruned**: both items were already
built and shipped by the time this was re-checked — "other WebDAV
destinations" (ownCloud/OpenCloud, see the multi-backend entry above)
and "Nextcloud-free backup" (see "Download Now" in the entry further
below) both satisfy their own candidate description exactly. Removed
rather than left stale. One real gap found while re-checking the
latter: `CLI_Command::run()`/`restore()` (`class-cli-command.php`
lines 20, 70) still hard-require `Settings::has_credentials()`
unconditionally — the credential-free path only exists on the AJAX
`destination=download` route, not WP-CLI. Not yet scoped or started;
raised to the owner 2026-10-21.

## Phase 3 — multi-backend (ownCloud + OpenCloud), CLEAR TO SHIP, 2026-10-21

Generalizes the backup destination from "Nextcloud" to any server speaking
the same WebDAV dialect. `Nextcloud_Client` needed zero code changes --
it was already a generic adapter with no Nextcloud-specific URL shapes
or headers. The only new surface is `Settings::BACKEND_CHOICES`
(`nextcloud`/`owncloud`/`opencloud`) and the admin UI's wording (which
credential term and error copy is shown); picked up by a prior session,
left uncommitted and undocumented when it was interrupted mid-debug.
This session verified and finished it:

**Verified live against real disposable instances**
(`owncloud/server:10.15`, `opencloudeu/opencloud:7.2.4`, brought up via
the new `docker compose --profile multi-backend` services), via
`tests/e2e/run-e2e-multi-backend.sh`: connection test, full backup,
incremental (remote-manifest GET/parse round-trip), chain-aware
retention (keep-N correctly protects a still-referenced base backup,
reclaims a fully-superseded one -- same scenario `run-e2e-incremental.sh`
already proves for Nextcloud), restore with URL migration, and a real
multi-part PUT/GET roundtrip (an 80MB seeded file, past the 64MB
`PART_SIZE_BYTES` threshold, SHA-256-confirmed byte-identical after a
full backup/restore cycle) -- against both backends. Full detail:
`docs/threat-model.md` T22.

**Three real bugs found and fixed, all test-infrastructure, zero plugin
code changes:**
1. `docker-compose.yml`'s `OWNCLOUD_TRUSTED_DOMAINS` was space-separated,
   but the `owncloud/server` image's own config template does
   `explode(',', ...)` on it -- the whole space-joined string landed as
   one single, never-matching trusted-domain entry, a live "untrusted
   domain" 400 on every WebDAV call. Fixed with a comma-separated value;
   fixing `config.php` directly via `occ` on the already-running
   container did not hold across subsequent `occ` invocations (each one
   re-derived the same broken value from the container's original
   environment), so a `--force-recreate` against the corrected env was
   needed (same data volume, no reinstall).
2. The new E2E script's own retention assertion assumed "keep last 1"
   means exactly one folder remains, not accounting for chain-aware
   retention's documented behavior (`class-retention.php`) of also
   protecting a still-referenced base backup -- the plugin's retention
   logic was correct throughout; this was the new test's own bug.
3. The script's restore step pulled a backup by `backup_id` onto a
   *different* WordPress install (different URL/port, hence a different
   `Settings::get_site_id()`), 404ing under the wrong site folder --
   the plugin's remote-pull restore has always been scoped to the
   current site's own `site_id` by design. Fixed by forcing
   `SITE_ID_KEY` to the source site's id before restoring, same as the
   real cross-site case in `run-e2e-restore.sh`.

**Also found and fixed, test-infra only**: the script's own restore step
changed `WP_RESTORE_TABLE_PREFIX` in `.env` and recreated `wp-restore`
with it, with no cleanup on exit -- left `wpmb_` behind in `.env` after
a run, which broke four unrelated E2E suites' own assumptions the next
time they ran (`run-e2e.sh`, `run-e2e-const-override.sh`,
`run-e2e-download-backup.sh`, `run-e2e-restore-migration.sh` all failed
for this reason alone, not from any plugin or multi-backend code).
Fixed with the same restore-on-exit trap already used for wp-source's
plugin settings. Full regression re-run clean after the fix: all 13
pre-existing E2E suites plus `run-e2e-multi-backend.sh`, 133/133 unit
tests (includes the `backend`-setting tests already written by the
earlier session).

**Also found, separately**: `.e2e-multi-backend-scratch/` (holds a full
dump of `wpguard_nc_settings`, app password included) was never added to
`.gitignore` -- fixed with a `.e2e-*-scratch/` catch-all pattern covering
every current and future per-script scratch dir.

**Overclaim correction**: the prior session's code comments in
`class-nextcloud-client.php` and `class-settings.php` cited
`docs/threat-model.md` T21/T22 and said the multi-backend behavior was
"confirmed live" -- T22 didn't exist yet (T21 is the unrelated
downloadable-backups finding) and no STATUS.md entry existed either.
The claims are true now that this session has actually done the
verification; T22 added, citations corrected to T22 only.

**Known limits**: CI (`ci.yml`) does not run the `multi-backend` compose
profile -- this matrix is exercised manually, not on every push. OpenCloud
is only verified through its legacy `remote.php/dav/files/{user}`
compatibility shim, not its native API. oCIS is untested (likely rides
along, since OpenCloud is its fork, but not confirmed).

**Vex security audit (SOP-023): CLEAR TO SHIP, zero findings.**
Independently confirmed `get_backend()` has no consumer anywhere in the
plugin -- `Nextcloud_Client` and both `redact_wp_config_*()` methods
never read it, so all three backends get byte-for-byte identical
treatment at the transport, credential, and redaction layers. The
`backend` input is double-validated (strict allowlist at both save and
read), so a tampered value can only ever fall back to `'nextcloud'`
wording. One doc-accuracy correction came out of the audit: this
section and the relevant code comments originally described the
`backend` setting as dynamically switching the UI's credential wording
per backend -- it doesn't; the UI already uses backend-neutral combined
wording ("app password / app token") that covers all three without
reading the setting at all, which the audit flagged as *better* (it
doesn't reveal which backend is configured to anyone reading over the
admin's shoulder) rather than worse. Corrected throughout
(`docs/threat-model.md` T22, `class-settings.php`,
`class-admin.php`'s settings-page description) rather than building the
dynamic-wording path that was never actually there. `SSL_CERT_FILE` on
the test-only OpenCloud container confirmed test-infra-only, no effect
on the plugin's own TLS verification (ADR 0007, `NoInsecureTlsTest.php`
unaffected). Full detail: `docs/threat-model.md` T22. Still sitting in
the same uncommitted working tree as the already-CLEAR-TO-SHIP
downloadable-backups/admin-menu work above (batch-commit per the
owner's standing instruction) -- tangled together in one `git diff`,
but both independently CLEAR TO SHIP now.

## Phase 3 progress — downloadable backups CLEAR TO SHIP (final), 2026-10-21

The second security re-verification (round 3 overall): all three round-2
findings confirmed fixed, re-proven live where re-proof was meaningful
(the exact `);`-in-password bypass case re-run standalone and confirmed
gone for both quote styles; the concatenated-define bypass mechanism the
re-verification itself surfaced mid-session confirmed live too). This
session's agent hit a provider-side rate limit partway through and was
resumed with full context rather than restarted — no work lost, no
re-litigation needed.

**One new mechanism found on this pass, assessed and accepted rather than
fixed**: a `define()` value built from string concatenation (e.g.
`define('DB_PASSWORD', 'abc' . 'def');`) defeats both the quoted-string
regex (concatenation isn't a single string literal, so the whole line
passes through unredacted) and the fail-closed `strpos()` re-check (the
runtime-joined value never appears as one contiguous substring in the
source text either — only its two separate pieces do). Deliberately not
fixed, for the same reason the pre-existing, already-accepted `LOW-R1`
gap (`const` syntax) isn't: no real `wp-config.php` from any mainstream
WordPress installer or hosting panel has ever been observed defining a
credential constant this way. Folded into `LOW-R1`'s own test comment
(`tests/unit/SettingsValidationTest.php`) rather than opened as a
separate finding, per the audit's own disposition — that comment
previously slightly overstated how completely the fail-closed backstop
covers every unusual `define()` shape; now accurate about this one too.
Also confirmed: the downloadable-backups feature introduces no new
advisory-lock concurrency surface beyond what `T12`'s existing, pre-dating
documented gap already covers (download-generation steps run through the
same `advance()`/lock mechanism as every other step; the TTL sweep is a
non-state-advancing cron callback with no lock interaction at all).

**Verdict: CLEAR TO SHIP.** Zero open HIGH/MEDIUM findings across all
three audit rounds (the original 9, the 3 from round 2, and this round's
single accepted-not-fixed mechanism). Full regression and unit suite
unchanged from the prior round (130/130 unit tests, all 12 pre-existing
E2E suites plus `run-e2e-download-backup.sh` green) — no code changed
this round beyond the one test-comment correction.

## Phase 3 progress — downloadable backups, re-verification found one new MEDIUM, fixed same-day, 2026-10-21

The security re-verification of the nine findings below (commit from the
first BLOCKED pass): **all nine confirmed fixed**, several re-proven live
(a sentinel `DB_PASSWORD` no longer survives; 5 concurrent `create_job()`
calls now produce exactly 1 job; a swept bundle's checkpoint is confirmed
cleared; the download link's dedicated nonce confirmed rejected against
the wrong action). **A fresh adversarial pass — not a re-check of what was
already fixed — found one new MEDIUM, ship-blocking, plus two LOW:**

- **NEW-MEDIUM-1**: the redaction regex's `.*?` (both the original
  app-password/CA-bundle redaction and the new DB-credential one, same
  shared pattern) is non-greedy — it matches only up to the *first* `);`
  anywhere in the value. Proven live: a credential value containing its
  own literal `);` fragment (plausible — some hosting panels generate
  passwords from the full printable-ASCII set) left everything after that
  point in the archived wp-config.php unredacted. Worse, it simultaneously
  **defeated the fail-closed re-check** added specifically to catch a
  redaction failure: that check does `strpos($redacted, $secret)` against
  the *full* secret string, which is no longer present once part of it
  was consumed by the partial `.*?` match — a redaction failure that
  silently looked like success. Fixed by replacing `.*?` with a real
  quoted-string-literal match (`Settings::quoted_string_value_pattern()`,
  escape-aware, both quote styles, written as a nowdoc specifically to
  avoid manually counting PHP-string-escape-of-regex-escape backslashes)
  in both `redact_wp_config_contents()` and `redact_wp_config_db_credentials()`.
  Two new regression tests reproduce the exact proof case (single- and
  double-quoted) for each function.
- **NEW-LOW-1**: the same class of race as MEDIUM-DL-3, on the restore
  side — `create_restore_job()` had no advisory lock at all around its own
  has-running-job-check-then-insert. Proven live: parallel backup+restore
  create calls produced 2 concurrent active jobs (a download backup
  running alongside a restore). Fixed by extracting the lock name into a
  new `Job_Orchestrator::create_job_lock_name()` and wrapping
  `create_restore_job()` with the same `GET_LOCK`/`RELEASE_LOCK` pair —
  since the lock is a single shared name, this also serializes a backup
  starting against a restore starting, not just two of the same kind.
- **NEW-LOW-2**: the advisory lock name was a bare static string, which
  would contend with an unrelated site sharing the same MySQL server
  under a different database/prefix (common on shared hosting — a
  co-tenant holding the lock for any reason would silently drop this
  site's own backup/restore trigger for up to 5s). Fixed by namespacing
  the lock name with this site's own `site_id` (already generated and
  persisted for exactly this kind of cross-site disambiguation), folded
  into the same `create_job_lock_name()` helper above.

All three fixed same-day. 130/130 unit tests (3 new). Full regression
(all 12 pre-existing E2E suites plus `run-e2e-download-backup.sh`)
re-run clean again — `create_restore_job()` changed this round too, so
the restore-touching suites were back in scope, not just the download
ones. Re-dispatched for a second re-verification before this is
considered shippable.

## Phase 3 progress — WPGuard top-level admin menu + downloadable backups, security audit BLOCKED, fixed same-day, 2026-10-21

Two Phase 3 items built together at the owner's direction: (1) the admin
UI moved from Settings > Nextcloud Backup to a shared top-level **WPGuard**
menu (`Admin::add_menu()`, idempotent shared-parent pattern so future
WPGuard-family plugins land as submenus rather than collecting under
Settings or each getting their own top-level entry); (2) "Download Now" —
a second, independent backup destination, always available regardless of
whether Nextcloud is configured, that packages the same backup into one
flat zip and serves it to the admin's browser instead of uploading it.
Held uncommitted together per the owner's instruction (batch-commit once
all Phase 3 work is done).

Design for the download feature came out of an advisor consult before any
code: `destination` stored as its own top-level `jobs` table column
(schema bumped 1->2) rather than inside the checkpoint JSON blob, since
several step functions replace the whole checkpoint wholesale rather than
merging — a field stored there would have silently vanished the first
time any step ran. New `step_bundle_local()` replaces upload_parts+finalize
for a download-destination job: builds the bundle in the exact same flat
layout (`manifest.json`, `COMPLETE.json`, `database.sql.gz`,
`files.part-*.zip` at the zip root) the *existing* `ajax_upload_restore_bundle()`
already accepts, specifically so a downloaded bundle restores through the
existing upload-restore path with zero new restore code — then reopens the
bundle independently (`ZipArchive::CHECKCONS`) and re-verifies every entry's
size+sha256 by extracting to disk one at a time (never buffering a whole
DB dump in memory, ADR 0004) before the job is allowed to reach `verified`.
Bundle lives in a 128-bit random-token directory nested under the existing
guarded `wp-content/wpguard-nc-backup-tmp/` base (inherits its `.htaccess`
and `Inventory` exclusion automatically). Lifecycle: cap-at-one (a new
download backup removes any still-pending one), 24h TTL sweep (hourly cron
+ on settings-page load), explicit "Delete local copy" button, and
plugin-deactivation purge. New dedicated `admin_post_wpguard_nc_download_bundle`
endpoint streams it (a real navigable GET, not fetch/AJAX, so the browser
doesn't buffer the whole zip in memory first).

New `tests/e2e/run-e2e-download-backup.sh`: drives the whole flow over
*real* authenticated HTTP (not `wp eval`) against the disposable stack's
`wp-restore` target with Nextcloud deliberately left unconfigured — a
no-credentials backup started via the real AJAX endpoint, unauthenticated/
bad-nonce download requests denied, the guarded directory confirmed not
directly web-servable, a real authenticated download, cap-at-one, explicit
delete, TTL sweep, and — the central design claim proven directly, not
just argued — the downloaded bundle fed back through the real, unmodified
upload-restore endpoint and restored successfully. Building this test
surfaced three of its own bugs before the real audit even started: driving
job steps via `wp eval` wrote absolute paths from the wrong container (the
disposable stack's `wpcli` and `wp-restore` containers mount the same
volume at different mountpoints) into the job's own persisted checkpoint —
fixed by driving every step through the real HTTP AJAX endpoint instead,
exactly matching how a real browser/WP-Cron-driven backup behaves; a
Git-Bash/MSYS path-mangling quirk corrupted a raw `curl` multipart
upload's MIME-type argument; and the disposable `wp-restore` target's
*files* (unlike its DB) aren't reset between E2E suites, so this test's
own backup was picking up ~100MB of real leftover content from whichever
suite last restored onto it — fixed with an explicit cleanup step. Also
found the "Upload a backup bundle" restore feature (shipped Phase 2) had
never actually been exercised with a real upload in any E2E suite before
this — PHP's stock `upload_max_filesize=2M` silently blocked it; fixed by
adding `docker/php/uploads.ini` (test-infra only, not a plugin change).

**Sent to the security audit: BLOCKED.** 4 MEDIUM, 5 LOW, two proven live:

- **MEDIUM-DL-1**: the existing wp-config.php redaction only ever stripped
  this plugin's own constants (app password, CA bundle path) — never the
  site's real `DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_HOST`. Proven live: a
  seeded `DB_PASSWORD` survived into the archived wp-config.php unchanged.
  The Nextcloud path's same behavior is a documented, accepted exposure
  (threat-model T9, storage the admin already controls); a downloadable
  bundle leaves that custody model entirely the moment it's saved to the
  admin's own computer. Fixed with a new `Settings::redact_wp_config_db_credentials()`,
  applied only to download-destination bundles, plus the same fail-closed
  re-check pattern as the existing app-password case (refuse to archive
  rather than silently leak if the real `DB_PASSWORD` constant somehow
  survives). Deliberately leaves `AUTH_KEY`/salts untouched — WP 2FA and
  encryption plugins store real recovery material keyed to those values,
  and stripping them would make a legitimate same-host restore silently
  lose that data. `docs/operations.md` updated: restoring a downloaded
  bundle onto different infrastructure needs those four values re-entered
  by hand.
- **MEDIUM-DL-2**: the UI claimed a pending bundle is removed "immediately
  once you download it," which was never true (the server can't confirm
  the browser received every byte, so `handle_download_bundle()` never
  deletes on its own) — the actual behavior (TTL/cap-at-one/explicit-delete
  only) is the correct one; the UI text was wrong, not the code. Fixed by
  correcting the copy rather than adding delete-on-download, which would
  strand an admin with no bundle to retry after any connection hiccup on a
  multi-MB download.
- **MEDIUM-DL-3**: `create_job()`'s has-running-job check and the job
  insert were a plain check-then-act with no atomicity. Proven live: 5
  concurrent requests produced 5 distinct running jobs. Worse for download
  jobs specifically — cap-at-one and the explicit-delete endpoint both
  assume at most one pending bundle exists; the audit proved a second,
  genuinely verified bundle silently destroyed by a delete call carrying
  the *first* job's job_id. Fixed with a MySQL advisory lock (`GET_LOCK`/
  `RELEASE_LOCK`, the same primitive `Job_Store`'s per-job locking already
  uses) wrapping the whole check-then-act window in `create_job()` —
  closes the race for both destinations, not just download.
- **MEDIUM-DL-4**: `step_bundle_local()` allocated a fresh token (and
  therefore directory) on every call with no check for one already in
  progress — a process killed mid-assembly (e.g. `max_execution_time`)
  orphaned its partial directory forever while the next poll silently
  started a new one, unbounded for as long as the admin's tab kept
  retrying, each one holding a partial DB dump on disk. Fixed by
  persisting the token into the checkpoint immediately and reusing it
  (wiping and rebuilding the directory) on re-entry, a hard 3-attempt cap
  before failing the job outright, and a `disk_free_space()` precheck
  against the bundle's expected size.
- **LOW-DL-1**: a failed download job with Nextcloud *also* configured
  still fired an authenticated WebDAV PUT for its diagnostic log (harmless
  in practice — `step_init()` never creates the remote folder for a
  download job, so it 409s — but contradicts "no Nextcloud involvement at
  all" and is needless outbound credential use). Fixed with an early
  return in `upload_diagnostic_log()` for download-destination jobs.
- **LOW-DL-2**: `Job_Store::get_latest_verified()` had no destination
  filter, so a download-only job (whose bundle may already be gone) could
  be shown as the site's standing "last verified backup." Fixed by
  filtering to `destination = 'nextcloud'`.
- **LOW-DL-3**: the sweep removed a bundle's directory but never cleared
  the owning job's checkpoint, so `download_available` kept reading true
  and resurfaced a dead download link. Fixed with a new
  `Job_Store::find_by_download_token()`, called from the sweep to clear
  the owning job's checkpoint in the same pass.
- **LOW-DL-4**: the download link reused the same nonce shared by every
  other action on the settings page, sitting in a navigable GET URL
  (browser history, server logs, Referer headers) — a nonce alone can't
  perform a privileged action without the session cookie, but least-
  privilege nonce scoping is cheap. Fixed with a dedicated
  `NONCE_ACTION_DOWNLOAD`.
- **LOW-DL-5**: zero Phase 3 unit tests existed. Added `DownloadBundleTest.php`
  (9 tests covering `download_dir_for_token()`'s hex-only validation —
  traversal attempts, null bytes, wrong length, wrong case — and
  `new_download_token()`'s own shape) and a new
  `test_redact_wp_config_db_credentials_strips_db_constants` in
  `SettingsValidationTest.php`.

All nine fixed same-day per standing instruction. 127/127 unit tests
(10 new). Full regression: all 12 pre-existing E2E suites plus the new
`run-e2e-download-backup.sh` re-run clean — `create_job()`'s new
advisory lock touches every job of either destination, so the complete
suite was re-run, not just the download-specific one. Re-dispatched to
the security audit for re-verification before this is considered
shippable, per established pattern.

## Phase 2.5 — migration on restore, in progress, 2026-10-21

Promoted out of the Phase 3 candidates list below (was originally a
narrower "host-rewrite" idea) after real cross-site restore testing
surfaced the actual problem directly: with only the earlier
scheme-only fix in place, a backup restored onto a different domain
left that domain's wp-admin bouncing every login attempt back toward
the *old* site, since the restored site's own `home_url` still said
the old domain. The owner's own framing, which this is built to:
checking "this backup is from a different site" should mean
migration, not a half-state — the new site's URL becomes the truth,
and every URL the backup carries gets corrected to match. Targeting
v1.2.0.

Built: `DB_Migrator` (new class) — a serialization-safe search/replace
engine, plus a new `restore_migrate_urls` step between
`restore_apply_db` and `restore_apply_files`, still entirely against
the restore's own shadow tables, still before `restore_swap` promotes
anything live. Checkbox label and help text in the admin UI rewritten
to state the migration semantics explicitly, per the owner's request.
Full writeup in `docs/operations.md` and `docs/threat-model.md` T20.

Unit tests (13 new, `DbMigratorTest.php`) cover the two real bugs this
work's own tests caught before anything shipped: `is_serialized_value()`
initially checked serialized strings' closing quote against the wrong
character position (the project's own reimplementation of WordPress
core's `is_serialized()`, since the unit harness's lightweight WP shim
doesn't bootstrap full core) — fixed. A second "failure" turned out to
be the test's own wrong expectation, not a code bug: a stale
http-scheme link to the old domain correctly canonicalizes to the new
site's one real (https) address, not an invented http variant of the
new domain.

New `tests/e2e/run-e2e-restore-migration.sh`: seeds a synthetic "old
site" with content engineered to exercise every real risk this feature
exists to handle — a plain https:// link, a scheme-mismatched legacy
http:// link to the same old domain, a URL nested inside a
PHP-serialized widget option (where a naive replace would corrupt the
byte-length prefix), and a bare-hostname plain-text mention (an email
address) with no scheme prefix that must survive completely untouched.
Backs that site up, restores it onto a genuinely different domain
with the box checked, and asserts all of it landed correctly —
including that wp-admin's own not-logged-in redirect stays on the new
site and never bounces toward the old domain, and that `wp-login.php`
actually loads.

**A third, real bug — the kind only a real-backup regression test
catches, not a synthetic fixture: the existing `run-e2e-restore.sh`
suite (restoring the owner's own real production backup) crashed
outright with a fatal error.** The real site's own Action Scheduler
data (used by WooCommerce and many other plugins) stores a serialized
object (`ActionScheduler_IntervalSchedule`) whose class isn't loaded
at this point in a restore; `unserialize()` safely falls back to a
`__PHP_Incomplete_Class` stand-in, but the first version of
`replace_recursive()` then tried to WRITE a property on it anyway —
which PHP makes a fatal error, not a catchable exception, crashing the
entire migration on any real site with Action Scheduler (or any
plugin storing serialized objects) installed. Fixed two ways:
`unserialize()` now always runs with `allowed_classes => false` (the
incomplete-class stand-in becomes the guaranteed outcome for every
object, regardless of which classes happen to be loaded at that exact
moment, and a real class's `__wakeup()` is never run); and the
recursive replace explicitly detects `__PHP_Incomplete_Class` and
returns it completely unchanged. A URL embedded inside such an
object's own property is consequently not migrated — a narrow,
documented limitation, not a crash. New unit test
(`test_replace_in_scalar_incomplete_class_object_does_not_crash`,
105/105 passing) proves this directly; `run-e2e-restore.sh` re-run
clean afterward, and `run-e2e-restore-migration.sh` re-confirmed green
too.

All three bugs were caught by this work's own tests before anything
shipped — exactly the point of writing them. Unit suite, both new E2E
suites, and the full existing E2E suite all green.

**Sent to the security audit (commit `c7564d6`): BLOCKED.** 1 HIGH, 2
MEDIUM, 1 LOW, every one proven live via direct probes against the
deployed code, not just theoretical review:

- **HIGH**: a `C:`-format object (the `Serializable` interface,
  common before its PHP 8.1 deprecation — WooCommerce, Gravity Forms,
  ACF, and others all had classes using it) loses its entire inner
  payload through the unserialize/reserialize round trip whenever its
  class isn't loaded — `replace_recursive()` correctly left the
  resulting `__PHP_Incomplete_Class` stand-in itself untouched, but
  `serialize()` at the top of the call chain still emitted a
  corrupted, empty `O:ClassName:0:{}` in its place, silently
  destroying data on any row containing one regardless of whether it
  even held a URL. Fixed with a lossless-round-trip guard: before any
  replacement, `serialize()`-ing the just-unserialized value straight
  back is compared byte-for-byte against the original; a mismatch
  means the round trip already lost something, so the value is left
  completely untouched.
- **MEDIUM**: the plain `str_replace()` had no concept of a URL
  boundary — proven to wrongly match `https://example.com` inside the
  unrelated, longer domains `https://example.community` and
  `https://example.com.evil.net`. Fixed with a boundary-aware regex.
  Took two tries to get right: the first version's single-character
  lookahead also rejected the ordinary, legitimate case of a URL
  ending a sentence with a trailing period (`https://example.com.`,
  indistinguishable from `https://example.com.au` by looking only one
  character ahead) — fixed by looking two characters ahead instead,
  rejecting only when a `.` is itself followed by another
  alphanumeric domain-label character.
- **MEDIUM**: the replacement was not idempotent when the new URL is
  itself prefixed by the old one (the documented subdirectory-migration
  case, `https://example.com` -> `https://example.com/wordpress`) —
  replaying an already-migrated value on a checkpoint resume would
  append the suffix again every time, breaking the exact safety
  property `migrate_table_batch()`'s per-batch (not per-row) checkpoint
  relies on. Fixed by refusing to generate any pair shaped that way at
  all, rather than trying to make the replace safe to double-apply.
- **LOW**: `$wpdb->update()`'s return value was discarded, so a
  failed write silently counted as success with the old value left in
  place. Fixed by failing the job outright on any write error, same
  discipline already used everywhere else in this destructive phase.

All four fixed. New unit tests directly reproduce the `C:`-format
corruption, the boundary overmatch, and the subdirectory-migration
refusal (110/110 unit tests passing). Both `run-e2e-restore-migration.sh`
and the real-backup `run-e2e-restore.sh` re-run clean afterward, plus
the full existing 9-suite E2E regression set. Re-dispatched for
re-verification.

**Re-verification (commit `45738a7`): BLOCKED again**, two more real
findings — one a genuine HIGH *regression introduced by the
MEDIUM-MIG-2 fix itself*, one a MEDIUM pre-existing gap that fix round
hadn't touched:

- **HIGH (regression)**: the subdirectory-migration refusal's bare
  `strpos()`-prefix check also wrongly refused four *safe* shapes
  that merely happen to share a long string prefix for unrelated
  reasons — an ordinary scheme-only migration
  (`http://example.com` → `https://example.com`, where
  `swap_scheme()` produces an identity pair that the check
  misclassified as unsafe), a port being added
  (`http://localhost` → `http://localhost:8080`), a longer but
  unrelated hostname (`https://example.co` → `https://example.com`),
  and a ccTLD extension (`https://example.com` → `https://example.com.au`).
  Each of these got silently refused — meaning `migrate_pairs` came
  back empty, the whole migration silently no-opped, and (for three
  of the four shapes) the restored site ended up with `siteurl`/`home`
  still pointing at the *backup's* old host, broken in exactly the
  way this entire feature exists to prevent, with the job still
  reporting `verified`. Fixed two ways: identity pairs
  (search equals replace) are now dropped outright rather than
  checked at all; and the refusal condition now specifically checks
  for the old URL being extended by a `/` or `?` (an actual
  path/query continuation), not a bare string-prefix match — which
  the boundary-aware regex already makes safe to replay for all four
  of the previously-misclassified shapes. A refusal now also
  surfaces as an explicit `WP_Error`, failing the restore at
  `restore_preflight` (still entirely read-only) rather than silently
  falling through to an unmigrated restore past the point of no
  return, which is what the first version of this fix did.
- **MEDIUM (pre-existing)**: a string *leaf* inside a serialized
  structure can itself be a serialized value — WordPress's own
  `maybe_serialize()` does this deliberately whenever a caller passes
  `update_option()` an already-serialized-looking string (a real,
  common mistake: `update_option($key, serialize($data))` instead of
  `update_option($key, $data)`). Treating that leaf as a plain string
  and doing a raw byte replace on it left its own length-prefixed
  encoding stale while only the *outer* wrapper's length got
  recomputed — corruption invisible until something unserializes it
  a second time. Fixed by having the string-leaf case recurse back
  into the same serialization-aware check the top-level value already
  gets, rather than going straight to a raw replace.

Both fixed. Six new unit tests (scheme-only, port-addition,
longer-unrelated-hostname, and ccTLD migrations must all still
produce real pairs; the subdirectory case must now fail with a
`WP_Error`; a double-serialized value must survive migration
correctly on both unserialize passes) — 115/115 unit tests passing.
Both E2E suites, including the real-backup regression test, re-run
clean again.

**Second re-verification (commit `42e2ccb`): BLOCKED a third time**,
one more real finding — the kind the re-verification process exists
to catch: a fresh adversarial pass, not a re-check of what was already
fixed.

- **MEDIUM**: a PHP serialization backreference (`R:N;` — `$x[0] = &$x;`
  then `serialize($x)`) reconstructs via `unserialize()` as a genuinely
  circular (or merely deeply shared) reference. This survives the
  HIGH-MIG-1 round-trip guard losslessly — `serialize(unserialize($v)) === $v`
  holds for this shape even though the structure is self-referential —
  so without any further check, `replace_recursive()` walked it
  forever: proven live to exhaust PHP's memory limit and fatal. A PHP
  Fatal error is not a catchable exception and skips every `finally`
  block, so `Restore_Orchestrator::advance()`'s own
  `try { ... } finally { Job_Store::release_lock(...) }` never runs —
  the restore's advisory lock stays held, unrecoverable without manual
  intervention. Fixed with a depth cap (100) threaded through every
  recursive entry point. First attempt at the fix stopped the
  unbounded recursion (no more fatal) but didn't fully solve it:
  returning a plain, depth-capped value from deep inside the
  recursion still meant 100 freshly-rebuilt array layers replaced what
  had been a single, compact `R:` backreference — not corrupted, not
  unbounded, but not byte-identical to the original either, which a
  circular/self-referential value (containing no URL to migrate in
  the first place) should be. Corrected by throwing when the cap is
  hit and catching only at the outermost call, so the *entire* original
  value unwinds back out byte-for-byte untouched, rather than a
  partially-rebuilt reconstruction of it.

New unit test reproduces the exact circular-reference shape and
confirms the result is the original value, completely unchanged —
116/116 unit tests passing. Both E2E suites re-run clean again.

**Third re-verification (commit `d1f8c94`): the circular-reference fix
confirmed working, but found a fourth, related finding (MEDIUM-DAG-1)
via a fresh adversarial pass** — a *shared* (not circular) reference,
the same array referenced from two different parent slots, is a real
PHP serialization shape `unserialize()` faithfully reconstructs. It
never exceeds `MAX_RECURSION_DEPTH` on any single path, but a plain
recursive walk still visits the shared sub-tree once per parent,
doubling total work at every level of sharing — proven live: an
18-level-deep version of this shape (a few hundred bytes) exhausted a
256MB memory limit, same fatal/stuck-lock consequence as the original
circular-reference bug, via a different mechanism the depth cap alone
can't catch. Fixed with a node-visit budget (50,000), independent of
recursion depth, reusing the exact same throw/catch-at-depth-0 unwind
path already built for the depth cap — no new exception
infrastructure needed. New unit test (built via real PHP reference
assignment, not a hand-rolled serialized string, specifically to
avoid the R:-slot-numbering mistake that made the audit's own first
attempt at proving this look like a false pass) reproduces the shape
and confirms the result is unchanged, verified under a constrained
128MB PHP memory limit to prove the budget genuinely aborts before
exhausting it rather than merely running fast enough not to notice —
117/117 unit tests passing. Both E2E suites re-run clean again.
**Fourth re-verification (commit `288ba7e`): CLEAR TO SHIP.** The
depth cap and node-visit budget together form a complete
two-dimensional bound on PHP serialization amplification — depth caps
any single root-to-leaf path (circular references), the node budget
caps total node visits regardless of path length (shared-reference
DAGs). Confirmed across DAG depths 18/20/25/30/50 (all correctly
aborted well short of memory exhaustion at every depth), confirmed
the budget resets cleanly between independent top-level calls (no
cross-contamination across `migrate_table_batch()`'s per-row loop),
and a dedicated search for a third distinct amplification vector
found none — a wide-but-shallow flat array costs strictly linear
work in input size, not amplification, and isn't a vulnerability in
this class.

**Migration on restore is shippable.** Four rounds of audit findings,
all real, all fixed, all re-verified: the original scope gap (box
meant "proceed anyway" instead of migration), a real crash against
real production Action Scheduler data, a boundary-overmatch bug, an
idempotency bug (plus the regression introduced fixing it), a
double-serialization corruption bug, and two distinct PHP
serialization-amplification attack shapes (circular references, then
shared-reference DAGs). Every one caught by this project's own
build-test-audit-fix-reverify discipline before shipping, several of
them the kind only real production data or genuinely adversarial
probing ever surfaces. Proceeding to cut v1.2.0.

## Phase 3 candidates (not yet scoped, for later discussion)

(Both prior entries here — Nextcloud-free backup, other WebDAV
destinations — shipped; see the doc-correction note at the top of this
file, 2026-10-21. Nothing currently queued in this section.)

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
