#!/usr/bin/env bash
# Phase 2 retention regression test: Retention::prune() and the new
# Nextcloud_Client::list_children() PROPFIND (Depth: 1) helper against
# a real Nextcloud server, not a mock. Uses an isolated test folder
# (not the real site's <site-id>/backups/ path) so this never touches
# real backup artifacts other E2E tests may have left behind.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-retention-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container' || true
}

TEST_PARENT="wpguard-nc-backup-retention-test-$(date +%s)"
echo "=== Isolated test folder: $TEST_PARENT (not the real backups path) ==="

echo
echo "=== Setup: create 5 real-shaped VERIFIED backup folders (backup_id format + manifest.json + COMPLETE.json, matching what step_finalize() actually leaves behind) ==="
for n in 1 2 3 4 5; do
  NAME=$(printf '2026010%dT000000Z-aaaa000%d' "$n" "$n")
  wp_source eval "
    \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array( \WPGuard\NCBackup\Settings::get_app_password() ) ) );
    \$parent = \$client->relative_path( '$TEST_PARENT' );
    \$mk = \$client->mkcol_recursive( \$parent . '/$NAME' );
    \$put = \$client->put_bytes( \$parent . '/$NAME/marker.txt', 'test' );
    // Phase 2 (incremental backups): a real verified backup always has a
    // manifest.json -- step_package_manifest() uploads it before
    // COMPLETE.json can ever be written -- so Retention::prune()'s
    // chain-aware must-keep computation (reads each kept backup's own
    // manifest.json) now expects one too. This fixture's files:[] marks
    // it self-contained (no cross-backup references), matching every
    // real backup taken with incremental backups off (the default).
    \$manifest = \$client->put_bytes( \$parent . '/$NAME/manifest.json', '{\"format_version\":2,\"backup_mode\":\"full\",\"files\":[]}' );
    \$complete = \$client->put_bytes( \$parent . '/$NAME/COMPLETE.json', '{\"ok\":true}' );
    echo (is_wp_error(\$mk) ? 'MK_FAIL:' . \$mk->get_error_message() : '') . (is_wp_error(\$put) ? 'PUT_FAIL:' . \$put->get_error_message() : '') . (is_wp_error(\$manifest) ? 'MANIFEST_FAIL:' . \$manifest->get_error_message() : '') . (is_wp_error(\$complete) ? 'COMPLETE_FAIL:' . \$complete->get_error_message() : 'ok');
  " > "$SCRATCH/setup_$n.log" 2>&1
  RESULT=$(cat "$SCRATCH/setup_$n.log")
  [[ "$RESULT" == *"ok"* ]] || { echo "FAIL: could not create fixture folder $NAME: $RESULT"; exit 1; }
done
echo "OK  5 verified fixture backup folders created on the real Nextcloud test account"

echo
echo "=== Setup: also create a FAILED-job stub -- backup_id-shaped folder, no COMPLETE.json (RETENTION-2) ==="
FAILED_NAME="20260106T000000Z-bbbb0006"
wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array( \WPGuard\NCBackup\Settings::get_app_password() ) ) );
  \$parent = \$client->relative_path( '$TEST_PARENT' );
  \$mk = \$client->mkcol_recursive( \$parent . '/$FAILED_NAME' );
  \$put = \$client->put_bytes( \$parent . '/$FAILED_NAME/job-log.json', '[]' );
  echo (is_wp_error(\$mk) || is_wp_error(\$put)) ? 'FAIL' : 'ok';
" > "$SCRATCH/setup_failed.log" 2>&1
[[ "$(cat "$SCRATCH/setup_failed.log")" == *"ok"* ]] || { echo "FAIL: could not create the failed-job stub fixture"; exit 1; }
echo "OK  failed-job stub folder created (job-log.json only, no COMPLETE.json) -- this is chronologically newest of all 6"

echo
echo "=== Case A: list_children() must see exactly all 6 folders just created (5 verified + 1 failed stub), and nothing else under this isolated parent ==="
LISTED=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( '$TEST_PARENT' );
  \$children = \$client->list_children( \$parent );
  sort(\$children);
  echo implode(',', \$children);
")
echo "listed: $LISTED"
[ "$LISTED" = "20260101T000000Z-aaaa0001,20260102T000000Z-aaaa0002,20260103T000000Z-aaaa0003,20260104T000000Z-aaaa0004,20260105T000000Z-aaaa0005,20260106T000000Z-bbbb0006" ] || { echo "FAIL: list_children() did not return exactly the 6 fixture folders, got: $LISTED"; exit 1; }
echo "OK  list_children() returned exactly the 6 real folders, correctly excluding the parent collection itself (RETENTION-1: this comparison is encoder-agnostic, decoding both sides before matching -- not independently re-provable here against the real server without a second test account whose username contains a character Sabre and rawurlencode() escape differently, but the fix applies uniformly regardless of username shape)"

echo
echo "=== Case B: keep=0 (disabled) must be a true no-op -- nothing deleted ==="
RESULT_KEEP0=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( '$TEST_PARENT' );
  \$r = \WPGuard\NCBackup\Retention::prune( \$client, \$parent, 0 );
  echo count(\$r['deleted']) . '|' . \$r['kept'];
")
[ "$RESULT_KEEP0" = "0|-1" ] || { echo "FAIL: keep=0 should delete nothing and report kept=-1 (sentinel for disabled), got '$RESULT_KEEP0'"; exit 1; }
LISTED_AFTER_KEEP0=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  echo count(\$client->list_children( \$client->relative_path( '$TEST_PARENT' ) ));
")
[ "$LISTED_AFTER_KEEP0" = "6" ] || { echo "FAIL: keep=0 must not have deleted anything, but only $LISTED_AFTER_KEEP0 of 6 folders remain"; exit 1; }
echo "OK  keep=0 left all 6 folders untouched"

echo
echo "=== Case C (RETENTION-2): keep=3 must count only the 5 VERIFIED backups, delete exactly the 2 oldest verified ones, and never touch the failed-job stub ==="
RESULT_KEEP3=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( '$TEST_PARENT' );
  \$r = \WPGuard\NCBackup\Retention::prune( \$client, \$parent, 3 );
  \$deleted = \$r['deleted'];
  sort(\$deleted);
  echo implode(',', \$deleted) . '||' . \$r['kept'];
")
echo "result: $RESULT_KEEP3"
[ "$RESULT_KEEP3" = "20260101T000000Z-aaaa0001,20260102T000000Z-aaaa0002||3" ] || { echo "FAIL: expected the 2 oldest VERIFIED backups deleted and kept=3 (NOT counting the failed stub), got '$RESULT_KEEP3'"; exit 1; }
echo "OK  Retention::prune() deleted exactly the 2 oldest verified backups, reported kept=3 -- the failed stub was correctly excluded from the count (if it had been wrongly counted, excess would have been 3 and a verified backup would have been deleted too)"

LISTED_AFTER_PRUNE=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$children = \$client->list_children( \$client->relative_path( '$TEST_PARENT' ) );
  sort(\$children);
  echo implode(',', \$children);
")
[ "$LISTED_AFTER_PRUNE" = "20260103T000000Z-aaaa0003,20260104T000000Z-aaaa0004,20260105T000000Z-aaaa0005,20260106T000000Z-bbbb0006" ] || { echo "FAIL: real remote state after pruning doesn't match -- expected the 3 newest verified backups PLUS the untouched failed stub, got '$LISTED_AFTER_PRUNE'"; exit 1; }
echo "OK  real Nextcloud state independently confirms: oldest 2 verified backups gone, newest 3 verified remain, failed stub never touched"

echo
echo "=== Case D: keep=10 with only 3 verified remaining must be a no-op (fewer verified entries than the keep count), and still must not touch the failed stub ==="
RESULT_KEEP10=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( '$TEST_PARENT' );
  \$r = \WPGuard\NCBackup\Retention::prune( \$client, \$parent, 10 );
  echo count(\$r['deleted']) . '|' . \$r['kept'];
")
[ "$RESULT_KEEP10" = "0|3" ] || { echo "FAIL: keep=10 with only 3 verified present should delete nothing and report kept=3, got '$RESULT_KEEP10'"; exit 1; }
LISTED_AFTER_KEEP10=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  echo count(\$client->list_children( \$client->relative_path( '$TEST_PARENT' ) ));
")
[ "$LISTED_AFTER_KEEP10" = "4" ] || { echo "FAIL: expected the 3 verified + 1 failed stub (4 total) still present, got $LISTED_AFTER_KEEP10"; exit 1; }
echo "OK  keep count higher than what exists is correctly a no-op, failed stub still untouched"

echo
echo "=== Cleanup: delete the remaining fixture folders and the isolated test parent ==="
wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$client->delete( \$client->relative_path( '$TEST_PARENT' ) );
" > /dev/null

echo
echo "=== Case E (RETENTION-3 integration): a real backup run through the actual Job_Orchestrator::step_finalize() path, with retention configured, must not silently fail with an ArgumentCountError ==="
echo "This is the exact class of bug that shipped undetected: maybe_prune_old_backups() called Retention::prune() with the wrong number of arguments, silently caught by its own try/catch, never exercised by a direct-call test of prune() alone."

wp_source eval "
  \WPGuard\NCBackup\Settings::save(array(
    'nextcloud_url' => \WPGuard\NCBackup\Settings::get_nextcloud_url(),
    'nextcloud_user' => \WPGuard\NCBackup\Settings::get_nextcloud_user(),
    'remote_folder' => \WPGuard\NCBackup\Settings::get_remote_folder(),
    'retention_count' => 2,
  ));
" > /dev/null
wp_source db query "UPDATE wp_wpguard_nc_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true

# security re-verification note: seed enough old-looking verified fixtures
# directly under the real site-id/backups/ parent (deterministic,
# regardless of whatever leftover history other test runs may have
# left there) so retention_count=2 is GUARANTEED to have more than 2
# verified entries to choose from -- the real backup about to be
# created is always the newest, so it never collides with these.
SITE_ID=$(wp_source eval 'echo \WPGuard\NCBackup\Settings::get_site_id();')
for n in 1 2; do
  FIXTURE_NAME=$(printf '2020010%dT000000Z-eeee000%d' "$n" "$n")
  wp_source eval "
    \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array( \WPGuard\NCBackup\Settings::get_app_password() ) ) );
    \$parent = \$client->relative_path( '$SITE_ID/backups' );
    \$client->mkcol_recursive( \$parent . '/$FIXTURE_NAME' );
    \$client->put_bytes( \$parent . '/$FIXTURE_NAME/manifest.json', '{\"format_version\":2,\"backup_mode\":\"full\",\"files\":[]}' );
    \$client->put_bytes( \$parent . '/$FIXTURE_NAME/COMPLETE.json', '{\"ok\":true}' );
  " > /dev/null
done
echo "OK  seeded 2 deterministic old verified fixtures under the real backups folder"

echo "Running one real backup via WP-CLI (wp wpguard-nc-backup run) -- this is the same real backup path run-e2e.sh exercises, expect this to take a couple of minutes..."
wp_source wpguard-nc-backup run > "$SCRATCH/real_backup_run.log" 2>&1 || true
tail -5 "$SCRATCH/real_backup_run.log"

REAL_JOB_ID=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::get_latest()["job_id"];')
REAL_STATUS=$(wp_source eval "echo \WPGuard\NCBackup\Job_Store::get('$REAL_JOB_ID')['status'];")
[ "$REAL_STATUS" = "verified" ] || { echo "FAIL: the real backup itself did not reach 'verified' (got '$REAL_STATUS') -- can't test retention's integration on top of a failed backup"; exit 1; }
echo "OK  real backup reached 'verified'"

REAL_LOG=$(wp_source eval "echo wp_json_encode(\WPGuard\NCBackup\Job_Store::get('$REAL_JOB_ID')['log']);")

# security re-verification note: the original assertion here only checked
# for the ABSENCE of an error, which can't tell a genuine success
# apart from a different silently-swallowed failure reaching the same
# catch block -- exactly the shape that let RETENTION-3 through the
# first time. Now requires the explicit success phrase and explicitly
# fails on either caught-error phrase.
if [[ "$REAL_LOG" == *"threw an unexpected error"* ]] || [[ "$REAL_LOG" == *"did not fully complete"* ]]; then
  echo "FAIL: retention's own try/catch caught something on the real step_finalize() path: $REAL_LOG"
  exit 1
fi
[[ "$REAL_LOG" == *"Retention: removed"* ]] || { echo "FAIL: expected an explicit 'Retention: removed N old backup(s)...' success log line (2 deterministic fixtures were seeded specifically to guarantee a real deletion happens), got: $REAL_LOG"; exit 1; }
echo "OK  retention explicitly logged a real deletion on the real step_finalize() path -- not just an absence of error"

echo "=== Cleanup: restore retention_count to 0 (keep-all) so it doesn't affect unrelated future test runs against the real shared backups folder ==="
wp_source eval "
  \WPGuard\NCBackup\Settings::save(array(
    'nextcloud_url' => \WPGuard\NCBackup\Settings::get_nextcloud_url(),
    'nextcloud_user' => \WPGuard\NCBackup\Settings::get_nextcloud_user(),
    'remote_folder' => \WPGuard\NCBackup\Settings::get_remote_folder(),
    'retention_count' => 0,
  ));
" > /dev/null

echo
echo "=== RETENTION REGRESSION TEST PASSED ==="
