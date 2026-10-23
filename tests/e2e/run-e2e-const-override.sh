#!/usr/bin/env bash
# security audit finding H-02 regression test: the constant-override credential path
# (ADR 0002's "more secure" escape hatch — define('WPTHISTLE_BACKUP_APP_PASSWORD',
# ...) in wp-config.php instead of storing it in wp_options) is the one
# the main E2E drill (run-e2e.sh) never exercises, because it always
# configures the plugin through Settings::save() (wp_options storage).
#
# That gap is exactly why H-02 shipped undetected: the credential ended
# up archived in plain text inside wp-config.php, in the very backup
# meant to protect it (fixed in Job_Orchestrator::redacted_wp_config_copy()
# / Settings::redact_wp_config_contents()).
#
# Docker-image note: this stack's WORDPRESS_CONFIG_EXTRA env var is
# `eval()`-ed at runtime (see wp-config.php's `eval($configExtra)`), never
# written to the physical file on disk — so it cannot reproduce the real
# vulnerability, which requires a literal `define(...)` line sitting in
# wp-config.php (the realistic case for a conventional, non-Docker WP
# host). This script appends that line to the real on-disk wp-config.php
# inside the disposable wp-source container for the duration of the
# test, then reverts it — never run this against a real site.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-const-override-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)
NC_BACKUP_USER=$(env_val NC_BACKUP_USER)
NC_BACKUP_USER_PASSWORD=$(env_val NC_BACKUP_USER_PASSWORD)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container'
}

START_MARKER='/* WPGUARD_H02_TEST_CONST_OVERRIDE_START */'
END_MARKER='/* WPGUARD_H02_TEST_CONST_OVERRIDE_END */'

cleanup() {
  echo "=== Cleanup: reverting wp-config.php test injection ==="
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env exec -T wp-source \
    sh -c "sed -i '/WPGUARD_H02_TEST_CONST_OVERRIDE_START/,/WPGUARD_H02_TEST_CONST_OVERRIDE_END/d' /var/www/html/wp-config.php" \
    2>&1 | grep -v '^ Container' || true
}
trap cleanup EXIT

echo "=== Step 1: capture the already-configured app password (wp_options-stored, not printed) ==="
REAL_APP_PASSWORD=$(wp_source option get wpthistle_backup_settings --format=json | python3 -c "import sys,json; print(json.load(sys.stdin)['app_password'])")
[ -n "$REAL_APP_PASSWORD" ] || { echo "FAIL: could not read the real app password from wp_options"; exit 1; }

run_round() {
  local const_name="$1" round_label="$2"
  local extracted_dir="$SCRATCH/extracted-$round_label"
  rm -rf "$extracted_dir" && mkdir -p "$extracted_dir"

  echo "=== [$round_label] Step 2: inject a literal define('$const_name', ...) into the real on-disk wp-config.php ==="
  PHP_SNIPPET="
${START_MARKER}
define('${const_name}', '${REAL_APP_PASSWORD}');
${END_MARKER}
"
  printf '%s\n' "$PHP_SNIPPET" > "$SCRATCH/inject.php"
  SCRATCH_WIN="$(cygpath -w "$SCRATCH")"
  MSYS_NO_PATHCONV=1 docker run --rm --user 0:0 \
    -v wpguard-test_wp_source_html:/target \
    -v "$SCRATCH_WIN:/inject:ro" \
    wordpress:cli sh -c "cat /inject/inject.php >> /target/wp-config.php"

  echo "=== [$round_label] Step 3: sanity check — plugin must now read the credential from the constant, not wp_options ==="
  LOCKED=$(wp_source eval 'echo \WPThistle\BackupRestore\Settings::is_app_password_locked() ? "yes" : "no";')
  [ "$LOCKED" = "yes" ] || { echo "FAIL [$round_label]: constant override not recognized as locked"; exit 1; }
  echo "OK  [$round_label] app password is constant-locked for this run"

  echo "=== [$round_label] Step 4: run a real backup through the constant-override path ==="
  wp_source wpthistle-backup-restore run

  STATUS=$(wp_source wpthistle-backup-restore status | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])")
  [ "$STATUS" = "verified" ] || { echo "FAIL [$round_label]: backup did not reach verified status (status=$STATUS)"; exit 1; }
  echo "OK  [$round_label] backup verified while running under the constant-override path"

  echo "=== [$round_label] Step 5: fetch and extract the backed-up files archive, check for the leaked credential ==="
  BACKUP_ID=$(wp_source wpthistle-backup-restore status | python3 -c "import sys,json; print(json.load(sys.stdin)['backup_id'])")
  SITE_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Settings::get_site_id();')
  APP_PASSWORD=$(curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$NC_BACKUP_USER_PASSWORD" \
    -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
    https://127.0.0.1:8443/ocs/v2.php/core/getapppassword | sed -n 's/.*"apppassword":"\([^"]*\)".*/\1/p')
  BASE="https://127.0.0.1:8443/remote.php/dav/files/$NC_BACKUP_USER/wpguard-backups/$SITE_ID/backups/$BACKUP_ID"

  PART=1
  while curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$APP_PASSWORD" \
    -o "$SCRATCH/files.part-$(printf %04d $PART).zip" --fail "$BASE/files.part-$(printf %04d $PART).zip"; do
    PART=$((PART+1))
  done
  rm -f "$SCRATCH/files.part-$(printf %04d $PART).zip"

  for p in "$SCRATCH"/files.part-*.zip; do
    [ -f "$p" ] && unzip -o -q "$p" -d "$extracted_dir"
  done
  rm -f "$SCRATCH"/files.part-*.zip

  [ -f "$extracted_dir/wp-config.php" ] || { echo "FAIL [$round_label]: wp-config.php not found in the archived files (expected it to be present, just redacted)"; exit 1; }

  if grep -q "$REAL_APP_PASSWORD" "$extracted_dir/wp-config.php"; then
    echo "FAIL [$round_label]: app password literal found in archived wp-config.php — H-02 regression!"
    exit 1
  fi
  echo "OK  [$round_label] app password absent from archived wp-config.php"

  if ! grep -q 'line redacted from backup archive' "$extracted_dir/wp-config.php"; then
    echo "FAIL [$round_label]: redaction marker comment missing — redaction path may not have run at all"
    exit 1
  fi
  echo "OK  [$round_label] redaction marker present — confirms the redaction code path actually ran"

  if ! grep -q "define( 'DB_NAME'" "$extracted_dir/wp-config.php"; then
    echo "FAIL [$round_label]: unrelated wp-config.php content (DB_NAME) missing — redaction over-stripped the file"
    exit 1
  fi
  echo "OK  [$round_label] unrelated wp-config.php content preserved (redaction is surgical, not wholesale)"

  echo "=== [$round_label] Cleanup: reverting this round's wp-config.php test injection ==="
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env exec -T wp-source \
    sh -c "sed -i '/WPGUARD_H02_TEST_CONST_OVERRIDE_START/,/WPGUARD_H02_TEST_CONST_OVERRIDE_END/d' /var/www/html/wp-config.php" \
    2>&1 | grep -v '^ Container' || true
}

# Proves the current constant name redacts correctly (the ordinary,
# already-on-the-new-release case).
run_round 'WPTHISTLE_BACKUP_APP_PASSWORD' 'current-constant'

# 2.0.0 rename: WPGUARD_NC_APP_PASSWORD still works as a fallback for a
# site whose wp-config.php hasn't been updated to the new constant name
# yet -- proves redact_wp_config_contents() covers that name too, not
# just the current one. This is exactly the gap that would otherwise
# have shipped undetected, the same way H-02 originally did.
run_round 'WPGUARD_NC_APP_PASSWORD' 'legacy-constant'

echo
echo "=== H-02 CONSTANT-OVERRIDE REGRESSION TEST PASSED (current and legacy constant names) ==="
