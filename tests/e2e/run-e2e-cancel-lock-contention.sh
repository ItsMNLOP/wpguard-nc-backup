#!/usr/bin/env bash
# Vex audit LOW-C1 regression test: cancel() must report lock_contended
# (and change nothing) when advance() genuinely holds the H-01 lock right
# now, not silently report success while the job stays running. Exercises
# the real cancel() against a real concurrent holder, not a mock.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-cancel-contention-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container'
}

echo "=== Clear any pre-existing job row ==="
wp_source db query "UPDATE wp_wpguard_nc_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true

echo "=== Create a job (un-advanced) to cancel against ==="
JOB_ID=$(wp_source eval '
  $id = \WPGuard\NCBackup\Job_Orchestrator::create_job();
  echo $id;
')
[ -n "$JOB_ID" ] || { echo "FAIL: could not create a job"; exit 1; }
echo "job_id=$JOB_ID"

HOLD_SECONDS=2
echo "=== Launching holder (acquires lock, holds it for ${HOLD_SECONDS}s) ==="
wp_source eval-file /fixtures/lock-holder.php "$JOB_ID" "$HOLD_SECONDS" > "$SCRATCH/holder.log" 2>&1 &
HOLDER_PID=$!

sleep 0.5
echo "=== Launching canceller while holder still holds the lock ==="
wp_source eval-file /fixtures/lock-canceller.php "$JOB_ID" > "$SCRATCH/canceller1.log" 2>&1 &
CANCELLER1_PID=$!

wait "$HOLDER_PID"
wait "$CANCELLER1_PID"

echo "--- holder log ---"; cat "$SCRATCH/holder.log"
echo "--- canceller (during contention) log ---"; cat "$SCRATCH/canceller1.log"

CONTENDED_RESULT=$(grep -oP 'cancel_result=\K\S+' "$SCRATCH/canceller1.log" || true)
[ "$CONTENDED_RESULT" = "lock_contended" ] || { echo "FAIL: expected cancel_result=lock_contended while holder held the lock, got: $CONTENDED_RESULT"; exit 1; }
echo "OK  cancel() correctly reported lock_contended, not success, while advance()'s lock was held"

STATUS_AFTER_CONTENDED=$(wp_source eval "
  \$job = \WPGuard\NCBackup\Job_Store::get('$JOB_ID');
  echo \$job['status'];
")
[ "$STATUS_AFTER_CONTENDED" = "queued" ] || { echo "FAIL: job status should be untouched (queued), got: $STATUS_AFTER_CONTENDED"; exit 1; }
echo "OK  job status unchanged by the contended cancel attempt"

echo "=== Canceller again, now that the holder has released the lock ==="
AFTER_RELEASE_RESULT=$(wp_source eval-file /fixtures/lock-canceller.php "$JOB_ID")
echo "$AFTER_RELEASE_RESULT"
echo "$AFTER_RELEASE_RESULT" | grep -q 'cancel_result=cancelled' || { echo "FAIL: cancel should succeed once the lock is free"; exit 1; }
echo "OK  cancel() succeeded once the lock was free"

echo
echo "=== LOW-C1 CANCEL/LOCK-CONTENTION REGRESSION TEST PASSED ==="
