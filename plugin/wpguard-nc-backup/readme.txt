=== WPGuard Nextcloud Backup ===
Contributors: itsmnlop
Tags: backup, nextcloud, webdav, restore, disaster-recovery
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backs up your WordPress files and database to a Nextcloud server you control, over authenticated HTTPS WebDAV, with read-back checksum verification before anything is called a success.

== Description ==

This is an MVP (0.1.0): manual, on-demand backups only. No scheduling, no retention/pruning, no one-click restore yet — see docs/restore-manual.md in the source repository for the manual disaster-recovery procedure, which is the only restore path in this release.

A backup is never reported successful until every uploaded component has been read back from Nextcloud and its SHA-256 checksum verified, and a final completion marker has itself been uploaded and verified.

== Requirements ==

* A Nextcloud server reachable over HTTPS with a valid certificate (or a private CA you supply the path to).
* A dedicated, non-admin Nextcloud account and an app password generated under Personal Settings > Security. Do not use your main Nextcloud account password.

== Installation ==

1. Upload and activate the plugin.
2. Go to Settings > Nextcloud Backup.
3. Enter your Nextcloud URL, the dedicated account's username, its app password, and a destination folder.
4. Click Test Connection.
5. Click Back Up Now.

== Changelog ==

= 0.1.0 =
Initial MVP: manual backup, versioned manifest, SHA-256 verified WebDAV transfer, no retention/scheduling.
