=== WPGuard Nextcloud Backup ===
Contributors: itsmnlop
Tags: backup, nextcloud, webdav, restore, disaster-recovery
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backs up your WordPress files and database to a Nextcloud server you control, over authenticated HTTPS WebDAV, with read-back checksum verification before anything is called a success.

== Description ==

Phase 1 MVP, security-audited and verified against real production data: manual, on-demand backups only. No scheduling, no retention/pruning, no one-click restore yet — see docs/restore-manual.md in the source repository for the manual disaster-recovery procedure, which is the only restore path in this release.

A backup is never reported successful until every uploaded component has been read back from Nextcloud and its SHA-256 checksum verified, and a final completion marker has itself been uploaded and verified.

== Requirements ==

* A Nextcloud server reachable over HTTPS with a valid certificate (or a private CA you supply the path to).
* A dedicated, non-admin Nextcloud account and an app password generated under Personal Settings > Security. Do not use your main Nextcloud account password.

== Installation ==

1. Upload and activate the plugin.
2. Go to WPGuard > Nextcloud Backup.
3. Enter your Nextcloud URL, the dedicated account's username, its app password, and a destination folder.
4. Click Test Connection.
5. Click Back Up Now.

== Changelog ==

= 1.3.0 =
Phase 3 release: a shared top-level WPGuard admin menu (future WPGuard-family plugins land as submenus); "Download Now" — a second, independent backup destination that packages a backup into a flat zip and serves it to your browser, with zero Nextcloud dependency; multi-backend support (ownCloud, OpenCloud, anything speaking the same WebDAV dialect, alongside Nextcloud); and WP-CLI parity for both of the above (`run --destination=download`, `restore_local`). Every feature went through an independent security audit, fixes applied, and re-verification before shipping — see this project's STATUS.md and docs/threat-model.md (T21–T23) for the full history.

= 1.2.0 =
Phase 2.5 release: checking "this backup is from a different site" on restore now means migration, not just "proceed anyway" — every URL in the backup (including inside PHP-serialized data) is rewritten to match this site's own address before anything is restored live, so logging into the restored site actually works. Four rounds of independent security audit findings, all fixed and re-verified, including two distinct PHP serialization-amplification attack shapes found via live adversarial testing against real production backup data. See this project's STATUS.md and docs/threat-model.md for the full history.

= 1.1.0 =
Phase 2 release: automatic stale-lock recovery, scheduled (daily/weekly) backups, retention pruning, incremental file backups, a self-hosted update feed via GitHub Releases, and one-click in-place restore (from either the configured Nextcloud destination or an uploaded backup bundle). Every feature went through an independent security audit, fixes applied, and re-verification before shipping — see this project's STATUS.md and docs/threat-model.md for the full history. Verified against real production WordPress/Nextcloud data throughout, including full real restores with 2FA state intact.

= 1.0.0 =
Phase 1 MVP release. Two full independent security audits, both CLEAR TO SHIP. Verified against real production WordPress/Nextcloud data, including a full real restore with 2FA state intact. Fixes and additions since the initial 0.1.0 build: concurrent job-advance mutual exclusion; wp-config.php credential redaction before archiving; WebDAV redirect/streaming/CA-bundle hardening; ZIP packaging fixed to use spec-compliant path separators; batched file/table export to cut AJAX round trips; a manual Cancel/Reset action for a stuck job; a diagnostic per-backup job-log.json; archive-writer performance fixes (no longer reopens the ZIP per file; skips recompressing already-compressed file types).

= 0.1.0 =
Initial MVP: manual backup, versioned manifest, SHA-256 verified WebDAV transfer, no retention/scheduling.
