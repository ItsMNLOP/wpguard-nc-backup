#!/usr/bin/env bash
# ADR 0010 (WebDAV chunking v2): proves Nextcloud_Client::upload_part()
# live against all three backends, over real authenticated WebDAV --
# not just the unit-level URL-building/name-filtering tests.
#
# - Nextcloud + ownCloud: a part bigger than one chunk goes through the
#   real MKCOL/PUT.../MOVE chunking-v2 dance (supports_chunked_upload_v2()
#   must report true), and the assembled file is byte-identical to the
#   source.
# - OpenCloud: supports_chunked_upload_v2() must report false (confirmed
#   live 2026-10-22 -- it 404s on the uploads/ collection, unlike the
#   other two), and upload_part() must still succeed via the plain-PUT
#   fallback for a file bigger than one chunk.
# - Resume, proven directly rather than just argued: a chunk 0 with
#   DELIBERATELY WRONG content is planted in the upload session before
#   calling upload_part() with the same upload_id. If resume (skip
#   already-uploaded chunks, discovered via a real PROPFIND against the
#   server) is actually engaged, the final assembled file's first chunk
#   must still be the WRONG planted bytes -- upload_part() never
#   touches it again -- while every later chunk matches the real source
#   file. This is the one proof that actually distinguishes "resumed"
#   from "silently re-uploaded everything and happened to produce the
#   same result."
#
# Requires the multi-backend compose profile already up (see
# run-e2e-multi-backend.sh's own header for the exact command).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-chunked-upload-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)
OC_BACKUP_USER=$(env_val OC_BACKUP_USER)
OC_BACKUP_USER_PASSWORD=$(env_val OC_BACKUP_USER_PASSWORD)
OPENCLOUD_BACKUP_USER=$(env_val OPENCLOUD_BACKUP_USER)
NC_BACKUP_USER=$(env_val NC_BACKUP_USER)
NC_BACKUP_USER_PASSWORD=$(env_val NC_BACKUP_USER_PASSWORD)

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
    -v "$SCRATCH:/scratch:rw" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container'
}

# This script reconfigures wp-source's live plugin settings three
# times (one per backend) to drive upload_part() directly -- same
# pollution risk run-e2e-multi-backend.sh already guards against (a
# prior session hit this exact bug: run-e2e.sh's own Step 2 hardcodes
# the "wpguard-backups" folder name and failed outright, empty-body
# manifest.json, once this script left wp-source pointed at its own
# "wpguard-chunked-e2e" folder instead). Captured and restored on exit,
# same pattern.
ORIGINAL_SETTINGS_JSON=$(wp_source option get wpguard_nc_settings --format=json)
printf '%s' "$ORIGINAL_SETTINGS_JSON" > "$SCRATCH/original-settings.json"
restore_original_settings() {
  echo "=== Restoring wp-source's original plugin settings ==="
  wp_source_scratch eval '
    $data = json_decode( file_get_contents( "/scratch/original-settings.json" ), true );
    $r = \WPGuard\NCBackup\Settings::save( $data );
    echo $r["ok"] ? "restored ok\n" : "RESTORE FAILED: " . wp_json_encode( $r["errors"] ) . "\n";
  '
}
trap restore_original_settings EXIT

echo "=== Mint fresh credentials for Nextcloud (app password via OCS) ==="
NC_APP_PASSWORD=$(curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$NC_BACKUP_USER_PASSWORD" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://127.0.0.1:8443/ocs/v2.php/core/getapppassword | sed -n 's/.*"apppassword":"\([^"]*\)".*/\1/p')
[ -n "$NC_APP_PASSWORD" ] || { echo "FAIL: could not mint a Nextcloud app password"; exit 1; }

echo "=== Mint a fresh OpenCloud app token ==="
OPENCLOUD_BACKUP_TOKEN=$(docker exec wpguard-test-opencloud-1 opencloud auth-app create --user-name "$OPENCLOUD_BACKUP_USER" --expiration 1h | sed -n 's/^ token: //p')
[ -n "$OPENCLOUD_BACKUP_TOKEN" ] || { echo "FAIL: could not mint an OpenCloud app token"; exit 1; }

# Unique per run -- an upload_id must be STABLE across retries of the
# SAME logical upload (that's the whole point, ADR 0010), but reusing a
# literal across different runs of THIS test script, each regenerating
# a fresh random big.bin, would let a previous run's leftover
# server-side chunks (if an earlier run died before reaching MOVE) get
# silently "resumed" against this run's different content -- a test
# artifact, not a real production scenario (a real job_id is unique
# per job already). Caught live during development of this exact script.
RUN_ID="$(date +%s)-$$"

echo "=== Build a 20MB test file with known content (2.5x CHUNK_SIZE_BYTES) ==="
head -c 20971520 /dev/urandom > "$SCRATCH/big.bin"
SOURCE_SHA256=$(sha256sum "$SCRATCH/big.bin" | cut -d' ' -f1)
echo "OK  source file built, sha256=$SOURCE_SHA256"

configure_backend() {
  local backend="$1" url="$2" user="$3" cred="$4" folder="wpguard-chunked-e2e"
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    -e WPGUARD_TEST_BACKEND="$backend" \
    -e WPGUARD_TEST_NC_URL="$url" \
    -e WPGUARD_TEST_NC_USER="$user" \
    -e WPGUARD_TEST_NC_APP_PASSWORD="$cred" \
    -e WPGUARD_TEST_NC_FOLDER="$folder" \
    wpcli wp --path=/var/www/html-source eval-file /fixtures/configure-plugin.php 2>&1 | grep -v '^ Container' > /dev/null || true
}

run_chunked_proof() {
  local backend="$1" expect_chunking="$2"
  echo
  echo "### Backend: $backend (expect chunking-v2 support: $expect_chunking) ###"

  SUPPORTED=$(wp_source eval '
    $logger = new \WPGuard\NCBackup\Logger( array() );
    $client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( $logger );
    echo $client->supports_chunked_upload_v2() ? "yes" : "no";
  ')
  [ "$SUPPORTED" = "$expect_chunking" ] || { echo "FAIL ($backend): supports_chunked_upload_v2() returned '$SUPPORTED', expected '$expect_chunking'"; exit 1; }
  echo "OK  ($backend) supports_chunked_upload_v2() = $SUPPORTED"

  RESULT=$(wp_source_scratch eval '
    $logger = new \WPGuard\NCBackup\Logger( array() );
    $client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( $logger );
    $client->mkcol_recursive( trim( \WPGuard\NCBackup\Settings::get_remote_folder(), "/" ) );
    $rel = $client->relative_path( "chunked-proof.bin" );
    $r = $client->upload_part( "/scratch/big.bin", $rel, "proof-upload-id-'"$RUN_ID"'" );
    if ( is_wp_error( $r ) ) { echo "ERROR:" . $r->get_error_message(); exit; }
    $dl = $client->download_to_file( $rel, "/scratch/downloaded.bin" );
    if ( is_wp_error( $dl ) ) { echo "ERROR:" . $dl->get_error_message(); exit; }
    echo "OK";
  ')
  [ "$RESULT" = "OK" ] || { echo "FAIL ($backend): upload_part() round trip: $RESULT"; exit 1; }
  DOWNLOADED_SHA256=$(sha256sum "$SCRATCH/downloaded.bin" | cut -d' ' -f1)
  [ "$DOWNLOADED_SHA256" = "$SOURCE_SHA256" ] || { echo "FAIL ($backend): downloaded content does not match source (sha256 mismatch)"; exit 1; }
  echo "OK  ($backend) upload_part() round trip byte-identical (sha256 $DOWNLOADED_SHA256)"
  rm -f "$SCRATCH/downloaded.bin"

  wp_source eval '
    $logger = new \WPGuard\NCBackup\Logger( array() );
    $client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( $logger );
    $client->delete( $client->relative_path( "chunked-proof.bin" ) );
  ' > /dev/null || true
}

echo
echo "=== Nextcloud ==="
configure_backend "nextcloud" "https://nc-proxy.wpguard-test.local" "$NC_BACKUP_USER" "$NC_APP_PASSWORD"
run_chunked_proof "nextcloud" "yes"

echo
echo "=== ownCloud ==="
configure_backend "owncloud" "https://oc-proxy.wpguard-test.local" "$OC_BACKUP_USER" "$OC_BACKUP_USER_PASSWORD"
run_chunked_proof "owncloud" "yes"

echo
echo "=== OpenCloud (expect plain-PUT fallback, chunking-v2 NOT supported) ==="
configure_backend "opencloud" "https://opencloud.wpguard-test.local:9200" "$OPENCLOUD_BACKUP_USER" "$OPENCLOUD_BACKUP_TOKEN"
run_chunked_proof "opencloud" "no"

echo
echo "=== Resume proof (Nextcloud): plant a WRONG chunk 0, confirm it survives untouched ==="
configure_backend "nextcloud" "https://nc-proxy.wpguard-test.local" "$NC_BACKUP_USER" "$NC_APP_PASSWORD"

WRONG_CHUNK_SIZE=8388608
head -c "$WRONG_CHUNK_SIZE" /dev/zero | tr '\0' 'X' > "$SCRATCH/wrong-chunk0.bin"

PLANT_RESULT=$(MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
  -e U="$NC_BACKUP_USER" -e P="$NC_APP_PASSWORD" \
  -v "$SCRATCH:/scratch:ro" wpcli php -r '
$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => "https://nc-proxy.wpguard-test.local/remote.php/dav/uploads/" . rawurlencode(getenv("U")) . "/resume-proof-upload-id-'"$RUN_ID"'",
  CURLOPT_CUSTOMREQUEST => "MKCOL",
  CURLOPT_USERPWD => getenv("U") . ":" . getenv("P"),
  CURLOPT_CAINFO => "/certs/wpguard-test-ca.crt",
  CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch);
$mk_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$fh = fopen("/scratch/wrong-chunk0.bin", "rb");
$ch2 = curl_init();
curl_setopt_array($ch2, [
  CURLOPT_URL => "https://nc-proxy.wpguard-test.local/remote.php/dav/uploads/" . rawurlencode(getenv("U")) . "/resume-proof-upload-id-'"$RUN_ID"'/000000",
  CURLOPT_CUSTOMREQUEST => "PUT",
  CURLOPT_PUT => true,
  CURLOPT_INFILE => $fh,
  CURLOPT_INFILESIZE => filesize("/scratch/wrong-chunk0.bin"),
  CURLOPT_USERPWD => getenv("U") . ":" . getenv("P"),
  CURLOPT_CAINFO => "/certs/wpguard-test-ca.crt",
  CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch2);
$put_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
fclose($fh);
echo "mkcol=$mk_code put=$put_code";
' 2>&1 | grep -v '^ Container')
echo "$PLANT_RESULT" | grep -qE 'mkcol=(201|405) put=(200|201|204)' || { echo "FAIL: could not plant the wrong chunk 0 ($PLANT_RESULT)"; exit 1; }
echo "OK  wrong chunk 0 planted directly on the server: $PLANT_RESULT"

RESUME_RESULT=$(wp_source_scratch eval '
	$logger = new \WPGuard\NCBackup\Logger( array() );
	$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( $logger );
	$client->mkcol_recursive( trim( \WPGuard\NCBackup\Settings::get_remote_folder(), "/" ) );
	$rel = $client->relative_path( "resume-proof.bin" );
	$r = $client->upload_part( "/scratch/big.bin", $rel, "resume-proof-upload-id-'"$RUN_ID"'" );
	if ( is_wp_error( $r ) ) { echo "ERROR:" . $r->get_error_message(); exit; }
	$dl = $client->download_to_file( $rel, "/scratch/resume-downloaded.bin" );
	if ( is_wp_error( $dl ) ) { echo "ERROR:" . $dl->get_error_message(); exit; }
	echo "OK";
')
[ "$RESUME_RESULT" = "OK" ] || { echo "FAIL: resume-case upload_part() round trip: $RESUME_RESULT"; exit 1; }

# The decisive check: first WRONG_CHUNK_SIZE bytes must be the planted
# 'X' bytes (proving chunk 0 was never re-uploaded), everything after
# must match the real source file (proving the rest WAS uploaded this
# call, not silently skipped too).
FIRST_CHUNK_ACTUAL=$(head -c "$WRONG_CHUNK_SIZE" "$SCRATCH/resume-downloaded.bin" | sha256sum | cut -d' ' -f1)
FIRST_CHUNK_EXPECTED_WRONG=$(sha256sum "$SCRATCH/wrong-chunk0.bin" | cut -d' ' -f1)
[ "$FIRST_CHUNK_ACTUAL" = "$FIRST_CHUNK_EXPECTED_WRONG" ] || { echo "FAIL: chunk 0 of the final file does not match the deliberately-planted wrong bytes -- resume did NOT skip it (or something else is broken)"; exit 1; }
echo "OK  chunk 0 survived untouched (matches the deliberately wrong planted bytes) -- proves resume genuinely skipped it, not just happened to match"

REST_ACTUAL=$(tail -c +"$((WRONG_CHUNK_SIZE + 1))" "$SCRATCH/resume-downloaded.bin" | sha256sum | cut -d' ' -f1)
REST_EXPECTED=$(tail -c +"$((WRONG_CHUNK_SIZE + 1))" "$SCRATCH/big.bin" | sha256sum | cut -d' ' -f1)
[ "$REST_ACTUAL" = "$REST_EXPECTED" ] || { echo "FAIL: bytes after chunk 0 do not match the real source file -- the rest of the upload didn't complete correctly"; exit 1; }
echo "OK  every byte after chunk 0 matches the real source file -- the remaining chunks uploaded correctly this call"

wp_source eval '
	$logger = new \WPGuard\NCBackup\Logger( array() );
	$client = \WPGuard\NCBackup\Nextcloud_Client::from_settings( $logger );
	$client->delete( $client->relative_path( "resume-proof.bin" ) );
' > /dev/null || true

echo
echo "=== CHUNKED UPLOAD (ADR 0010) E2E TEST PASSED ==="
