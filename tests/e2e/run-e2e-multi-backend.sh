#!/usr/bin/env bash
# Phase 3 (multi-backend): re-runs the connection / backup / incremental /
# retention / restore matrix against ownCloud classic and OpenCloud
# instead of Nextcloud, proving Nextcloud_Client's WebDAV dialect holds
# against each with zero backend-specific code (see STATUS.md and
# docs/threat-model.md T22). Requires the multi-backend compose profile
# already up:
#   docker compose --profile multi-backend up -d db-owncloud owncloud oc-proxy opencloud
#
# Diagnostic-log-upload-on-failure is deliberately NOT re-tested per
# backend here: Job_Orchestrator::upload_diagnostic_log() calls the same
# Nextcloud_Client::put_bytes() this script already exercises against
# both backends below, and has no backend-specific branching of its own.
# run-e2e-cancel-job.sh / run-e2e-lock-race.sh / run-e2e-stale-lock-
# recovery.sh already exercise that failure path against Nextcloud;
# re-running those per backend would retest Job_Orchestrator's state
# machine again, not backend compatibility.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-multi-backend-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)
WP_RESTORE_DB_PASSWORD=$(env_val WP_RESTORE_DB_PASSWORD)
WP_RESTORE_DB_ROOT_PASSWORD=$(env_val WP_RESTORE_DB_ROOT_PASSWORD)
OC_BACKUP_USER=$(env_val OC_BACKUP_USER)
OC_BACKUP_USER_PASSWORD=$(env_val OC_BACKUP_USER_PASSWORD)
OPENCLOUD_BACKUP_USER=$(env_val OPENCLOUD_BACKUP_USER)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container'
}
wp_source_scratch() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    -v "$SCRATCH:/scratch:ro" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container'
}
wp_restore() {
  local prefix="$1"; shift
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$prefix" \
    wpcli wp --path=/var/www/html-restore "$@" 2>&1 | grep -v '^ Container'
}

echo "=== Capturing wp-source's current plugin settings (restored at exit, whatever happens) ==="
ORIGINAL_SETTINGS_JSON=$(wp_source option get wpguard_nc_settings --format=json)
printf '%s' "$ORIGINAL_SETTINGS_JSON" > "$SCRATCH/original-settings.json"

ORIGINAL_WP_RESTORE_PREFIX=$(grep '^WP_RESTORE_TABLE_PREFIX=' .env | cut -d= -f2-)

restore_original_settings() {
  echo "=== Restoring wp-source's original plugin settings ==="
  wp_source_scratch eval '
    $data = json_decode( file_get_contents( "/scratch/original-settings.json" ), true );
    $r = \WPGuard\NCBackup\Settings::save( $data );
    echo $r["ok"] ? "restored ok\n" : "RESTORE FAILED: " . wp_json_encode( $r["errors"] ) . "\n";
  '
  # This script's own restore step changes WP_RESTORE_TABLE_PREFIX in
  # .env and recreates wp-restore with it (same pattern run-e2e.sh uses)
  # -- every other E2E script assumes its own prefix and recreates
  # wp-restore itself if it differs, so leaving this one's prefix behind
  # on disk just means the NEXT script to run pays for an extra
  # recreate, not a real conflict. Restored anyway, to leave the
  # checked-out tree as found rather than relying on every other
  # script's own self-healing.
  CURRENT_PREFIX=$(grep '^WP_RESTORE_TABLE_PREFIX=' .env | cut -d= -f2-)
  if [ -n "$ORIGINAL_WP_RESTORE_PREFIX" ] && [ "$CURRENT_PREFIX" != "$ORIGINAL_WP_RESTORE_PREFIX" ]; then
    echo "=== Restoring WP_RESTORE_TABLE_PREFIX in .env ($CURRENT_PREFIX -> $ORIGINAL_WP_RESTORE_PREFIX) ==="
    sed -i "s/^WP_RESTORE_TABLE_PREFIX=.*/WP_RESTORE_TABLE_PREFIX=${ORIGINAL_WP_RESTORE_PREFIX}/" .env
  fi
}
trap restore_original_settings EXIT

# wp-restore runs at a different URL/port (8082 vs wp-source's 8081), so
# Settings::get_site_id() would normally compute a DIFFERENT id for it --
# every remote path the plugin builds is rooted at its OWN site_id
# (class-job-orchestrator.php / class-restore-orchestrator.php), so a
# plain `restore` pull from wp-restore would 404 looking under the wrong
# folder entirely. The one-click-restore CLI path only ever cares about
# the backup living under this site's own site_id -- the real cross-site
# case (run-e2e-restore.sh) forces SITE_ID_KEY to the source's id before
# restoring, and this test does the same.
SOURCE_SITE_ID=$(wp_source eval 'echo \WPGuard\NCBackup\Settings::get_site_id();')

run_backend() {
  local backend="$1" url="$2" user="$3" cred="$4" folder="wpguard-backups-multi-backend-e2e"

  echo
  echo "############################################################"
  echo "### Backend: $backend  ($url)"
  echo "############################################################"

  echo "--- Configure wp-source to point at $backend ---"
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    -e WPGUARD_TEST_BACKEND="$backend" \
    -e WPGUARD_TEST_NC_URL="$url" \
    -e WPGUARD_TEST_NC_USER="$user" \
    -e WPGUARD_TEST_NC_APP_PASSWORD="$cred" \
    -e WPGUARD_TEST_NC_FOLDER="$folder" \
    wpcli wp --path=/var/www/html-source eval-file /fixtures/configure-plugin.php 2>&1 | grep -v '^ Container'

  echo "--- Connection test ---"
  CONN=$(wp_source eval '
    $logger = new \WPGuard\NCBackup\Logger( array( \WPGuard\NCBackup\Settings::get_app_password() ) );
    $client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( $logger );
    $r = $client->test_connection();
    echo wp_json_encode( $r );
  ')
  echo "$CONN"
  echo "$CONN" | grep -q '"ok":true' || { echo "FAIL ($backend): test_connection() did not report ok:true"; exit 1; }
  echo "OK  ($backend) connection verified"

  echo "--- Backup #1 (full) ---"
  wp_source wpguard-nc-backup run
  BACKUP_1=$(wp_source wpguard-nc-backup status | python3 -c "import sys,json; print(json.load(sys.stdin)['backup_id'])")
  echo "OK  ($backend) backup #1 verified: $BACKUP_1"

  echo "--- Enable incremental, backup #2 (expect mode=incremental, nothing changed since #1) ---"
  wp_source eval '
    $cur = \WPGuard\NCBackup\Settings::get_all();
    $cur["incremental_enabled"] = true;
    $cur["app_password"] = "";
    $r = \WPGuard\NCBackup\Settings::save( $cur );
    echo $r["ok"] ? "incremental enabled\n" : "FAILED: " . wp_json_encode( $r["errors"] ) . "\n";
  '
  wp_source wpguard-nc-backup run
  BACKUP_2=$(wp_source wpguard-nc-backup status | python3 -c "import sys,json; print(json.load(sys.stdin)['backup_id'])")
  MODE_2=$(wp_source eval "
    \$logger = new \WPGuard\NCBackup\Logger( array( \WPGuard\NCBackup\Settings::get_app_password() ) );
    \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( \$logger );
    \$rel = \$client->relative_path( \WPGuard\NCBackup\Settings::get_site_id() . '/backups/$BACKUP_2/manifest.json' );
    \$m = \$client->get_json( \$rel, 50 * 1024 * 1024 );
    echo \$m['backup_mode'];
  ")
  [ "$MODE_2" = "incremental" ] || { echo "FAIL ($backend): backup #2 expected mode=incremental, got '$MODE_2' -- Backup_Chain could not fetch/trust the previous manifest from this backend"; exit 1; }
  echo "OK  ($backend) backup #2 ($BACKUP_2) correctly took the incremental path -- proves the remote manifest GET/parse round-trip against this backend"

  echo "--- Retention: keep last 1, backup #3, confirm older folders pruned on this backend ---"
  wp_source eval '
    $cur = \WPGuard\NCBackup\Settings::get_all();
    $cur["retention_count"] = 1;
    $cur["app_password"] = "";
    $r = \WPGuard\NCBackup\Settings::save( $cur );
    echo $r["ok"] ? "retention_count=1 set\n" : "FAILED: " . wp_json_encode( $r["errors"] ) . "\n";
  '
  wp_source wpguard-nc-backup run
  BACKUP_3=$(wp_source wpguard-nc-backup status | python3 -c "import sys,json; print(json.load(sys.stdin)['backup_id'])")
  REMAINING=$(wp_source eval "
    \$logger = new \WPGuard\NCBackup\Logger( array( \WPGuard\NCBackup\Settings::get_app_password() ) );
    \$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( \$logger );
    \$rel = \$client->relative_path( \WPGuard\NCBackup\Settings::get_site_id() . '/backups' );
    \$children = \$client->list_children( \$rel );
    sort( \$children );
    echo implode( ',', \$children );
  ")
  echo "remaining backup folders on $backend: $REMAINING"
  # Chain-aware retention (class-retention.php): keep=1 keeps BACKUP_3 (the
  # newest) plus whatever its manifest's base_backup_id still points at --
  # here BACKUP_1, the full backup both incrementals were built on, since
  # nothing changed between any of the three runs. BACKUP_2 is fully
  # superseded (nothing newer depends on it) and is the one actually
  # reclaimed. Same scenario already covered backend-specifically by
  # run-e2e-incremental.sh; expecting a bare single survivor here was this
  # script's own bug, not a retention bug.
  EXPECTED=$(printf '%s\n%s' "$BACKUP_1" "$BACKUP_3" | sort | paste -sd,)
  [ "$REMAINING" = "$EXPECTED" ] || { echo "FAIL ($backend): expected retention to keep exactly [$EXPECTED] (chain-aware: newest + its base), got [$REMAINING]"; exit 1; }
  echo "OK  ($backend) retention correctly kept the chain-aware set ($EXPECTED), reclaimed the superseded middle backup"

  echo "--- Restore: fresh WordPress target pulls backup #3 from $backend ---"
  local prefix="wpmb_"
  docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
    -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';" > /dev/null
  if ! grep -q "^WP_RESTORE_TABLE_PREFIX=${prefix}$" .env; then
    sed -i "s/^WP_RESTORE_TABLE_PREFIX=.*/WP_RESTORE_TABLE_PREFIX=${prefix}/" .env
    MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env up -d wp-restore > /dev/null
  fi
  wp_restore "$prefix" core install --url="http://127.0.0.1:8082" --title="Multi-backend Restore Target" \
    --admin_user=wpadmin --admin_password=RestoreE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
  wp_restore "$prefix" plugin activate wpguard-nc-backup > /dev/null

  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$prefix" \
    -e WPGUARD_TEST_BACKEND="$backend" \
    -e WPGUARD_TEST_NC_URL="$url" \
    -e WPGUARD_TEST_NC_USER="$user" \
    -e WPGUARD_TEST_NC_APP_PASSWORD="$cred" \
    -e WPGUARD_TEST_NC_FOLDER="$folder" \
    wpcli wp --path=/var/www/html-restore eval-file /fixtures/configure-plugin.php 2>&1 | grep -v '^ Container'

  wp_restore "$prefix" eval "update_option( \WPGuard\NCBackup\Settings::SITE_ID_KEY, '$SOURCE_SITE_ID', true ); echo 'site_id forced to source: ' . \WPGuard\NCBackup\Settings::get_site_id();"

  wp_restore "$prefix" wpguard-nc-backup restore "$BACKUP_3" --confirm-url-mismatch
  RESTORE_STATUS=$(wp_restore "$prefix" eval 'echo \WPGuard\NCBackup\Job_Store::get_latest()["status"];')
  [ "$RESTORE_STATUS" = "verified" ] || { echo "FAIL ($backend): restore from this backend did not reach verified, got '$RESTORE_STATUS'"; exit 1; }
  POST_COUNT=$(wp_restore "$prefix" post list --post_type=post --format=count)
  [ "$POST_COUNT" -ge 2 ] || { echo "FAIL ($backend): restored post count too low ($POST_COUNT)"; exit 1; }
  echo "OK  ($backend) restore from this backend verified, $POST_COUNT posts present"

  echo "=== $backend: PASSED ==="
}

echo "=== ownCloud: ensure dedicated backup user exists with the known password ==="
docker compose -p wpguard-test exec -T -e OC_PASS="$OC_BACKUP_USER_PASSWORD" owncloud \
  occ user:add --password-from-env --display-name="WPGuard Backup (dedicated, non-admin)" "$OC_BACKUP_USER" 2>&1 | grep -v '^ Container' || true
docker compose -p wpguard-test exec -T -e OC_PASS="$OC_BACKUP_USER_PASSWORD" owncloud \
  occ user:resetpassword --password-from-env "$OC_BACKUP_USER" > /dev/null 2>&1 || true

echo "=== OpenCloud: ensure dedicated backup user exists, mint a fresh admin app token to provision it via Graph ==="
ADMIN_TOKEN=$(docker exec wpguard-test-opencloud-1 opencloud auth-app create --user-name admin --expiration 1h | sed -n 's/^ token: //p')
[ -n "$ADMIN_TOKEN" ] || { echo "FAIL: could not mint an OpenCloud admin app token"; exit 1; }
USER_EXISTS=$(curl -sk --cacert certs/wpguard-test-ca.crt --user "admin:$ADMIN_TOKEN" \
  "https://127.0.0.1:9200/graph/v1.0/users/$OPENCLOUD_BACKUP_USER" -o /dev/null -w "%{http_code}")
if [ "$USER_EXISTS" != "200" ]; then
  curl -sk --cacert certs/wpguard-test-ca.crt --user "admin:$ADMIN_TOKEN" -X POST -H "Content-Type: application/json" \
    --data "{\"displayName\":\"WPGuard Backup\",\"mail\":\"${OPENCLOUD_BACKUP_USER}@example.org\",\"onPremisesSamAccountName\":\"$OPENCLOUD_BACKUP_USER\",\"passwordProfile\":{\"password\":\"Unused-Login-Pw-Not-The-App-Token-1!\"}}" \
    https://127.0.0.1:9200/graph/v1.0/users -o /dev/null -w "created backup user: HTTP %{http_code}\n"
fi
OPENCLOUD_BACKUP_TOKEN=$(docker exec wpguard-test-opencloud-1 opencloud auth-app create --user-name "$OPENCLOUD_BACKUP_USER" --expiration 1h | sed -n 's/^ token: //p')
[ -n "$OPENCLOUD_BACKUP_TOKEN" ] || { echo "FAIL: could not mint an OpenCloud app token for $OPENCLOUD_BACKUP_USER"; exit 1; }

run_backend "owncloud" "https://oc-proxy.wpguard-test.local" "$OC_BACKUP_USER" "$OC_BACKUP_USER_PASSWORD"
run_backend "opencloud" "https://opencloud.wpguard-test.local:9200" "$OPENCLOUD_BACKUP_USER" "$OPENCLOUD_BACKUP_TOKEN"

echo
echo "=== MULTI-BACKEND E2E DRILL PASSED (ownCloud + OpenCloud) ==="
