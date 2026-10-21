#!/usr/bin/env bash
# Phase 2 one-click restore, security audit finding HIGH-AJAX-2: the CLI restore
# command drives the whole restore in ONE continuous PHP process/
# WordPress bootstrap, which is how the main run-e2e-restore.sh test
# necessarily exercises it. The real AJAX (and WP-Cron) path is
# different in a way that matters: every batch is a FRESH bootstrap.
# If an earlier batch has already quarantined or replaced some of a
# still-"active" third-party plugin's files, the VERY NEXT bootstrap
# can fatal loading that now-broken plugin -- stalling the restore
# with no way forward, since even the stale-lock reaper can't resume
# a job stuck on a WordPress bootstrap that never completes.
#
# This constructs that exact scenario on purpose: a plugin whose live
# version requires a file an EARLIER backup doesn't have, then drives
# the restore from that older backup one advance() call at a time,
# each via a genuinely separate `wp eval` process (a real, fresh
# bootstrap every time -- not the CLI command's own internal loop),
# and asserts that NONE of those intermediate bootstraps ever fatal.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_RESTORE_DB_PASSWORD=$(env_val WP_RESTORE_DB_PASSWORD)
WP_RESTORE_DB_ROOT_PASSWORD=$(env_val WP_RESTORE_DB_ROOT_PASSWORD)
NC_BACKUP_USER=$(env_val NC_BACKUP_USER)
PREFIX="wp_"

wpr() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
    wpcli wp --path=/var/www/html-restore "$@" 2>&1 | grep -v '^ Container' || true
}

echo "=== Setup: fresh WordPress core + WPGuard plugin, plus a synthetic third-party plugin ==="
docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
  -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';" > /dev/null
wpr core install --url="http://127.0.0.1:8082" --title="AJAX Safety Test" \
  --admin_user=wpadmin --admin_password=RestoreE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wpr plugin activate wpguard-nc-backup > /dev/null

NC_BACKUP_USER_PASSWORD=$(env_val NC_BACKUP_USER_PASSWORD)
APP_PASSWORD=$(curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$NC_BACKUP_USER_PASSWORD" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://127.0.0.1:8443/ocs/v2.php/core/getapppassword | sed -n 's/.*"apppassword":"\([^"]*\)".*/\1/p')
wpr eval "
  \WPGuard\NCBackup\Settings::save(array(
    'nextcloud_url' => 'https://nc-proxy.wpguard-test.local',
    'nextcloud_user' => '$NC_BACKUP_USER',
    'app_password' => '$APP_PASSWORD',
    'remote_folder' => 'wpguard-backups',
    'ca_bundle_path' => '/usr/local/share/ca-certificates/wpguard-test-ca.crt',
  ));
" > /dev/null
echo "OK  wp-restore's own plugin settings configured against the disposable test Nextcloud"

docker exec wpguard-test-wp-restore-1 sh -c "
  mkdir -p /var/www/html/wp-content/plugins/plugin-a
  cat > /var/www/html/wp-content/plugins/plugin-a/main.php <<'EOF'
<?php
/* Plugin Name: Plugin A */
require_once __DIR__ . '/helper.php';
EOF
  cat > /var/www/html/wp-content/plugins/plugin-a/helper.php <<'EOF'
<?php
// helper, present at backup time
EOF
  chown -R www-data:www-data /var/www/html/wp-content/plugins/plugin-a
"
wpr plugin activate plugin-a > /dev/null
echo "OK  synthetic plugin-a (main.php requiring helper.php) created and active"

echo
echo "=== Backup A: taken while plugin-a still only needs helper.php ==="
wpr wpguard-nc-backup run > /dev/null 2>&1 || true
# Confirmed transient infra flakiness, not a code defect: an immediate
# follow-up `docker compose run --rm` container occasionally doesn't
# see the previous one's just-committed row on its first query, even
# though a direct db query/var_dump moments earlier proves the row is
# genuinely there -- retried rather than chased further.
RESULT_A=""
for attempt in 1 2 3 4 5; do
  RESULT_A=$(wpr eval '$j = \WPGuard\NCBackup\Job_Store::get_latest(); echo ($j["backup_id"] ?? "") . "|" . ($j["status"] ?? "");')
  [ -n "$RESULT_A" ] && [[ "$RESULT_A" == *"|"* ]] && break
  sleep 2
done
BACKUP_A="${RESULT_A%%|*}"
STATUS_A="${RESULT_A##*|}"
[ -n "$BACKUP_A" ] || { echo "FAIL: could not read backup A's job row after retrying"; exit 1; }
[ "$STATUS_A" = "verified" ] || { echo "FAIL: backup A did not verify, got '$STATUS_A'"; exit 1; }
echo "OK  backup A ($BACKUP_A) verified, with plugin-a requiring only helper.php"

echo
echo "=== Simulate plugin-a being updated SINCE backup A: now requires a NEW file backup A does not have ==="
docker exec wpguard-test-wp-restore-1 sh -c "
  cat > /var/www/html/wp-content/plugins/plugin-a/main.php <<'EOF'
<?php
/* Plugin Name: Plugin A */
require_once __DIR__ . '/new-required.php';
EOF
  cat > /var/www/html/wp-content/plugins/plugin-a/new-required.php <<'EOF'
<?php
// added AFTER backup A -- backup A has no idea this file exists
EOF
  chown -R www-data:www-data /var/www/html/wp-content/plugins/plugin-a
"
echo "OK  plugin-a's live version now depends on new-required.php, which backup A predates"

echo
echo "=== Drive the restore from backup A one advance() call at a time, EACH via a genuinely separate process (fresh WordPress bootstrap every time -- matching real AJAX/cron, not the CLI command's own internal loop) ==="
JOB_ID=$(wpr eval "echo \WPGuard\NCBackup\Restore_Orchestrator::create_restore_job(array('type'=>'nextcloud'), '$BACKUP_A', true);")
echo "job=$JOB_ID"
[ -n "$JOB_ID" ] || { echo "FAIL: could not create restore job"; exit 1; }

FATAL_SEEN=0
for i in $(seq 1 400); do
  RESULT=$(wpr eval "\$j = \WPGuard\NCBackup\Restore_Orchestrator::advance('$JOB_ID'); echo \$j['step'] . '|' . \$j['status'];")
  if [[ "$RESULT" == *"Fatal error"* || "$RESULT" == *"critical error"* ]]; then
    echo "FAIL: a fresh bootstrap at iteration $i fataled -- the AJAX/cron path is NOT safe. Output: $RESULT"
    FATAL_SEEN=1
    break
  fi
  if [[ "$RESULT" == *"|verified"* ]]; then
    echo "OK  reached 'verified' at iteration $i with zero intermediate bootstrap fatals"
    break
  fi
  if [[ "$RESULT" == *"|failed"* ]]; then
    echo "FAIL: restore job failed outright: $RESULT"
    exit 1
  fi
done
[ "$FATAL_SEEN" -eq 0 ] || exit 1

FINAL_STATUS=$(wpr eval "echo \WPGuard\NCBackup\Job_Store::get('$JOB_ID')['status'];")
[ "$FINAL_STATUS" = "verified" ] || { echo "FAIL: restore did not reach 'verified' within 400 batches, got '$FINAL_STATUS'"; exit 1; }

echo
echo "=== Post-restore sanity: a fresh bootstrap still works at all (plugin-a's files are consistent again post-swap) ==="
SANITY=$(wpr eval "echo 'alive';")
[[ "$SANITY" == *"alive"* ]] || { echo "FAIL: WordPress cannot even boot after the restore completed: $SANITY"; exit 1; }
echo "OK  WordPress boots cleanly after the restore"

echo
echo "=== RESTORE AJAX-SAFETY (FRESH-BOOTSTRAP-PER-BATCH) TEST PASSED ==="
