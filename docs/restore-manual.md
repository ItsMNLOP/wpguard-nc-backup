# Manual disaster recovery runbook

**If you're restoring this exact site, in place, and the plugin is still
installed and running on it: use the plugin's own one-click restore
(WPGuard > Nextcloud Backup > Restore) instead of this document.** It
handles every step below automatically, verified against real production
data (`tests/e2e/run-e2e-restore.sh`, `docs/operations.md`'s "One-click
restore" section, `docs/threat-model.md` T20). This manual runbook exists
for the case that tool can't help with: the original WordPress install is
gone, offline, or unreachable, and you're recovering onto a **different**
host using only the Nextcloud archive and these instructions.

**Read this before you need it.** This procedure does **not** require the
WPGuard plugin, or even a WordPress install, to be present on the recovery
machine for steps 1–3. You need: a WebDAV-capable client (or `curl`), a
MySQL/MariaDB client, `unzip`/`ZipArchive`, and a fresh target WordPress
install (steps 4+).

This is the exact procedure the automated E2E restore test executes — see
`tests/e2e/`. If you deviate from it, you are no longer testing what was
verified.

## 0. Check `manifest.json`'s `backup_mode` first (Phase 2, incremental backups)

Before retrieving anything else, fetch just `<backup-id>/manifest.json` and
read its `backup_mode` field:

- **`"full"`** (or a `format_version: 1` manifest from before this feature
  existed, which has no `backup_mode` field at all): every file this backup
  needs is inside its own `files.part-NNNN.zip`. Follow steps 1–8 exactly as
  written below — nothing else to know.
- **`"incremental"`**: this backup's own folder does **not** contain every
  file you need. Skip to **step 1a** instead of step 1.

**Stop if `format_version` is higher than this document's supported value
(2)** — a newer plugin version wrote this backup, and the shape may have
changed again; use that version's own copy of this document instead of
guessing.

## 1. Retrieve the backup from Nextcloud, independent of WordPress

Using any WebDAV client (Nextcloud web UI, a desktop sync client, `curl`,
`cadaver`, etc.), browse to
`/<configured-folder>/<site-id>/backups/<backup-id>/` on your Nextcloud
server and download the entire folder, including:
- `manifest.json`
- `COMPLETE.json`
- `database.sql.gz` (or `database.part-NNNN.sql.gz` parts)
- `files.part-NNNN.zip` (one or more)

**Stop if `COMPLETE.json` is missing.** A backup folder without it was never
verified and may be incomplete; pick an earlier `<backup-id>` that has one.

## 1a. Retrieving an INCREMENTAL backup (skip this if step 0 said "full")

An incremental backup's `manifest.json` has a `files` array: one entry per
file currently on the site, each naming `source_backup_id` and
`source_part` — the backup folder where that file's actual bytes live
(its own, or an earlier one in the same chain). Nothing here is a diff to
"apply" on top of anything; it's a complete, resolved list of exactly what
the site looked like at backup time.

**The security audit, 2026-10-20 (MEDIUM-RESTORE-1): every `source_part` is named
`files.part-NNNN.zip` the same way in every backup** — two different
ancestor backups' parts both named `files.part-0001.zip` will silently
overwrite each other if downloaded to that bare filename. Step 3 below
now names each download by its full `(source_backup_id, source_part)`
pair specifically to prevent this. The same audit also found this
section gave no instruction to verify extracted files' integrity, and no
warning against the natural mistake of applying step 6's whole-archive
`unzip` to an ancestor part here — doing that would **resurrect deleted
files**, the exact risk this feature's design otherwise eliminates (see
`docs/threat-model.md` T19). Both are fixed below. `jq` is assumed
available; any tool that can read JSON works identically.

1. Download this backup's own `manifest.json` and `COMPLETE.json` (same
   stop-if-missing rule as step 1) and `database.sql.gz` (the database is
   never incremental — always a full dump in every backup).
2. List the **distinct** `(source_backup_id, source_part)` pairs referenced
   anywhere in `files` — you almost always need far fewer than one
   download per file, since many files typically share the same
   unchanged ancestor part:
   ```bash
   jq -r '.files[] | "\(.source_backup_id)/\(.source_part)"' manifest.json | sort -u
   ```
3. For each distinct pair, download that *other* backup's
   `/<site-id>/backups/<source_backup_id>/<source_part>` — yes, reaching
   into an earlier backup's folder on purpose — **naming the local file
   after the full pair, never the bare part name**, so two ancestors'
   same-named parts can never collide:
   ```bash
   # source_backup_id=20261019T120000Z-ab12cd34, source_part=files.part-0001.zip
   curl -u "<nextcloud-user>:<app-password>" \
     "https://<nextcloud-host>/remote.php/dav/files/<nextcloud-user>/<folder>/<site-id>/backups/20261019T120000Z-ab12cd34/files.part-0001.zip" \
     -o "20261019T120000Z-ab12cd34__files.part-0001.zip"
   ```
   **Do not delete or rotate out an earlier backup a newer one still has
   live references into** — this plugin's own retention pruning already
   accounts for this (see `docs/operations.md`), but if you've copied
   backups elsewhere by hand, the same rule applies there too.
4. **Extract only the individual named files you need — never the whole
   contents of an ancestor part — and handle `wp-config.php` separately,
   never through this generic loop (the security re-verification, MEDIUM-RESTORE-2).**
   Step 6's `unzip files.part-NNNN.zip -d /target/` does **not** apply here:
   an ancestor part is itself a complete snapshot of whatever existed when
   *it* was taken, including files this backup's own `manifest.json` no
   longer lists as current (already deleted by backup time) — extracting it
   wholesale would restore those deleted files right back. Extract by exact
   path instead, one entry at a time, into your target WordPress root —
   **excluding `wp-config.php`**, which `Inventory::enumerate()` always
   includes in `files[]` just like any other file, but which step 6's
   RST-04 reconciliation exists specifically to handle (it contains the
   *source* site's DB credentials, which must never blindly overwrite the
   target's):
   ```bash
   jq -r '.files[] | select(.path != "wp-config.php") | "\(.source_backup_id)__\(.source_part) \(.path)"' manifest.json \
     | while read -r partfile path; do
         mkdir -p "/path/to/target/wordpress/$(dirname "$path")"
         unzip -p "$partfile" "$path" > "/path/to/target/wordpress/$path"
       done
   ```
4a. **Extract and reconcile `wp-config.php` through step 6's procedure,
   not step 1a's loop above.** Find its entry's `(source_backup_id,
   source_part)` pair (`jq -r '.files[] | select(.path == "wp-config.php")'
   manifest.json`), download/extract just that one file the same way item 3
   above downloaded any other distinct pair, then follow step 6's four
   sub-steps on it exactly as written for a full backup — nothing about
   RST-04's reconciliation differs for an incremental backup's
   `wp-config.php`, only where the file physically came from.
5. **Verify every extracted file against its manifest `sha256` before
   trusting it** — this is the per-file equivalent of step 2 below, which
   only covers `components[]` (this specific backup's own produced
   artifacts: `database.sql.gz` and whichever `files.part-NNNN.zip` files
   it actually produced itself, possibly none at all if nothing changed
   since its chain parent). A file extracted from an ancestor part is
   never in `components[]`; its own `sha256` field inside its `files[]`
   entry is the only thing that verifies it, regardless of which backup
   it actually came from:
   **Excludes `wp-config.php`** — item 4a's reconciled copy is deliberately
   *not* a verbatim copy of the backup's original (that's the whole point
   of RST-04's selective-merge procedure), so it will never match the
   backup's own `sha256` for that path; its own review in step 6 is what
   stands in for an automated check there.
   ```bash
   cd /path/to/target/wordpress
   jq -r '.files[] | select(.path != "wp-config.php") | "\(.sha256)  \(.path)"' /path/to/manifest.json | sha256sum --check
   ```
   **Do not proceed past any mismatch** — same rule as step 2.
6. Continue at step 2 below for the database/manifest-level integrity
   check, then skip step 6 (file extraction) entirely — already done,
   correctly, in item 4 above.

Example with `curl` against the app-password account (replace placeholders):

```bash
curl -u "<nextcloud-user>:<app-password>" \
  "https://<nextcloud-host>/remote.php/dav/files/<nextcloud-user>/<folder>/<site-id>/backups/<backup-id>/manifest.json" \
  -o manifest.json
# repeat for COMPLETE.json, database.sql.gz, each files.part-NNNN.zip
```

## 2. Verify integrity before trusting anything

For every entry in `manifest.json`'s `components[]`, recompute the local
hash and compare:

```bash
sha256sum database.sql.gz files.part-0001.zip   # Linux/macOS
# Windows PowerShell:
Get-FileHash database.sql.gz,files.part-0001.zip -Algorithm SHA256
```

Every value must exactly match the corresponding `sha256` field in
`manifest.json`. **Do not proceed past a mismatch** — it indicates a
corrupted download or a corrupted stored backup; retry the download first,
and if it still mismatches, try an earlier `<backup-id>`.

Also check `manifest.json`'s `format_version` is `1` or `2` (or a version
this document still covers — see `docs/backup-format.md`'s compatibility
policy) — already checked in step 0 above, repeated here as a reminder
since this is the point where you'd actually notice a mismatch if you
skipped straight to step 1.

## 3. Decompress and inspect before extracting

```bash
gunzip database.sql.gz
unzip -l files.part-0001.zip | less   # list contents, sanity-check paths
```

Confirm no listed path is absolute or contains `..` segments. The plugin's
archive writer refuses to create such entries, but an independent check
before extraction costs nothing (defense in depth, see
`docs/threat-model.md` T6).

Check available disk space against the manifest's declared `bytes` sizes
before extracting (T7, archive-bomb defense — a legitimate backup's
extracted size should be in the same order of magnitude as the sum of
component sizes; wildly larger is a reason to stop and inspect).

## 4. Prepare a clean target WordPress install

Set up a fresh WordPress install (any supported version from
`docs/adr/0008-version-support-matrix.md`) with its own database. **Do not
reuse the original site's install** unless the original is confirmed
unrecoverable and you're intentionally overwriting it. Note the target's
own DB name/user/password/host — you will need to reconcile these with the
restored `wp-config.php` in step 6.

## 5. Import the database

```bash
mysql -h <target-db-host> -u <target-db-user> -p <target-db-name> < database.sql
```

If the backup was split (`database.part-0001.sql.gz`, `...-0002...`), import
each part in order into the same target database — each part is a
self-contained set of complete `CREATE TABLE`/`INSERT` statements for its
tables, not a byte-split file, so order between *table* parts does not
matter, but each part must be imported fully.

## 6. Restore files, reconciling `wp-config.php`

```bash
unzip files.part-0001.zip -d /path/to/target/wordpress/
unzip files.part-0002.zip -d /path/to/target/wordpress/   # if present
```

**`wp-config.php` is high-risk (RST-04).** The archive's `wp-config.php`
contains the *source* site's DB name/user/password/host and, if you used the
constant-override credential path (ADR 0002), the Nextcloud app password.
Do **not** blindly overwrite the target's working `wp-config.php` with the
archived one. Instead:

1. Open the archived `wp-config.php` in a text editor.
2. Copy over `$table_prefix` and the `AUTH_KEY`/`AUTH_SALT`/etc. secret
   definitions (these must match what the exported database expects for
   session/cookie validity) into the **target's own** `wp-config.php`.
3. Leave the target's own `DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_HOST`
   pointing at the target database you created in step 4 — do not copy the
   source site's DB credentials over the target's.
4. If the archived file defines `WPGUARD_NC_APP_PASSWORD` or
   `WPGUARD_NC_CA_BUNDLE`, do not carry those over blindly either; re-enter
   them deliberately if you intend to keep backing up from the restored
   site, since the "same Nextcloud account" assumption may not hold on a new
   host.

**Note specific to this project's disposable Docker test stack** (not a
real-hosting concern): `docker/docker-compose.yml`'s `wp-restore` service
reads `$table_prefix` from its own container environment
(`WORDPRESS_TABLE_PREFIX`), not from a static `wp-config.php` line like a
real host would. If that environment value doesn't match the backup's
actual `table_prefix`, the site shows WordPress's "choose a language"
installer instead of the restored content — found live during testing.
`tests/e2e/run-e2e.sh` updates `docker/.env` and recreates the container
automatically; doing this restore by hand against the test stack requires
the same manual step (edit `WP_RESTORE_TABLE_PREFIX` in `docker/.env`,
then `docker compose up -d wp-restore`) before browsing to it.

## 7. Reconcile the site URL if restoring to a new host/domain

If `manifest.json`'s `source.home_url`/`source.site_url` differ from the
target's actual URL, WordPress's serialized data (in `wp_options`, some
`wp_postmeta`) embeds the old URL inside PHP-serialized strings, where a
naive find-and-replace corrupts the serialization length prefixes. Use a
serialization-aware tool:

```bash
wp search-replace 'https://source.example.test' 'https://target.example.test' --all-tables --precise
```

(`wp-cli`'s `search-replace` is serialization-aware; this is why the brief
and `RST-05` forbid naive SQL string replacement.) If `wp-cli` is not
available on the target, use a reviewed serialization-aware PHP script —
never a plain SQL `UPDATE ... REPLACE()` across tables.

## 8. Validate

- Log in to `/wp-admin/` with a known source-site admin account.
- Confirm posts/pages count and a specific known post's content and
  permalink match the source.
- Confirm a known media file opens and its checksum matches the original
  (if you retained the original to compare).
- Confirm the expected theme and plugins are present and the expected one is
  active.
- Confirm a representative plugin-specific setting (e.g., a sentinel option
  value used in the automated test fixtures) is present.

## Known limitations (MVP)

- No guided/automated restore tool ships in MVP (RST-04 is Phase 4). This
  document is the only restore path. Step 1a's part-fetching/extraction is
  entirely manual — it has been exercised by the automated
  `tests/e2e/run-e2e-incremental.sh` (which performs exactly this
  reconstruction in code, not just a direct-call test of the chain logic),
  but not yet wrapped in a one-click admin-UI restore (a separate,
  not-yet-built Phase 2 item).
- Files outside the WordPress document root, and symlink targets outside
  the site root, are never captured — restore them from your own records if
  your site depends on any.
- No cross-host credential/secret migration tooling exists; step 6 is manual
  and deliberately so (RST-04: "never silently overwrite target environment
  secrets").
- This runbook has been executed, end-to-end, exactly as written, by the
  automated E2E test in `tests/e2e/` against one specific version
  combination (see `docs/adr/0008-version-support-matrix.md`, "tested"
  row). It has not been exercised against every matrix combination, and not
  against a real production WordPress install.
