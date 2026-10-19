#!/usr/bin/env bash
# Full backup -> verify -> restore -> content-assertion drill, run against
# the disposable stack started by docker/scripts/bootstrap.ps1. This is
# the exact procedure docs/restore-manual.md documents for a human
# operator, automated. See STATUS.md for the gate this satisfies.
set -euo pipefail
# MSYS_NO_PATHCONV is set per-docker-call below, NOT globally: Git Bash's
# path conversion is what lets the Windows-native curl.exe resolve local
# file paths (-o/--cacert), but it mangles container-internal paths like
# /var/www/html-source into a bogus Windows path when passed to docker.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }

WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_RESTORE_DB_PASSWORD=$(env_val WP_RESTORE_DB_PASSWORD)
WP_RESTORE_DB_ROOT_PASSWORD=$(env_val WP_RESTORE_DB_ROOT_PASSWORD)
NC_BACKUP_USER=$(env_val NC_BACKUP_USER)
NC_BACKUP_USER_PASSWORD=$(env_val NC_BACKUP_USER_PASSWORD)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
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

echo "=== Step 1: three successive backups ==="
for i in 1 2 3; do
  echo "--- backup run $i ---"
  wp_source wpguard-nc-backup run
done

echo "=== Step 2: locate latest verified backup, fetch via independent client, verify hashes ==="
BACKUP_ID=$(wp_source wpguard-nc-backup status | python3 -c "import sys,json; print(json.load(sys.stdin)['backup_id'])")
SITE_ID=$(wp_source eval 'echo \WPGuard\NCBackup\Settings::get_site_id();')
echo "backup_id=$BACKUP_ID site_id=$SITE_ID"

APP_PASSWORD=$(curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$NC_BACKUP_USER_PASSWORD" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://127.0.0.1:8443/ocs/v2.php/core/getapppassword | sed -n 's/.*"apppassword":"\([^"]*\)".*/\1/p')

BASE="https://127.0.0.1:8443/remote.php/dav/files/$NC_BACKUP_USER/wpguard-backups/$SITE_ID/backups/$BACKUP_ID"
for f in manifest.json COMPLETE.json database.sql.gz; do
  curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$APP_PASSWORD" "$BASE/$f" -o "$SCRATCH/$f"
done
PART=1
while curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$APP_PASSWORD" -o "$SCRATCH/files.part-$(printf %04d $PART).zip" --fail "$BASE/files.part-$(printf %04d $PART).zip"; do
  PART=$((PART+1))
done
rm -f "$SCRATCH/files.part-$(printf %04d $PART).zip" # last attempt was the 404 that broke the loop

python3 - "$SCRATCH/manifest.json" <<'PYEOF'
import sys, json, hashlib, os
manifest = json.load(open(sys.argv[1]))
scratch = os.path.dirname(sys.argv[1])
for c in manifest["components"]:
    path = os.path.join(scratch, c["path"])
    actual = hashlib.sha256(open(path, "rb").read()).hexdigest()
    assert actual == c["sha256"], f"HASH MISMATCH {c['path']}: expected {c['sha256']} got {actual}"
    print(f"OK  {c['path']}  sha256 matches  ({c['bytes']} bytes)")
print("table_prefix recorded in manifest:", manifest["source"]["table_prefix"])
with open(os.path.join(scratch, "TABLE_PREFIX"), "w") as f:
    f.write(manifest["source"]["table_prefix"])
PYEOF

TABLE_PREFIX=$(cat "$SCRATCH/TABLE_PREFIX")
gunzip -k "$SCRATCH/database.sql.gz"

echo "=== Step 3: restore to a clean disposable WordPress instance ==="
docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
  -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';"

wp_restore "$TABLE_PREFIX" core install \
  --url="http://127.0.0.1:8082" --title="WPGuard Restore Target" \
  --admin_user=wpadmin --admin_password=RestoreE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email

docker compose -p wpguard-test exec -T db-restore mariadb -u wp_restore -p"$WP_RESTORE_DB_PASSWORD" wp_restore < "$SCRATCH/database.sql"

SCRATCH_WIN="$(cygpath -w "$SCRATCH")"
MSYS_NO_PATHCONV=1 docker run --rm --user 0:0 --entrypoint sh \
  -v wpguard-test_wp_restore_html:/target \
  -v "$SCRATCH_WIN:/backup:ro" \
  wordpress:cli -c "rm -rf /target/wp-content && cd /target && for p in /backup/files.part-*.zip; do unzip -o \"\$p\" > /dev/null; done && chown -R 33:33 /target/wp-content"

wp_restore "$TABLE_PREFIX" search-replace 'http://127.0.0.1:8081' 'http://127.0.0.1:8082' --all-tables --precise

echo "=== Step 4: content assertions ==="
POST_COUNT=$(wp_restore "$TABLE_PREFIX" post list --post_type=post --format=count)
[ "$POST_COUNT" -ge 2 ] && echo "OK  post count = $POST_COUNT" || { echo "FAIL post count = $POST_COUNT"; exit 1; }

SENTINEL_OPT=$(wp_restore "$TABLE_PREFIX" option get wpguard_sentinel_option)
[[ "$SENTINEL_OPT" == sentinel-value-* ]] && echo "OK  sentinel option present: $SENTINEL_OPT" || { echo "FAIL sentinel option missing"; exit 1; }

ACTIVE_THEME=$(wp_restore "$TABLE_PREFIX" theme list --status=active --field=name)
echo "OK  active theme restored: $ACTIVE_THEME"

echo "=== Step 5: credential-leak check ==="
STORED_PW=$(wp_source option get wpguard_nc_settings --format=json | python3 -c "import sys,json;print(json.load(sys.stdin)['app_password'])")
if grep -q "$STORED_PW" "$SCRATCH/database.sql"; then
  echo "FAIL: app password found in SQL dump"; exit 1
fi
echo "OK  app password absent from SQL dump"

echo
echo "=== E2E DRILL PASSED ==="
