# Cutting a release (Phase 2, update feed)

The plugin checks `github.com/ItsMNLOP/wpguard-nc-backup`'s **Releases**
for updates (`Update_Checker`, Phase 2 item 4) instead of a self-hosted
feed server or the WordPress.org plugin directory. It only ever offers
an update when the release carries a `.zip` asset whose name starts
with `wpguard-nc-backup` — it never falls back to GitHub's own
auto-generated zipball, because that contains the whole repo (`docs/`,
`tests/`, `.github/`, etc.), not just the plugin folder.

As of 2026-10-21, `.github/workflows/ci.yml`'s `release` job builds and
attaches that zip automatically on any `v*` tag push, gated behind the
same lint/unit/E2E jobs every push already runs (a broken build can
never produce a release asset):

1. Bump `Version:` in `plugin/wpguard-nc-backup/wpguard-nc-backup.php`
   and `WPGUARD_NC_VERSION`, and `Stable tag:` in `readme.txt`.
2. Tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. CI builds `wpguard-nc-backup-X.Y.Z.zip` from that tag (same
   `git archive --prefix=wpguard-nc-backup/` shape as below) and
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
   `plugin/wpguard-nc-backup/` folder itself (so the archive's top
   level is `wpguard-nc-backup/...`, not the repo root):
   ```
   git archive --format=zip --prefix=wpguard-nc-backup/ vX.Y.Z:plugin/wpguard-nc-backup -o wpguard-nc-backup-X.Y.Z.zip
   ```
2. Create a GitHub Release for the tag and attach that zip as a
   release asset. The release body becomes the "View version details"
   changelog shown in wp-admin.

Sites check at most once every 12 hours (`Update_Checker::CACHE_TTL`);
a failed or missing-release check is cached for 1 hour so a site
doesn't hammer the GitHub API. `upgrader_process_complete` clears the
cache immediately after any plugin update, so a just-installed version
isn't momentarily re-offered from a stale cached check.
