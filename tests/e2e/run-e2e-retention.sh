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
echo "=== Setup: create 5 fake backup folders with sortable, chronological-looking names ==="
for n in 1 2 3 4 5; do
  NAME=$(printf '2026010%dT000000Z-aaaa000%d' "$n" "$n")
  wp_source eval "
    \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array( \WPGuard\NCBackup\Settings::get_app_password() ) ) );
    \$parent = \$client->relative_path( '$TEST_PARENT' );
    \$mk = \$client->mkcol_recursive( \$parent . '/$NAME' );
    \$put = \$client->put_bytes( \$parent . '/$NAME/marker.txt', 'test' );
    echo (is_wp_error(\$mk) ? 'MK_FAIL:' . \$mk->get_error_message() : '') . (is_wp_error(\$put) ? 'PUT_FAIL:' . \$put->get_error_message() : 'ok');
  " > "$SCRATCH/setup_$n.log" 2>&1
  RESULT=$(cat "$SCRATCH/setup_$n.log")
  [[ "$RESULT" == *"ok"* ]] || { echo "FAIL: could not create fixture folder $NAME: $RESULT"; exit 1; }
done
echo "OK  5 fixture backup folders created on the real Nextcloud test account"

echo
echo "=== Case A: list_children() must see exactly the 5 folders just created, and nothing else under this isolated parent ==="
LISTED=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( '$TEST_PARENT' );
  \$children = \$client->list_children( \$parent );
  sort(\$children);
  echo implode(',', \$children);
")
echo "listed: $LISTED"
[ "$LISTED" = "20260101T000000Z-aaaa0001,20260102T000000Z-aaaa0002,20260103T000000Z-aaaa0003,20260104T000000Z-aaaa0004,20260105T000000Z-aaaa0005" ] || { echo "FAIL: list_children() did not return exactly the 5 fixture folders, got: $LISTED"; exit 1; }
echo "OK  list_children() returned exactly the 5 real folders, correctly excluding the parent collection itself"

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
[ "$LISTED_AFTER_KEEP0" = "5" ] || { echo "FAIL: keep=0 must not have deleted anything, but only $LISTED_AFTER_KEEP0 of 5 folders remain"; exit 1; }
echo "OK  keep=0 left all 5 folders untouched"

echo
echo "=== Case C: keep=3 must delete exactly the 2 oldest, keeping the 3 most recent ==="
RESULT_KEEP3=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( '$TEST_PARENT' );
  \$r = \WPGuard\NCBackup\Retention::prune( \$client, \$parent, 3 );
  \$deleted = \$r['deleted'];
  sort(\$deleted);
  echo implode(',', \$deleted) . '||' . \$r['kept'];
")
echo "result: $RESULT_KEEP3"
[ "$RESULT_KEEP3" = "20260101T000000Z-aaaa0001,20260102T000000Z-aaaa0002||3" ] || { echo "FAIL: expected the 2 oldest deleted and kept=3, got '$RESULT_KEEP3'"; exit 1; }
echo "OK  Retention::prune() deleted exactly the 2 oldest, reported kept=3"

LISTED_AFTER_PRUNE=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$children = \$client->list_children( \$client->relative_path( '$TEST_PARENT' ) );
  sort(\$children);
  echo implode(',', \$children);
")
[ "$LISTED_AFTER_PRUNE" = "20260103T000000Z-aaaa0003,20260104T000000Z-aaaa0004,20260105T000000Z-aaaa0005" ] || { echo "FAIL: real remote state after pruning doesn't match -- expected only the 3 newest, got '$LISTED_AFTER_PRUNE'"; exit 1; }
echo "OK  real Nextcloud state independently confirms: oldest 2 gone, newest 3 remain (and the just-created-newest survives by construction)"

echo
echo "=== Case D: keep=10 with only 3 remaining must be a no-op (fewer entries than the keep count) ==="
RESULT_KEEP10=$(wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$parent = \$client->relative_path( '$TEST_PARENT' );
  \$r = \WPGuard\NCBackup\Retention::prune( \$client, \$parent, 10 );
  echo count(\$r['deleted']) . '|' . \$r['kept'];
")
[ "$RESULT_KEEP10" = "0|3" ] || { echo "FAIL: keep=10 with only 3 present should delete nothing and report kept=3, got '$RESULT_KEEP10'"; exit 1; }
echo "OK  keep count higher than what exists is correctly a no-op"

echo
echo "=== Cleanup: delete the remaining 3 fixture folders and the isolated test parent ==="
wp_source eval "
  \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( new \WPGuard\NCBackup\Logger( array() ) );
  \$client->delete( \$client->relative_path( '$TEST_PARENT' ) );
" > /dev/null

echo
echo "=== RETENTION REGRESSION TEST PASSED ==="
