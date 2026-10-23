#!/usr/bin/env bash
# Phase 2 incremental-backup regression test: proves the design in
# class-backup-chain.php's docblock against a real WP install + real
# Nextcloud server, not mocks -- unchanged files are genuinely never
# re-archived/re-uploaded (not just logically marked unchanged),
# changed/new files are captured fresh, a deleted file never resurrects,
# a real byte-for-byte restore reconstruction from the final manifest's
# files list succeeds by pulling from whichever ancestor backup each
# file's bytes actually live in, and chain-aware retention protects a
# backup that a kept backup still depends on even when it would
# otherwise be "too old" to keep.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-incremental-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container' || true
}

TRACK_DIR="wp-content/uploads/wpguard-incr-test"
echo "=== Setup: clean job table, clear leftover local chain state from any previous run of this test, enable incremental backups, create 3 tracked files ==="
wp_source db query "UPDATE wp_wpguard_nc_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true
wp_source eval 'delete_option("wpguard_nc_chain_state");' > /dev/null
wp_source eval '
  \WPGuard\NCBackup\Settings::save(array(
    "nextcloud_url" => \WPGuard\NCBackup\Settings::get_nextcloud_url(),
    "nextcloud_user" => \WPGuard\NCBackup\Settings::get_nextcloud_user(),
    "remote_folder" => \WPGuard\NCBackup\Settings::get_remote_folder(),
    "incremental_enabled" => true,
    "retention_count" => 0,
  ));
' > /dev/null
docker exec wpguard-test-wp-source-1 sh -c "
  mkdir -p /var/www/html/$TRACK_DIR
  printf 'fileA-v1' > /var/www/html/$TRACK_DIR/fileA.txt
  printf 'fileB-v1' > /var/www/html/$TRACK_DIR/fileB.txt
  printf 'fileC-v1' > /var/www/html/$TRACK_DIR/fileC.txt
"
echo "OK  3 tracked files created, incremental backups enabled"

echo
echo "=== Backup 1 (expected: full -- no local chain state yet) ==="
wp_source wpguard-nc-backup run > "$SCRATCH/run1.log" 2>&1 || true
tail -3 "$SCRATCH/run1.log"
BACKUP1=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::get_latest()["backup_id"];')
STATUS1=$(wp_source eval "echo \WPGuard\NCBackup\Job_Store::get_latest()['status'];")
[ "$STATUS1" = "verified" ] || { echo "FAIL: backup 1 did not verify"; exit 1; }
echo "backup1=$BACKUP1"

RESULT1=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$m = \$client->get_json( \$client->relative_path( \WPGuard\NCBackup\Settings::get_site_id() . '/backups/$BACKUP1/manifest.json' ), 64*1024*1024 );
  \$by_path = array();
  foreach ( \$m['files'] as \$f ) { \$by_path[\$f['path']] = \$f; }
  echo \$m['backup_mode'] . '|' . (\$m['base_backup_id'] ?? 'null') . '|' . \$by_path['$TRACK_DIR/fileA.txt']['source_backup_id'];
")
[ "$RESULT1" = "full|null|$BACKUP1" ] || { echo "FAIL: backup 1 expected full|null|<own id>, got '$RESULT1'"; exit 1; }
echo "OK  backup 1 is a full backup, fileA's manifest entry correctly points at itself"

echo
echo "=== Backup 2 (expected: incremental, no file changes -- every tracked file must be carried forward, NOT re-archived) ==="
wp_source wpguard-nc-backup run > "$SCRATCH/run2.log" 2>&1 || true
tail -3 "$SCRATCH/run2.log"
BACKUP2=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::get_latest()["backup_id"];')
STATUS2=$(wp_source eval "echo \WPGuard\NCBackup\Job_Store::get_latest()['status'];")
[ "$STATUS2" = "verified" ] || { echo "FAIL: backup 2 did not verify"; exit 1; }
echo "backup2=$BACKUP2"

RESULT2=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( \WPGuard\NCBackup\Settings::get_site_id() . '/backups' );
  \$m = \$client->get_json( \$parent . '/$BACKUP2/manifest.json', 64*1024*1024 );
  \$by_path = array();
  foreach ( \$m['files'] as \$f ) { \$by_path[\$f['path']] = \$f; }
  \$children = \$client->list_children( \$parent . '/$BACKUP2' );
  \$has_part = false;
  foreach ( \$children as \$c ) { if ( false !== strpos( \$c, 'files.part' ) ) { \$has_part = true; } }
  echo \$m['backup_mode'] . '|' . \$m['parent_backup_id'] . '|' . \$by_path['$TRACK_DIR/fileA.txt']['source_backup_id'] . '|' . \$by_path['$TRACK_DIR/fileB.txt']['source_backup_id'] . '|' . ( \$has_part ? 'HAS_PART' : 'NO_PART' );
")
[ "$RESULT2" = "incremental|$BACKUP1|$BACKUP1|$BACKUP1|NO_PART" ] || { echo "FAIL: backup 2 expected incremental, parent=backup1, both files still sourced from backup1, and NO files.part-*.zip uploaded at all; got '$RESULT2'"; exit 1; }
echo "OK  backup 2 is incremental; unchanged files carried forward to backup1 verbatim; confirmed on the real server that NOT A SINGLE file part was uploaded for a backup with zero file changes"

echo
echo "=== Mutate: change fileB, delete fileC, add fileD ==="
docker exec wpguard-test-wp-source-1 sh -c "
  printf 'fileB-v2' > /var/www/html/$TRACK_DIR/fileB.txt
  rm /var/www/html/$TRACK_DIR/fileC.txt
  printf 'fileD-v1' > /var/www/html/$TRACK_DIR/fileD.txt
"

echo
echo "=== Backup 3 (expected: incremental depth 2; fileA still from backup1; fileB and fileD from backup3 itself; fileC absent entirely -- deletions must not resurrect) ==="
wp_source wpguard-nc-backup run > "$SCRATCH/run3.log" 2>&1 || true
tail -3 "$SCRATCH/run3.log"
BACKUP3=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::get_latest()["backup_id"];')
STATUS3=$(wp_source eval "echo \WPGuard\NCBackup\Job_Store::get_latest()['status'];")
[ "$STATUS3" = "verified" ] || { echo "FAIL: backup 3 did not verify"; exit 1; }
echo "backup3=$BACKUP3"

RESULT3=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$m = \$client->get_json( \$client->relative_path( \WPGuard\NCBackup\Settings::get_site_id() . '/backups/$BACKUP3/manifest.json' ), 64*1024*1024 );
  \$by_path = array();
  foreach ( \$m['files'] as \$f ) { \$by_path[\$f['path']] = \$f; }
  \$c_present = isset( \$by_path['$TRACK_DIR/fileC.txt'] ) ? 'PRESENT' : 'ABSENT';
  echo \$m['backup_mode'] . '|' . \$by_path['$TRACK_DIR/fileA.txt']['source_backup_id'] . '|' . \$by_path['$TRACK_DIR/fileB.txt']['source_backup_id'] . '|' . \$by_path['$TRACK_DIR/fileD.txt']['source_backup_id'] . '|' . \$c_present;
")
[ "$RESULT3" = "incremental|$BACKUP1|$BACKUP3|$BACKUP3|ABSENT" ] || { echo "FAIL: expected fileA still from backup1, fileB+fileD from backup3, fileC absent; got '$RESULT3'"; exit 1; }
echo "OK  unchanged file still points at the original backup; changed+new files captured fresh; deleted file is absent from the manifest entirely (no resurrection risk on restore)"

echo
echo "=== Restore reconstruction: walk backup3's manifest, fetch each distinct source part exactly once, extract, compare byte-for-byte against live content ==="
RESTORE_RESULT=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent_rel = \$client->relative_path( \WPGuard\NCBackup\Settings::get_site_id() . '/backups' );
  \$m = \$client->get_json( \$parent_rel . '/$BACKUP3/manifest.json', 64*1024*1024 );
  \$wanted = array('$TRACK_DIR/fileA.txt' => 'fileA-v1', '$TRACK_DIR/fileB.txt' => 'fileB-v2', '$TRACK_DIR/fileD.txt' => 'fileD-v1');
  \$by_path = array();
  foreach ( \$m['files'] as \$f ) { \$by_path[\$f['path']] = \$f; }
  \$tmp_dir = sys_get_temp_dir() . '/wpguard-restore-test';
  @mkdir( \$tmp_dir );
  \$part_cache = array();
  \$failures = array();
  foreach ( \$wanted as \$path => \$expected ) {
    \$entry = \$by_path[\$path] ?? null;
    if ( null === \$entry ) { \$failures[] = \"\$path: missing from manifest\"; continue; }
    \$cache_key = \$entry['source_backup_id'] . '/' . \$entry['source_part'];
    if ( ! isset( \$part_cache[\$cache_key] ) ) {
      \$local_part = \$tmp_dir . '/' . md5(\$cache_key) . '.zip';
      \$dl = \$client->download_to_file( \$parent_rel . '/' . \$entry['source_backup_id'] . '/' . \$entry['source_part'], \$local_part, 100*1024*1024 );
      if ( is_wp_error( \$dl ) ) { \$failures[] = \"\$path: download failed: \" . \$dl->get_error_message(); continue; }
      \$part_cache[\$cache_key] = \$local_part;
    }
    \$zip = new ZipArchive();
    \$zip->open( \$part_cache[\$cache_key] );
    \$content = \$zip->getFromName( \$path );
    \$zip->close();
    if ( false === \$content ) { \$failures[] = \"\$path: not found inside \" . \$entry['source_part']; continue; }
    if ( hash('sha256', \$content) !== \$entry['sha256'] ) { \$failures[] = \"\$path: sha256 mismatch against manifest entry\"; continue; }
    if ( \$content !== \$expected ) { \$failures[] = \"\$path: expected '\$expected', got '\$content'\"; continue; }
  }
  echo empty(\$failures) ? 'RESTORE_OK:' . count(\$part_cache) . '_distinct_parts_fetched' : 'RESTORE_FAIL:' . implode('; ', \$failures);
")
echo "$RESTORE_RESULT"
[[ "$RESTORE_RESULT" == RESTORE_OK:* ]] || { echo "FAIL: chain restore reconstruction did not match live content"; exit 1; }
echo "OK  all 3 files restored byte-identical by walking the final manifest alone, pulling fileA from backup1's part and fileB/fileD from backup3's part"

echo
echo "=== Chain-aware retention: keep=1 must protect backup1 (fileA's source, still depended on by the kept backup3) even though it is the oldest by name, while reclaiming backup2 (fully superseded, nothing depends on it) ==="
echo "Note: the real backups folder is shared with other E2E scripts run earlier this session (same precedent as run-e2e-retention.sh Case E), so other unrelated old verified backups may legitimately also be deleted here -- the real invariant under test is backup1 survives and backup2 does not, not an exact deleted-list match."
PRUNE_RESULT=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( \WPGuard\NCBackup\Settings::get_site_id() . '/backups' );
  \$r = \WPGuard\NCBackup\Retention::prune( \$client, \$parent, 1 );
  echo is_wp_error(\$r) ? 'ERROR:' . \$r->get_error_message() : implode(',', \$r['deleted']);
")
echo "deleted: $PRUNE_RESULT"
[[ "$PRUNE_RESULT" != ERROR:* ]] || { echo "FAIL: prune() errored: $PRUNE_RESULT"; exit 1; }
[[ ",$PRUNE_RESULT," == *",$BACKUP2,"* ]] || { echo "FAIL: expected backup2 ($BACKUP2) to be among the deleted (fully superseded, nothing depends on it), got '$PRUNE_RESULT'"; exit 1; }
[[ ",$PRUNE_RESULT," != *",$BACKUP1,"* ]] || { echo "FAIL: backup1 ($BACKUP1) must survive -- it is still depended on by kept backup3 for fileA -- but it was deleted: '$PRUNE_RESULT'"; exit 1; }
[[ ",$PRUNE_RESULT," != *",$BACKUP3,"* ]] || { echo "FAIL: backup3 ($BACKUP3) is the one being kept and must never be deleted by its own prune call, got '$PRUNE_RESULT'"; exit 1; }
echo "OK  backup2 (fully superseded, no remaining dependents) reclaimed; backup1 (still depended on for fileA) correctly survived despite being the oldest by name; backup3 (the kept backup) untouched"

RETENTION_CLEANUP=$(wp_source eval "
  \WPGuard\NCBackup\Settings::save(array(
    'nextcloud_url' => \WPGuard\NCBackup\Settings::get_nextcloud_url(),
    'nextcloud_user' => \WPGuard\NCBackup\Settings::get_nextcloud_user(),
    'remote_folder' => \WPGuard\NCBackup\Settings::get_remote_folder(),
    'incremental_enabled' => false,
    'retention_count' => 0,
  ));
  echo 'ok';
")
docker exec wpguard-test-wp-source-1 sh -c "rm -rf /var/www/html/$TRACK_DIR"
echo
echo "=== Cleanup: incremental disabled, retention reset to keep-all, tracked files removed ($RETENTION_CLEANUP) ==="

echo
echo "=== INCREMENTAL BACKUP REGRESSION TEST PASSED ==="
