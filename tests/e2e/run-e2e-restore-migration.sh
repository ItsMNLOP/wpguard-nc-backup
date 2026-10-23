#!/usr/bin/env bash
# Phase 2.5 (migration on restore): checking "this backup is from a
# different site" means migration, not merely "proceed anyway" --
# every URL in the backup gets rewritten to the new site's own
# address, serialization-safe, before anything is promoted live.
#
# Seeds content on a synthetic "old site" specifically engineered to
# exercise every real risk this feature exists to handle: a plain
# https:// link in post content, a scheme-mismatched (http://) legacy
# link to the same old domain, a URL nested inside PHP-serialized
# widget data (where a naive replace would corrupt the byte-length
# prefix), and a bare-hostname plain-text mention with NO scheme
# prefix that must survive completely untouched (proving the feature
# doesn't over-match). Backs that site up, then restores it onto a
# fresh "new site" with box checked, and verifies all of it landed
# correctly -- including that wp-admin actually works on the new site
# without bouncing back toward the old domain.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_RESTORE_DB_PASSWORD=$(env_val WP_RESTORE_DB_PASSWORD)
WP_RESTORE_DB_ROOT_PASSWORD=$(env_val WP_RESTORE_DB_ROOT_PASSWORD)
NC_BACKUP_USER=$(env_val NC_BACKUP_USER)
NC_BACKUP_USER_PASSWORD=$(env_val NC_BACKUP_USER_PASSWORD)
PREFIX="wp_"

# This script's own wp-restore install/HTTP checks assume the DEFAULT
# table prefix -- but another E2E script run earlier in the same
# session can leave .env's WP_RESTORE_TABLE_PREFIX set to something
# else entirely (run-e2e.sh deliberately matches whatever prefix the
# backup's own manifest recorded, and leaves it there afterward -- not
# a bug in that script, just a different resting value than this one
# needs). Found live: wp-login.php's real HTTP check returned a
# WordPress "not installed" redirect because the apache container's
# own WORDPRESS_TABLE_PREFIX env (read from .env at container-create
# time, not live) didn't match the wp_-prefixed tables this script's
# own wp-cli calls had just created. Same fix pattern as
# run-e2e-restore.sh.
if ! grep -q "^WP_RESTORE_TABLE_PREFIX=${PREFIX}$" .env; then
  sed -i "s/^WP_RESTORE_TABLE_PREFIX=.*/WP_RESTORE_TABLE_PREFIX=${PREFIX}/" .env
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env up -d wp-restore > /dev/null
fi

wpr() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
    wpcli wp --path=/var/www/html-restore "$@" 2>&1 | grep -v '^ Container' || true
}
fresh_db() {
  docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
    -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';" > /dev/null
}
configure_nextcloud() {
  APP_PASSWORD=$(curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$NC_BACKUP_USER_PASSWORD" \
    -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
    https://127.0.0.1:8443/ocs/v2.php/core/getapppassword | sed -n 's/.*"apppassword":"\([^"]*\)".*/\1/p')
  wpr eval "
    \WPThistle\BackupRestore\Settings::save(array(
      'nextcloud_url' => 'https://nc-proxy.wpguard-test.local',
      'nextcloud_user' => '$NC_BACKUP_USER',
      'app_password' => '$APP_PASSWORD',
      'remote_folder' => 'wpguard-backups',
      'ca_bundle_path' => '/usr/local/share/ca-certificates/wpguard-test-ca.crt',
    ));
  " > /dev/null
}

echo "=== Old site: fresh WordPress at https://old-site.example, seeded with content engineered to exercise every migration edge case ==="
fresh_db
wpr core install --url="https://old-site.example" --title="Old Site" \
  --admin_user=wpadmin --admin_password=MigrationE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wpr plugin activate wpthistle-backup-restore > /dev/null
configure_nextcloud
echo "OK  old site installed at https://old-site.example, plugin configured"

POST_ID=$(wpr post create --post_title="Welcome" --post_status=publish \
  --post_content='<p>Visit our <a href="https://old-site.example/legacy-page">main page</a>, or the <a href="http://old-site.example/even-older-page">archived version</a>.</p><p>Email us at admin@old-site.example for help.</p>' \
  --porcelain)
echo "OK  seeded post $POST_ID with an https link, an http (scheme-mismatched) link, and a bare-hostname email mention"

wpr eval "
  // Passed as a plain array, NOT pre-serialized -- update_option()
  // calls maybe_serialize() itself, and that deliberately
  // double-serializes any string that already looks serialized (to
  // disambiguate a literal string from real array data on a later
  // read), so pre-serializing here would seed an unrealistic
  // double-serialized value no real widget/option ever actually has.
  update_option( 'widget_text-2', array(
    'title' => 'Links',
    'text'  => 'See https://old-site.example/widget-target for details.',
  ) );
" > /dev/null
echo "OK  seeded a PHP-serialized widget option containing a nested URL to the old domain"

echo
echo "=== Backup A: taken from the old site ==="
wpr wpthistle-backup-restore run > /dev/null 2>&1 || true
RESULT_A=""
for attempt in 1 2 3 4 5; do
  RESULT_A=$(wpr eval '$j = \WPThistle\BackupRestore\Job_Store::get_latest(); echo ($j["backup_id"] ?? "") . "|" . ($j["status"] ?? "");')
  [ -n "$RESULT_A" ] && [[ "$RESULT_A" == *"|"* ]] && break
  sleep 2
done
BACKUP_A="${RESULT_A%%|*}"
STATUS_A="${RESULT_A##*|}"
[ -n "$BACKUP_A" ] || { echo "FAIL: could not read backup A's job row after retrying"; exit 1; }
[ "$STATUS_A" = "verified" ] || { echo "FAIL: backup A did not verify, got '$STATUS_A'"; exit 1; }
echo "OK  backup A ($BACKUP_A) verified"

OLD_SITE_ID=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_site_id();")
[ -n "$OLD_SITE_ID" ] || { echo "FAIL: could not read old site's site_id"; exit 1; }
echo "OK  old site's site_id: $OLD_SITE_ID"

echo
echo "=== New site: fresh WordPress at http://127.0.0.1:8082 (a genuinely different domain AND scheme) ==="
fresh_db
wpr core install --url="http://127.0.0.1:8082" --title="New Site" \
  --admin_user=wpadmin --admin_password=MigrationE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wpr plugin activate wpthistle-backup-restore > /dev/null
configure_nextcloud
echo "OK  new site installed at http://127.0.0.1:8082"

echo
echo "=== Downloading backup A's files into a local upload-style holding dir (same mechanism a real admin's manual download+upload uses -- the Nextcloud picker is site_id-scoped and can never reach another site's backup, confirmed earlier in this project) ==="
wpr eval "
  \$client = \WPThistle\BackupRestore\Nextcloud_Client::from_settings( new \WPThistle\BackupRestore\Logger( array( \WPThistle\BackupRestore\Settings::get_app_password() ) ) );
  \$parent_rel = \$client->relative_path( '$OLD_SITE_ID/backups/$BACKUP_A' );
  \$holding = '/var/www/html-restore/wp-content/migration-test-upload/';
  wp_mkdir_p( \$holding );
  foreach ( array( 'manifest.json', 'COMPLETE.json' ) as \$name ) {
    \$client->download_to_file( \$parent_rel . '/' . \$name, \$holding . \$name, 8 * 1024 * 1024 );
  }
  \$manifest = json_decode( file_get_contents( \$holding . 'manifest.json' ), true );
  foreach ( (array) ( \$manifest['components'] ?? array() ) as \$component ) {
    \$client->download_to_file( \$parent_rel . '/' . \$component['path'], \$holding . \$component['path'], (int) \$component['bytes'] + 1024 );
  }
  echo 'done';
" > /dev/null
echo "OK  backup A's files staged into /var/www/html-restore/wp-content/migration-test-upload/ on the new site's own filesystem"

echo
echo "=== Restoring backup A onto the new site from that upload dir, box checked (confirm_url_mismatch) ==="
JOB_ID=$(wpr eval "echo \WPThistle\BackupRestore\Restore_Orchestrator::create_restore_job(array('type'=>'upload','upload_dir'=>'/var/www/html-restore/wp-content/migration-test-upload/'), '$BACKUP_A', true);")
echo "job=$JOB_ID"
[ -n "$JOB_ID" ] || { echo "FAIL: could not create restore job"; exit 1; }

for i in $(seq 1 200); do
  RESULT=$(wpr eval "\$j = \WPThistle\BackupRestore\Restore_Orchestrator::advance('$JOB_ID'); echo \$j['step'] . '|' . \$j['status'];")
  [[ "$RESULT" == *"|verified"* ]] && break
  if [[ "$RESULT" == *"|failed"* ]]; then
    echo "FAIL: restore job failed: $RESULT"
    exit 1
  fi
done
FINAL_STATUS=$(wpr eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['status'];")
[ "$FINAL_STATUS" = "verified" ] || { echo "FAIL: restore did not reach 'verified', got '$FINAL_STATUS'"; exit 1; }
echo "OK  restore reached 'verified'"

echo
echo "=== Assertions ==="
NEW_SITEURL=$(wpr option get siteurl)
[ "$NEW_SITEURL" = "http://127.0.0.1:8082" ] || { echo "FAIL: siteurl is '$NEW_SITEURL', expected the new site's own address"; exit 1; }
echo "OK  siteurl correctly points at the new site (http://127.0.0.1:8082), not the backup's old-site.example"

POST_CONTENT=$(wpr post get "$POST_ID" --field=content)
if [[ "$POST_CONTENT" == *"https://old-site.example"* || "$POST_CONTENT" == *"http://old-site.example"* ]]; then
  echo "FAIL: post content still has a scheme-qualified link to old-site.example after migration: $POST_CONTENT"
  exit 1
fi
[[ "$POST_CONTENT" == *"http://127.0.0.1:8082/legacy-page"* ]] || { echo "FAIL: the https link was not correctly migrated: $POST_CONTENT"; exit 1; }
[[ "$POST_CONTENT" == *"http://127.0.0.1:8082/even-older-page"* ]] || { echo "FAIL: the scheme-mismatched http link was not correctly migrated: $POST_CONTENT"; exit 1; }
echo "OK  both the https link and the scheme-mismatched http link were migrated to the new site's address"

[[ "$POST_CONTENT" == *"admin@old-site.example"* ]] || { echo "FAIL: the bare-hostname email mention (no scheme) was altered or lost -- migration must only ever touch scheme-qualified URL occurrences"; exit 1; }
echo "OK  the bare-hostname email mention survived completely untouched -- confirms no over-matching"

WIDGET_RAW=$(wpr option get widget_text-2)
WIDGET_CHECK=$(wpr eval "
  // get_option() already unserializes once internally -- a correctly
  // migrated value comes back as a real PHP array directly here, not
  // a string needing another manual unserialize() call.
  \$u = get_option( 'widget_text-2' );
  if ( ! is_array( \$u ) ) { echo 'CORRUPT'; exit; }
  echo ( false !== strpos( \$u['text'], 'http://127.0.0.1:8082/widget-target' ) ) ? 'OK' : 'WRONG:' . \$u['text'];
")
[ "$WIDGET_CHECK" = "OK" ] || { echo "FAIL: serialized widget option did not survive migration intact: $WIDGET_CHECK (raw: $WIDGET_RAW)"; exit 1; }
echo "OK  the PHP-serialized widget option survived migration as valid, unserializable data, with its nested URL correctly rewritten"

LOGIN_REDIRECT=$(curl -sI http://127.0.0.1:8082/wp-admin/ 2>&1 | grep -i '^location:' || true)
if [[ "$LOGIN_REDIRECT" == *"old-site.example"* ]]; then
  echo "FAIL: wp-admin's own not-logged-in redirect still points at the old domain: $LOGIN_REDIRECT"
  exit 1
fi
[[ "$LOGIN_REDIRECT" == *"127.0.0.1:8082"* ]] || { echo "FAIL: wp-admin's redirect doesn't point at the new site at all: $LOGIN_REDIRECT"; exit 1; }
echo "OK  wp-admin's not-logged-in redirect correctly stays on the new site, never bounces toward the old domain"

LOGIN_PAGE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8082/wp-login.php 2>&1)
[ "$LOGIN_PAGE" = "200" ] || { echo "FAIL: wp-login.php returned $LOGIN_PAGE, expected 200"; exit 1; }
echo "OK  wp-login.php loads cleanly on the new site"

echo
echo "=== RESTORE MIGRATION (PHASE 2.5) TEST PASSED ==="
