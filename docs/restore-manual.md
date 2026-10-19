# Manual disaster recovery runbook

**Read this before you need it.** This procedure does **not** require the
WPGuard plugin, or even a WordPress install, to be present on the recovery
machine for steps 1–3. You need: a WebDAV-capable client (or `curl`), a
MySQL/MariaDB client, `unzip`/`ZipArchive`, and a fresh target WordPress
install (steps 4+).

This is the exact procedure the automated E2E restore test executes — see
`tests/e2e/`. If you deviate from it, you are no longer testing what was
verified.

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

Also check `manifest.json`'s `format_version` is `1` (or a version this
document still covers — see `docs/backup-format.md`'s compatibility policy).

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
  document is the only restore path.
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
