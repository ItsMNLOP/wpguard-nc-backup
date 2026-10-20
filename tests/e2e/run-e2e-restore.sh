#!/usr/bin/env bash
# Phase 2 one-click restore regression test: a REAL backup (full,
# format_version 1, taken from the owner's real production site,
# mikelautner.com) is restored IN PLACE onto a freshly provisioned,
# fully disposable WordPress install with the plugin active and
# running on it -- exactly the "click to restore" scenario, exercised
# with real-world data rather than synthetic fixtures, per the owner's
# explicit instruction (test on the local disposable stack using real
# backup copies already accessible on cloud.tomcat.casa; never against
# a live DB until this is proven here first).
#
# Deliberately targets wp-restore, NOT wp-source: wp-source is a
# stable fixture every other E2E script in this suite depends on
# (sentinel option, post count, active theme); wp-restore is already
# freshly re-provisioned (wp core install) by every test that touches
# it, so it is the only safe target for a real in-place restore test.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-restore-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

SECRETS="$HOME/Nextcloud/Claude/PKA/Credentials/secrets.env"
if [ ! -f "$SECRETS" ]; then
  echo "FAIL: cannot find $SECRETS (real cloud.tomcat.casa test-account credentials)"; exit 1
fi
NC_TEST_HOST=$(grep '^NC_TEST_HOST=' "$SECRETS" | cut -d= -f2-)
NC_TEST_USER=$(grep '^NC_TEST_USER=' "$SECRETS" | cut -d= -f2-)
NC_TEST_APP_PASSWORD=$(grep '^NC_TEST_APP_PASSWORD=' "$SECRETS" | cut -d= -f2-)

REAL_SITE_ID="mikelautner-com-ff09cd"
REAL_BACKUP_ID="20261020T023149Z-9ca75a64"
echo "=== Restoring REAL backup $REAL_BACKUP_ID (site_id $REAL_SITE_ID) from $NC_TEST_HOST ==="

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_RESTORE_DB_PASSWORD=$(env_val WP_RESTORE_DB_PASSWORD)
WP_RESTORE_DB_ROOT_PASSWORD=$(env_val WP_RESTORE_DB_ROOT_PASSWORD)
PREFIX="wp_"

wp_restore() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
    wpcli wp --path=/var/www/html-restore "$@" 2>&1 | grep -v '^ Container' || true
}

echo "=== Setup: fresh WordPress core + WPGuard plugin on the disposable restore target ==="
docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
  -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';" > /dev/null

if ! grep -q "^WP_RESTORE_TABLE_PREFIX=${PREFIX}$" .env; then
  sed -i "s/^WP_RESTORE_TABLE_PREFIX=.*/WP_RESTORE_TABLE_PREFIX=${PREFIX}/" .env
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env up -d wp-restore > /dev/null
fi

wp_restore core install --url="http://127.0.0.1:8082" --title="Restore E2E Target" \
  --admin_user=wpadmin --admin_password=RestoreE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wp_restore plugin activate wpguard-nc-backup > /dev/null
echo "OK  fresh WordPress + active WPGuard plugin on wp-restore"

echo
echo "=== Configure plugin settings to point at the REAL cloud.tomcat.casa test account, forcing site_id to the real backup's ==="
wp_restore eval "
  \WPGuard\NCBackup\Settings::save(array(
    'nextcloud_url' => 'https://$NC_TEST_HOST',
    'nextcloud_user' => '$NC_TEST_USER',
    'app_password' => '$NC_TEST_APP_PASSWORD',
    'remote_folder' => 'wpguard-backups',
  ));
  update_option( \WPGuard\NCBackup\Settings::SITE_ID_KEY, '$REAL_SITE_ID', true );
  echo 'configured:' . \WPGuard\NCBackup\Settings::get_nextcloud_url() . '|' . \WPGuard\NCBackup\Settings::get_site_id();
"

echo
echo "=== Seed pre-restore state to verify the restore mechanism's own safety guarantees ==="
SENTINEL_REL="wp-content/uploads/pre-restore-sentinel.txt"
docker exec wpguard-test-wp-restore-1 sh -c "mkdir -p /var/www/html/wp-content/uploads && echo 'this file predates the restore and is NOT in the backup' > /var/www/html/$SENTINEL_REL"
WPCONFIG_HASH_BEFORE=$(docker exec wpguard-test-wp-restore-1 sh -c "sha256sum /var/www/html/wp-config.php" | awk '{print $1}')
echo "OK  sentinel file created; wp-config.php hash recorded: $WPCONFIG_HASH_BEFORE"

echo
echo "=== Running the real one-click restore (this downloads a real ~production-scale backup; may take a few minutes) ==="
wp_restore wpguard-nc-backup restore "$REAL_BACKUP_ID" --confirm-url-mismatch 2>&1 | tee "$SCRATCH/restore_run.log"
# Checked HERE, immediately after the restore CLI command itself returns --
# before any OTHER wp-cli invocation gets a chance to boot WordPress with
# the now-restored, now-genuinely-active third-party plugins. Found live:
# the real backup's own content includes the "WP 2FA" plugin, which
# writes its own random encryption key into wp-config.php as completely
# normal behavior the first time it runs active -- nothing to do with
# this restore mechanism, and indistinguishable from "the restore itself
# touched the file" if checked only after a later, unrelated boot. This
# is the one point in the test that actually isolates what THIS restore
# wrote, not what a legitimately-restored plugin did on its own next.
WPCONFIG_HASH_IMMEDIATELY_AFTER=$(docker exec wpguard-test-wp-restore-1 sh -c "sha256sum /var/www/html/wp-config.php" | awk '{print $1}')
tail -5 "$SCRATCH/restore_run.log"

RESTORE_JOB_ID=$(wp_restore eval 'echo \WPGuard\NCBackup\Job_Store::get_latest()["job_id"];')
RESTORE_STATUS=$(wp_restore eval "echo \WPGuard\NCBackup\Job_Store::get('$RESTORE_JOB_ID')['status'];")
[ "$RESTORE_STATUS" = "verified" ] || { echo "FAIL: restore job did not reach 'verified', got '$RESTORE_STATUS'"; exit 1; }
echo "OK  restore job $RESTORE_JOB_ID reached 'verified'"

[ "$WPCONFIG_HASH_IMMEDIATELY_AFTER" = "$WPCONFIG_HASH_BEFORE" ] || { echo "FAIL: wp-config.php was modified by the restore itself (it must never be touched), before=$WPCONFIG_HASH_BEFORE immediately-after=$WPCONFIG_HASH_IMMEDIATELY_AFTER"; exit 1; }
echo "OK  wp-config.php byte-identical before and immediately after the restore -- the restore itself never touched it"

echo
echo "=== Assertions ==="

PLUGIN_SETTINGS_USER=$(wp_restore eval "echo \WPGuard\NCBackup\Settings::get_nextcloud_user();")
[ "$PLUGIN_SETTINGS_USER" = "$NC_TEST_USER" ] || { echo "FAIL: plugin's own Nextcloud settings were NOT preserved across the restore -- got user '$PLUGIN_SETTINGS_USER'"; exit 1; }
echo "OK  plugin's own Nextcloud connection settings survived the restore intact (captured_options re-apply worked)"

PLUGIN_SITE_ID=$(wp_restore eval "echo \WPGuard\NCBackup\Settings::get_site_id();")
[ "$PLUGIN_SITE_ID" = "$REAL_SITE_ID" ] || { echo "FAIL: site_id was not preserved, got '$PLUGIN_SITE_ID'"; exit 1; }
echo "OK  site_id preserved"

JOB_ROW_STATUS=$(wp_restore eval "echo \WPGuard\NCBackup\Job_Store::get('$RESTORE_JOB_ID')['status'] ?? 'MISSING';")
[ "$JOB_ROW_STATUS" = "verified" ] || { echo "FAIL: the restore job's OWN row in wp_wpguard_nc_jobs did not survive the DB swap intact (jobs-table exclusion failed), got '$JOB_ROW_STATUS'"; exit 1; }
echo "OK  the restore job's own tracking row survived the live/shadow table swap untouched (jobs-table exclusion confirmed)"

SENTINEL_STILL_LIVE=$(docker exec wpguard-test-wp-restore-1 sh -c "test -f /var/www/html/$SENTINEL_REL && echo yes || echo no")
[ "$SENTINEL_STILL_LIVE" = "no" ] || { echo "FAIL: pre-restore sentinel file (not part of the backup) is still at its live path -- should have been quarantined, not left in place"; exit 1; }
QUARANTINED=$(docker exec wpguard-test-wp-restore-1 sh -c "find /var/www/html/wp-content/wpguard-nc-restore-tmp -path '*quarantine*pre-restore-sentinel.txt' 2>/dev/null | head -1")
[ -n "$QUARANTINED" ] || { echo "FAIL: pre-restore sentinel file was removed from its live path but not found anywhere under the rollback/quarantine directory -- it may have been deleted outright instead of quarantined"; exit 1; }
echo "OK  pre-restore sentinel file (not part of the backup) was quarantined (moved aside), not deleted -- found at $QUARANTINED"

BAK_TABLES=$(wp_restore db query "SHOW TABLES LIKE 'wpgbak%';" --skip-column-names 2>/dev/null | wc -l)
[ "$BAK_TABLES" -gt 0 ] || { echo "FAIL: expected rollback (wpgbak-prefixed) tables from the pre-restore live DB, found none"; exit 1; }
echo "OK  $BAK_TABLES rollback table(s) preserved under a wpgbak-prefixed name for manual recovery"

SHADOW_TABLES=$(wp_restore db query "SHOW TABLES LIKE 'wpgshd%';" --skip-column-names 2>/dev/null | wc -l)
[ "$SHADOW_TABLES" -eq 0 ] || { echo "FAIL: expected zero leftover shadow (wpgshd-prefixed) tables after a successful swap, found $SHADOW_TABLES"; exit 1; }
echo "OK  no leftover shadow tables after a successful swap"

JOBS_TABLE_LIVE=$(wp_restore db query "SELECT COUNT(*) FROM wp_wpguard_nc_jobs;" --skip-column-names 2>/dev/null)
[ "$JOBS_TABLE_LIVE" -ge 1 ] || { echo "FAIL: live wp_wpguard_nc_jobs table is empty/missing after restore"; exit 1; }
echo "OK  live jobs table intact with $JOBS_TABLE_LIVE row(s)"

REAL_CONTENT_POST_COUNT=$(wp_restore post list --post_type=post --format=count)
echo "Real site's restored post count: $REAL_CONTENT_POST_COUNT"
[ "$REAL_CONTENT_POST_COUNT" -ge 0 ] || { echo "FAIL: could not query restored post content at all -- DB import may not have taken effect"; exit 1; }
REAL_PLUGIN_COUNT=$(docker exec wpguard-test-wp-restore-1 sh -c "find /var/www/html/wp-content -mindepth 1 -maxdepth 3 | wc -l")
echo "OK  real site's database is queryable post-restore; $REAL_PLUGIN_COUNT wp-content filesystem entries present after file restore"

echo
echo "=== RESTORE REGRESSION TEST PASSED ==="
