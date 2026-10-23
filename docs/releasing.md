# Cutting a release (Phase 2, update feed)

The plugin checks `github.com/ItsMNLOP/wpthistle-backup-restore`'s **Releases**
for updates (`Update_Checker`, Phase 2 item 4) instead of a self-hosted
feed server or the WordPress.org plugin directory. It only ever offers
an update when the release carries a `.zip` asset whose name starts
with `wpthistle-backup-restore` — it never falls back to GitHub's own
auto-generated zipball, because that contains the whole repo (`docs/`,
`tests/`, `.github/`, etc.), not just the plugin folder.

As of 2026-10-21, `.github/workflows/ci.yml`'s `release` job builds and
attaches that zip automatically on any `v*` tag push, gated behind the
same lint/unit/E2E jobs every push already runs (a broken build can
never produce a release asset):

1. Bump `Version:` in `plugin/wpthistle-backup-restore/wpthistle-backup-restore.php`
   and `WPTHISTLE_BACKUP_VERSION`, and `Stable tag:` in `readme.txt`.
2. Tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. CI builds `wpthistle-backup-restore-X.Y.Z.zip` from that tag (same
   `git archive --prefix=wpthistle-backup-restore/` shape as below) and
   attaches it to a GitHub Release for the tag, with auto-generated
   release notes.

A tag with a `-` suffix (e.g. `v1.2.1-rc1`) is published as a GitHub
prerelease — `Update_Checker` queries `/releases/latest`, which the
GitHub API never returns a draft or prerelease for, so this is the
safe way to test the whole pipeline without any live site ever being
offered it.

**Manual fallback**, if CI is unavailable or a release needs hand
assembly:

1. Build the asset from a clean checkout of the tag — zip the
   `plugin/wpthistle-backup-restore/` folder itself (so the archive's top
   level is `wpthistle-backup-restore/...`, not the repo root):
   ```
   git archive --format=zip --prefix=wpthistle-backup-restore/ vX.Y.Z:plugin/wpthistle-backup-restore -o wpthistle-backup-restore-X.Y.Z.zip
   ```
2. Create a GitHub Release for the tag and attach that zip as a
   release asset. The release body becomes the "View version details"
   changelog shown in wp-admin.

Sites check at most once every 12 hours (`Update_Checker::CACHE_TTL`);
a failed or missing-release check is cached for 1 hour so a site
doesn't hammer the GitHub API. `upgrader_process_complete` clears the
cache immediately after any plugin update, so a just-installed version
isn't momentarily re-offered from a stale cached check.

## One-time cutover: WPGuard Nextcloud Backup -> WPThistle Backup and Restore (2.0.0)

A new plugin slug means WordPress sees this as a different plugin from
the old "WPGuard Nextcloud Backup" -- there is no in-place update path
for this one release, and the old plugin's own update feed cannot see
this release either (different repo, different slug allowlist). On any
site already running the old plugin:

1. **Deactivate** "WPGuard Nextcloud Backup" — do **not** delete it yet.
   Deactivating leaves its settings, job history, and jobs table
   exactly where they are; deleting runs its `uninstall.php`, which
   drops all of that.
2. **Install and activate** "WPThistle Backup and Restore" 2.0.0.
   Activation runs `migrate_from_legacy_wpguard()` automatically: it
   copies settings/site_id/chain-state/retention-error-flag to this
   plugin's own option keys and renames the old jobs table in place, so
   backup/incremental-chain history carries over rather than starting
   fresh.
3. **Verify**: Settings should already show your Nextcloud/ownCloud/
   OpenCloud connection as configured, and the family hub
   (`WPThistle` in the admin menu) should show your real last-backup
   status, not "No backup yet."
4. Only then, **delete** the old plugin's files.

If a wp-config.php defines `WPGUARD_NC_APP_PASSWORD` or
`WPGUARD_NC_CA_BUNDLE`, those still work as a fallback -- no file edit
is required for the cutover itself, though updating to the
`WPTHISTLE_BACKUP_*` names eventually is recommended.
