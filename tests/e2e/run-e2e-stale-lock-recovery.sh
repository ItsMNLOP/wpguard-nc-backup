#!/usr/bin/env bash
# JOB-05 automatic-recovery regression test: Job_Orchestrator::reap_stale_lock()
# must clear a genuinely abandoned job (no checkpoint progress for longer
# than the staleness threshold) automatically -- no admin click, no real
# server-cron -- while never racing a job whose advisory lock is actually
# held by a live process, however old its updated_at looks. Exercises the
# real Job_Store/Job_Orchestrator primitives against a live WP install, not
# a mock. Companion to run-e2e-cancel-job.sh (the manual override) and
# run-e2e-lock-race.sh (the underlying try_lock()/release_lock() primitive).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-stale-lock-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)

wp_source() {
  # `|| true`: grep -v exits 1 when it filters out every line (e.g. a
  # create_job() call that legitimately returns "" because it was
  # correctly blocked -- Case A/C below). That's not a real failure; the
  # actual pass/fail checks are the explicit assertions after each call,
  # not this helper's exit code.
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container' || true
}

age_updated_at() {
  # Simulate real elapsed time without actually waiting -- push updated_at
  # into the past by $2 seconds for job $1.
  wp_source db query "UPDATE ${WP_SOURCE_TABLE_PREFIX}wpthistle_backup_jobs SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL $2 SECOND) WHERE job_id = '$1';"
}

echo "=== Clear any pre-existing job row so this test starts from a clean slate ==="
wp_source db query "UPDATE ${WP_SOURCE_TABLE_PREFIX}wpthistle_backup_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true

echo "=== Case A: a fresh (non-stale) job must NOT be reaped ==="
JOB_A=$(wp_source eval '
  $id = \WPThistle\BackupRestore\Job_Orchestrator::create_job();
  echo $id;
')
[ -n "$JOB_A" ] || { echo "FAIL: could not create job A"; exit 1; }
echo "job_a=$JOB_A"

SECOND_ATTEMPT=$(wp_source eval '
  $id = \WPThistle\BackupRestore\Job_Orchestrator::create_job();
  echo $id;
')
[ -z "$SECOND_ATTEMPT" ] || { echo "FAIL: create_job() should have been blocked by the fresh, non-stale job A, but returned '$SECOND_ATTEMPT'"; exit 1; }
echo "OK  non-stale job correctly left alone (create_job() returned empty, job A untouched)"

STATUS_A=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_A')['status'];")
[ "$STATUS_A" = "queued" ] || { echo "FAIL: job A should still be 'queued', got '$STATUS_A'"; exit 1; }
echo "OK  job A status unchanged (queued)"

echo
echo "=== Case B: aging job A past the staleness threshold (600s) must trigger auto-recovery ==="
age_updated_at "$JOB_A" 700

JOB_B=$(wp_source eval '
  $id = \WPThistle\BackupRestore\Job_Orchestrator::create_job();
  echo $id;
')
[ -n "$JOB_B" ] && [ "$JOB_B" != "$JOB_A" ] || { echo "FAIL: create_job() should have reaped stale job A and returned a new job id, got '$JOB_B'"; exit 1; }
echo "OK  create_job() returned a genuinely new job: $JOB_B"

RESULT_A=$(wp_source eval "
  \$job = \WPThistle\BackupRestore\Job_Store::get('$JOB_A');
  echo \$job['status'] . '|' . \$job['error'];
")
echo "job A after reap: $RESULT_A"
[[ "$RESULT_A" == failed\|* ]] || { echo "FAIL: stale job A should now be 'failed', got '$RESULT_A'"; exit 1; }
[[ "$RESULT_A" == *"Automatically recovered"* ]] || { echo "FAIL: job A's error should explain the automatic recovery, got '$RESULT_A'"; exit 1; }
echo "OK  stale job A automatically marked failed with an explanatory error"

echo
echo "=== Cleanup: fail job B so the next case starts clean ==="
wp_source eval "\WPThistle\BackupRestore\Job_Orchestrator::cancel('$JOB_B');" > /dev/null

echo
echo "=== Case C: a job whose lock is genuinely held must NOT be reaped, however stale its clock looks ==="
JOB_C=$(wp_source eval '
  $id = \WPThistle\BackupRestore\Job_Orchestrator::create_job();
  echo $id;
')
[ -n "$JOB_C" ] || { echo "FAIL: could not create job C"; exit 1; }
age_updated_at "$JOB_C" 700

HOLD_SECONDS=3
echo "Launching a real concurrent holder of job C's advisory lock for ${HOLD_SECONDS}s..."
wp_source eval-file /fixtures/lock-holder.php "$JOB_C" "$HOLD_SECONDS" > "$SCRATCH/holder.log" 2>&1 &
HOLDER_PID=$!
sleep 0.5

ATTEMPT_WHILE_HELD=$(wp_source eval '
  $id = \WPThistle\BackupRestore\Job_Orchestrator::create_job();
  echo $id;
')
[ -z "$ATTEMPT_WHILE_HELD" ] || { echo "FAIL: create_job() should have been blocked -- job C's lock is genuinely held, reaping it now would race a live process. Got '$ATTEMPT_WHILE_HELD'"; exit 1; }
echo "OK  stale-by-clock job C correctly left alone while its lock is genuinely held"

STATUS_C_WHILE_HELD=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_C')['status'];")
[ "$STATUS_C_WHILE_HELD" = "queued" ] || { echo "FAIL: job C should still be 'queued' while its lock is held, got '$STATUS_C_WHILE_HELD'"; exit 1; }
echo "OK  job C status unchanged while held"

wait "$HOLDER_PID"
cat "$SCRATCH/holder.log"

echo
echo "=== Case C (continued): once the lock is free, the still-stale job C must now be reaped ==="
JOB_D=$(wp_source eval '
  $id = \WPThistle\BackupRestore\Job_Orchestrator::create_job();
  echo $id;
')
[ -n "$JOB_D" ] && [ "$JOB_D" != "$JOB_C" ] || { echo "FAIL: create_job() should have reaped job C once its lock was free, got '$JOB_D'"; exit 1; }
echo "OK  job C reaped once its lock was released: new job $JOB_D"

RESULT_C=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_C')['status'];")
[ "$RESULT_C" = "failed" ] || { echo "FAIL: job C should now be 'failed', got '$RESULT_C'"; exit 1; }
echo "OK  job C marked failed after its lock freed up"

echo "=== Cleanup: fail job D ==="
wp_source eval "\WPThistle\BackupRestore\Job_Orchestrator::cancel('$JOB_D');" > /dev/null

echo
echo "=== JOB-05 AUTOMATIC-RECOVERY REGRESSION TEST PASSED ==="
