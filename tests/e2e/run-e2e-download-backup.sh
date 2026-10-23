#!/usr/bin/env bash
# Phase 3 (downloadable backups): proves the whole no-Nextcloud-required
# local backup/download/restore path over REAL authenticated HTTP (not
# just direct wp eval calls) -- the new surface this feature introduces
# is an admin_post_ file-serving endpoint, so the auth gating on it is
# exercised for real, not assumed. Also proves the central design claim
# directly: a downloaded bundle restores through the EXISTING
# upload-restore path with zero new restore code.
#
# Runs against wp-restore (port 8082), never wp-source -- other suites
# depend on wp-source as a stable fixture, and this test's whole premise
# is "Nextcloud never configured," which wp-source's own fixtures aren't.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-download-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_RESTORE_DB_PASSWORD=$(env_val WP_RESTORE_DB_PASSWORD)
WP_RESTORE_DB_ROOT_PASSWORD=$(env_val WP_RESTORE_DB_ROOT_PASSWORD)
PREFIX="wp_"
BASE_URL="http://127.0.0.1:8082"
ADMIN_USER="wpadmin"
ADMIN_PASS="DownloadE2EAdminPW"

wpr() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
    wpcli wp --path=/var/www/html-restore "$@" 2>&1 | grep -v '^ Container' || true
}
wpcli_sh() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm wpcli sh -c "$1" 2>&1 | grep -v '^ Container' | tr -d '\r'
}

echo "=== Setup: fresh WordPress + WPGuard plugin on wp-restore, Nextcloud left UNCONFIGURED on purpose ==="
docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
  -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';" > /dev/null
# wp-restore is a shared, reused target across the E2E suite -- the DB
# above gets reset every run, but the wp_restore_html VOLUME doesn't, so
# whatever an earlier suite last restored onto it (a real production
# backup, in run-e2e-restore.sh/run-e2e-restore-migration.sh's case)
# otherwise still sits in wp-content/ and gets backed up here too. Clear
# plugins (except this one, which is bind-mounted from the host repo --
# never delete that path) and uploads so this test's own backup is just
# its own small seeded fixture, deterministic regardless of run order.
docker exec wpguard-test-wp-restore-1 sh -c "
  find /var/www/html/wp-content/plugins -mindepth 1 -maxdepth 1 ! -name wpthistle-backup-restore -exec rm -rf {} +
  rm -rf /var/www/html/wp-content/uploads/* /var/www/html/wp-content/wpthistle-backup-restore-tmp
" || true
wpr core install --url="$BASE_URL" --title="Download Backup E2E Test" \
  --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wpr plugin activate wpthistle-backup-restore > /dev/null
HAS_CREDS=$(wpr eval "echo \WPThistle\BackupRestore\Settings::has_credentials() ? 'yes' : 'no';")
[ "$HAS_CREDS" = "no" ] || { echo "FAIL: wp-restore unexpectedly has Nextcloud credentials configured"; exit 1; }
echo "OK  no Nextcloud credentials configured"

echo "=== Seed fixture content ==="
wpr eval-file /fixtures/seed-fixtures.php > "$SCRATCH/seed.json"
SENTINEL_OPTION_EXPECTED=$(wpr eval "echo get_option('wpguard_sentinel_option');")
echo "OK  fixtures seeded, sentinel option = $SENTINEL_OPTION_EXPECTED"

echo
echo "=== Real authenticated HTTP login (cookie jar, not wp eval) ==="
COOKIES="$SCRATCH/cookies.txt"
curl -s -c "$COOKIES" "$BASE_URL/wp-login.php" -o /dev/null
curl -s -c "$COOKIES" -b "$COOKIES" \
  --data-urlencode "log=$ADMIN_USER" --data-urlencode "pwd=$ADMIN_PASS" \
  -d "wp-submit=Log+In&redirect_to=$BASE_URL%2Fwp-admin%2F&testcookie=1" \
  "$BASE_URL/wp-login.php" -o /dev/null
PAGE=$(curl -s -c "$COOKIES" -b "$COOKIES" "$BASE_URL/wp-admin/admin.php?page=wpthistle-backup-restore")
echo "$PAGE" > "$SCRATCH/settings-page.html"
[[ "$PAGE" == *"wpthistle_backup_download_now"* ]] || { echo "FAIL: could not load the authenticated settings page (login failed?)"; exit 1; }
NONCE=$(echo "$PAGE" | sed -n 's/.*var nonce = "\([a-f0-9]*\)".*/\1/p')
[ -n "$NONCE" ] || { echo "FAIL: could not extract nonce from settings page"; exit 1; }
# security audit finding LOW-DL-4: the download link now uses its own dedicated
# nonce (NONCE_ACTION_DOWNLOAD), not the shared admin-action one above --
# extracted separately, matching what the real page's own JS does.
DOWNLOAD_NONCE=$(echo "$PAGE" | sed -n 's/.*var downloadNonce = "\([a-f0-9]*\)".*/\1/p')
[ -n "$DOWNLOAD_NONCE" ] || { echo "FAIL: could not extract downloadNonce from settings page"; exit 1; }
echo "OK  authenticated session established, nonce + downloadNonce extracted"

# Every job is driven to completion through the REAL admin-ajax.php
# endpoint, not `wp eval` -- deliberately. wpcli and wp-restore's own
# Apache container mount the SAME volume at DIFFERENT paths
# (/var/www/html-restore vs. /var/www/html), so a step that computes and
# PERSISTS an absolute path (bundle_local's download_bundle_path) would
# get stored relative to whichever container executed it. Driving
# exclusively through the real web server keeps every path the plugin
# ever writes consistent with the one process that will later serve it
# -- exactly how a real browser/WP-Cron-driven backup behaves.
advance_via_http() {
  curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_advance_job&nonce=$NONCE&job_id=$1" "$BASE_URL/wp-admin/admin-ajax.php"
}
drive_to_verified() {
  local jid="$1" label="$2" res i
  for i in $(seq 1 400); do
    res=$(advance_via_http "$jid")
    [[ "$res" == *'"status":"verified"'* ]] && return 0
    if [[ "$res" == *'"status":"failed"'* ]]; then
      echo "FAIL: $label failed: $res"
      return 1
    fi
  done
  echo "FAIL: $label did not reach verified within 400 HTTP advance() calls. Last response: $res"
  return 1
}

echo
echo "=== Start a Download Now backup via the REAL authenticated AJAX endpoint (destination=download, no creds configured) ==="
START=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_start_backup&nonce=$NONCE&destination=download" "$BASE_URL/wp-admin/admin-ajax.php")
echo "$START" > "$SCRATCH/start.json"
JOB_ID=$(echo "$START" | sed -n 's/.*"job_id":"\([^"]*\)".*/\1/p')
[ -n "$JOB_ID" ] || { echo "FAIL: did not get a job_id back. Response: $START"; exit 1; }
echo "OK  job started via real HTTP: $JOB_ID"

echo
echo "=== Drive the job to completion via the real HTTP AJAX endpoint ==="
drive_to_verified "$JOB_ID" "download-only backup" || exit 1
echo "OK  download-only backup verified"

DOWNLOAD_TOKEN=$(wpr eval "\$cp = \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['checkpoint']; echo \$cp['download_token'] ?? '';")
[ -n "$DOWNLOAD_TOKEN" ] || { echo "FAIL: no download_token on the verified job's checkpoint"; exit 1; }

echo
echo "=== Negative auth checks on the real download endpoint ==="
DOWNLOAD_URL="$BASE_URL/wp-admin/admin-post.php?action=wpthistle_backup_download_bundle&job_id=$JOB_ID&nonce=$DOWNLOAD_NONCE"

# WordPress core itself blocks this before our handler ever runs: an
# admin_post_{action} hook with no matching admin_post_nopriv_{action}
# registered gets wp_die('', 400) straight from wp-admin/admin-post.php
# for any non-logged-in request -- our own current_user_can() check
# inside handle_download_bundle() is real defense in depth, but is
# provably unreachable for an anonymous request given this hook choice.
CODE_NOAUTH=$(curl -s -o "$SCRATCH/noauth.bin" -w "%{http_code}" "$DOWNLOAD_URL")
case "$CODE_NOAUTH" in
  400|403) : ;;
  *) echo "FAIL: unauthenticated download request returned $CODE_NOAUTH, expected 400 or 403"; exit 1 ;;
esac
# The denial response is WordPress's own HTML error page, which is not
# itself tiny -- the real check is that it is not a zip (magic bytes
# "PK"), not that it is short.
NOAUTH_MAGIC=$(head -c 2 "$SCRATCH/noauth.bin")
[ "$NOAUTH_MAGIC" != "PK" ] || { echo "FAIL: unauthenticated request received real zip bytes (PK magic)"; exit 1; }
echo "OK  unauthenticated request denied ($CODE_NOAUTH), response is not a zip"

CODE_BADNONCE=$(curl -s -c "$COOKIES" -b "$COOKIES" -o "$SCRATCH/badnonce.bin" -w "%{http_code}" "$BASE_URL/wp-admin/admin-post.php?action=wpthistle_backup_download_bundle&job_id=$JOB_ID&nonce=deadbeef")
[ "$CODE_BADNONCE" = "403" ] || { echo "FAIL: bad-nonce download request returned $CODE_BADNONCE, expected 403"; exit 1; }
echo "OK  authenticated-but-bad-nonce request denied (403)"

DIRECT_URL="$BASE_URL/wp-content/wpthistle-backup-restore-tmp/downloads/$DOWNLOAD_TOKEN/"
CODE_DIRECT=$(curl -s -o /dev/null -w "%{http_code}" "$DIRECT_URL")
[ "$CODE_DIRECT" != "200" ] || { echo "FAIL: the guarded downloads directory is directly web-listable/servable ($CODE_DIRECT)"; exit 1; }
echo "OK  guarded downloads directory not directly web-accessible (got $CODE_DIRECT)"

echo
echo "=== Real authenticated download fetch ==="
CODE_OK=$(curl -s -c "$COOKIES" -b "$COOKIES" -o "$SCRATCH/bundle.zip" -D "$SCRATCH/headers.txt" -w "%{http_code}" "$DOWNLOAD_URL")
[ "$CODE_OK" = "200" ] || { echo "FAIL: authenticated download returned $CODE_OK"; exit 1; }
grep -qi '^Content-Disposition: attachment' "$SCRATCH/headers.txt" || { echo "FAIL: missing Content-Disposition: attachment header"; exit 1; }
grep -qi '^Content-Type: application/zip' "$SCRATCH/headers.txt" || { echo "FAIL: missing Content-Type: application/zip header"; exit 1; }
BYTES=$(wc -c < "$SCRATCH/bundle.zip" | tr -d ' ')
[ "$BYTES" -gt 1000 ] || { echo "FAIL: downloaded bundle is suspiciously small ($BYTES bytes)"; exit 1; }
echo "OK  downloaded real bundle via authenticated HTTP ($BYTES bytes)"

echo
echo "=== Bundle sanity: expected entries present, manifest references no backup-tmp paths ==="
ziptool() { MSYS_NO_PATHCONV=1 docker run --rm -v "$SCRATCH":/d alpine sh -c "apk add --no-cache unzip >/dev/null 2>&1 && $1"; }
ziptool "unzip -l /d/bundle.zip" > "$SCRATCH/listing.txt"
for name in manifest.json COMPLETE.json database.sql.gz files.part-0001.zip; do
  grep -q "$name" "$SCRATCH/listing.txt" || { echo "FAIL: bundle missing $name"; exit 1; }
done
echo "OK  bundle contains manifest.json, COMPLETE.json, database.sql.gz, files.part-0001.zip"

ziptool "unzip -p /d/bundle.zip manifest.json" > "$SCRATCH/manifest.json"
# Checking files[].path specifically, not grepping the raw JSON text --
# the manifest legitimately documents Inventory::EXCLUDED_PATTERNS
# (which itself contains the literal string "wpthistle-backup-restore-tmp") in
# its own scope.files.excluded_patterns field, so a raw text grep always
# false-positives regardless of whether any FILE ENTRY actually
# references it.
BAD_COUNT=$(MSYS_NO_PATHCONV=1 docker run --rm -v "$SCRATCH":/d php:8.3-cli php -r '
$d = json_decode(file_get_contents("/d/manifest.json"), true);
$bad = 0;
foreach (($d["files"] ?? []) as $f) {
  if (strpos($f["path"] ?? "", "wpthistle-backup-restore-tmp") === 0) { $bad++; }
}
echo $bad;
')
[ "$BAD_COUNT" = "0" ] || { echo "FAIL: manifest.json's files[] references $BAD_COUNT entries under its own tmp staging directory -- Inventory exclusion regressed"; exit 1; }
echo "OK  manifest.json's files[] contains no wpthistle-backup-restore-tmp self-references"

echo
echo "=== Cap-at-one: a second Download Now backup removes the first bundle ==="
TOKEN_DIR_1=$(wpr eval "echo \WPThistle\BackupRestore\Job_Orchestrator::download_dir_for_token('$DOWNLOAD_TOKEN');")
EXISTS_BEFORE=$(wpcli_sh "[ -d '$TOKEN_DIR_1' ] && echo yes || echo no")
[ "$EXISTS_BEFORE" = "yes" ] || { echo "FAIL: expected first bundle dir to still exist before starting a second backup"; exit 1; }

START2=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_start_backup&nonce=$NONCE&destination=download" "$BASE_URL/wp-admin/admin-ajax.php")
JOB_ID_2=$(echo "$START2" | sed -n 's/.*"job_id":"\([^"]*\)".*/\1/p')
[ -n "$JOB_ID_2" ] || { echo "FAIL: second download backup did not start: $START2"; exit 1; }
EXISTS_AFTER=$(wpcli_sh "[ -d '$TOKEN_DIR_1' ] && echo yes || echo no")
[ "$EXISTS_AFTER" = "no" ] || { echo "FAIL: first bundle's directory still exists after a second Download Now backup started -- cap-at-one not enforced"; exit 1; }
echo "OK  starting a second local backup removed the prior pending bundle"

drive_to_verified "$JOB_ID_2" "second download backup" || exit 1
DOWNLOAD_TOKEN_2=$(wpr eval "\$cp = \WPThistle\BackupRestore\Job_Store::get('$JOB_ID_2')['checkpoint']; echo \$cp['download_token'] ?? '';")
[ -n "$DOWNLOAD_TOKEN_2" ] || { echo "FAIL: second job has no download_token"; exit 1; }
echo "OK  second local backup verified ($DOWNLOAD_TOKEN_2)"

echo
echo "=== Explicit delete via the real authenticated AJAX endpoint ==="
TOKEN_DIR_2=$(wpr eval "echo \WPThistle\BackupRestore\Job_Orchestrator::download_dir_for_token('$DOWNLOAD_TOKEN_2');")
curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_delete_download_bundle&nonce=$NONCE&job_id=$JOB_ID_2" "$BASE_URL/wp-admin/admin-ajax.php" > /dev/null
EXISTS_DELETED=$(wpcli_sh "[ -d '$TOKEN_DIR_2' ] && echo yes || echo no")
[ "$EXISTS_DELETED" = "no" ] || { echo "FAIL: explicit delete did not remove the bundle directory"; exit 1; }
CODE_AFTER_DELETE=$(curl -s -c "$COOKIES" -b "$COOKIES" -o /dev/null -w "%{http_code}" "$BASE_URL/wp-admin/admin-post.php?action=wpthistle_backup_download_bundle&job_id=$JOB_ID_2&nonce=$DOWNLOAD_NONCE")
[ "$CODE_AFTER_DELETE" = "404" ] || { echo "FAIL: download endpoint did not 404 after explicit delete (got $CODE_AFTER_DELETE)"; exit 1; }
echo "OK  explicit delete removes the bundle; endpoint 404s afterward"

echo
echo "=== TTL sweep: an artificially-aged bundle is removed by the TTL-only sweep ==="
START_TTL=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_start_backup&nonce=$NONCE&destination=download" "$BASE_URL/wp-admin/admin-ajax.php")
JOB_ID_TTL=$(echo "$START_TTL" | sed -n 's/.*"job_id":"\([^"]*\)".*/\1/p')
[ -n "$JOB_ID_TTL" ] || { echo "FAIL: TTL-test backup did not start"; exit 1; }
drive_to_verified "$JOB_ID_TTL" "TTL-test backup" || exit 1
TOKEN_DIR_TTL=$(wpr eval "\$cp = \WPThistle\BackupRestore\Job_Store::get('$JOB_ID_TTL')['checkpoint']; echo \WPThistle\BackupRestore\Job_Orchestrator::download_dir_for_token(\$cp['download_token'] ?? '');")
[ -n "$TOKEN_DIR_TTL" ] || { echo "FAIL: could not resolve TTL-test bundle directory"; exit 1; }
wpr eval "touch(rtrim('$TOKEN_DIR_TTL','/'), time() - 2 * DAY_IN_SECONDS);" > /dev/null
wpr eval "\WPThistle\BackupRestore\Job_Orchestrator::sweep_download_bundles(false);" > /dev/null
EXISTS_AFTER_TTL=$(wpcli_sh "[ -d '$TOKEN_DIR_TTL' ] && echo yes || echo no")
[ "$EXISTS_AFTER_TTL" = "no" ] || { echo "FAIL: TTL-only sweep did not remove a bundle aged past the TTL"; exit 1; }
echo "OK  TTL-only sweep removes an aged pending bundle"

echo
echo "=== Round trip: feed the FIRST downloaded bundle back through the EXISTING upload-restore path (no new restore code) ==="
# No ";type=application/zip" suffix on the @filename -- Git Bash's MSYS
# runtime auto-converts anything resembling a POSIX path in a raw (non-
# Docker) curl argument, including "application/zip" itself, corrupting
# the upload (curl: (26) Failed to open/read local data). The handler
# never inspects $_FILES['bundle']['type'] anyway, so it's not needed.
UPLOAD_RESP=$(curl -s -c "$COOKIES" -b "$COOKIES" -F "action=wpthistle_backup_upload_restore_bundle" -F "nonce=$NONCE" -F "bundle=@$SCRATCH/bundle.zip" "$BASE_URL/wp-admin/admin-ajax.php")
echo "$UPLOAD_RESP" > "$SCRATCH/upload-resp.json"
UPLOAD_TOKEN=$(echo "$UPLOAD_RESP" | sed -n 's/.*"upload_token":"\([^"]*\)".*/\1/p')
[ -n "$UPLOAD_TOKEN" ] || { echo "FAIL: upload-restore-bundle rejected the downloaded bundle: $UPLOAD_RESP"; exit 1; }
echo "OK  downloaded bundle accepted by the existing upload-restore endpoint"

RESTORE_START=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_start_restore&nonce=$NONCE&source=upload&upload_token=$UPLOAD_TOKEN&confirm_url_mismatch=1" "$BASE_URL/wp-admin/admin-ajax.php")
RESTORE_JOB_ID=$(echo "$RESTORE_START" | sed -n 's/.*"job_id":"\([^"]*\)".*/\1/p')
[ -n "$RESTORE_JOB_ID" ] || { echo "FAIL: restore did not start: $RESTORE_START"; exit 1; }
echo "OK  restore job started from the downloaded bundle: $RESTORE_JOB_ID"

# Same admin-ajax action dispatches to Restore_Orchestrator::advance()
# internally (by checkpoint kind) -- drive_to_verified works unchanged.
drive_to_verified "$RESTORE_JOB_ID" "restore from downloaded bundle" || exit 1
echo "OK  restore from the downloaded bundle verified"

echo
echo "=== Content assertions after restoring from the downloaded bundle ==="
GOT_OPTION=$(wpr eval "echo get_option('wpguard_sentinel_option');")
[ "$GOT_OPTION" = "$SENTINEL_OPTION_EXPECTED" ] || { echo "FAIL: sentinel option mismatch after restore ('$GOT_OPTION' != '$SENTINEL_OPTION_EXPECTED')"; exit 1; }
echo "OK  sentinel option present and correct after round-trip restore"

echo
echo "=== Deactivate-purge: a pending bundle is removed when the plugin deactivates ==="
START3=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_start_backup&nonce=$NONCE&destination=download" "$BASE_URL/wp-admin/admin-ajax.php")
JOB_ID_3=$(echo "$START3" | sed -n 's/.*"job_id":"\([^"]*\)".*/\1/p')
[ -n "$JOB_ID_3" ] || { echo "FAIL: third download backup did not start"; exit 1; }
drive_to_verified "$JOB_ID_3" "third download backup" || exit 1
TOKEN_DIR_3=$(wpr eval "\$cp = \WPThistle\BackupRestore\Job_Store::get('$JOB_ID_3')['checkpoint']; echo \WPThistle\BackupRestore\Job_Orchestrator::download_dir_for_token(\$cp['download_token'] ?? '');")
[ -n "$TOKEN_DIR_3" ] || { echo "FAIL: could not resolve third bundle's directory"; exit 1; }

wpr plugin deactivate wpthistle-backup-restore > /dev/null
EXISTS_AFTER_DEACTIVATE=$(wpcli_sh "[ -d '$TOKEN_DIR_3' ] && echo yes || echo no")
wpr plugin activate wpthistle-backup-restore > /dev/null
[ "$EXISTS_AFTER_DEACTIVATE" = "no" ] || { echo "FAIL: pending bundle directory survived plugin deactivation"; exit 1; }
echo "OK  deactivating the plugin purges the pending bundle (plugin reactivated afterward)"

echo
echo "=== DOWNLOADABLE BACKUPS E2E TEST PASSED ==="
