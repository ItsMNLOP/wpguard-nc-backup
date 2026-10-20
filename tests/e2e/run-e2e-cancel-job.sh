#!/usr/bin/env bash
# JOB-05 manual-recovery regression test: a job that's created but never
# advanced (simulating "WP-Cron fallback never picked it up, browser tab
# long closed") must be cancellable by an administrator -- marked failed,
# unblocking has_running_job() for a new backup -- without needing direct
# DB access. Exercises the real Job_Orchestrator::cancel() against a live
# WP install, not a mock.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container'
}

echo "=== Clear any pre-existing job row so this test starts from a clean slate ==="
wp_source db query "UPDATE wp_wpguard_nc_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true

echo "=== Create a job and leave it un-advanced (simulates a genuinely stranded job) ==="
JOB_ID=$(wp_source eval '
  $id = \WPGuard\NCBackup\Job_Orchestrator::create_job();
  echo $id;
')
[ -n "$JOB_ID" ] || { echo "FAIL: could not create a job"; exit 1; }
echo "job_id=$JOB_ID"

HAS_RUNNING=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::has_running_job() ? "yes" : "no";')
[ "$HAS_RUNNING" = "yes" ] || { echo "FAIL: has_running_job() should be true right after create_job()"; exit 1; }
echo "OK  has_running_job() = yes (new job correctly blocked while stuck)"

echo "=== Cancel it (the admin override, not the deferred Phase 2 lock-expiry system) ==="
STATUS_AFTER_CANCEL=$(wp_source eval "
  \$job = \WPGuard\NCBackup\Job_Orchestrator::cancel('$JOB_ID');
  echo \$job['status'] . '|' . \$job['error'];
")
echo "result: $STATUS_AFTER_CANCEL"
[[ "$STATUS_AFTER_CANCEL" == failed\|* ]] || { echo "FAIL: cancel() did not mark the job failed"; exit 1; }
[[ "$STATUS_AFTER_CANCEL" == *"Cancelled by administrator"* ]] || { echo "FAIL: cancel() did not record an explanatory error"; exit 1; }
echo "OK  job marked failed with an explanatory error"

HAS_RUNNING_AFTER=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::has_running_job() ? "yes" : "no";')
[ "$HAS_RUNNING_AFTER" = "no" ] || { echo "FAIL: has_running_job() should be false after cancelling the only job"; exit 1; }
echo "OK  has_running_job() = no (a new backup could start now)"

echo "=== Cancelling an already-terminal job is a no-op, not an error ==="
STATUS_SECOND_CANCEL=$(wp_source eval "
  \$job = \WPGuard\NCBackup\Job_Orchestrator::cancel('$JOB_ID');
  echo \$job['status'];
")
[ "$STATUS_SECOND_CANCEL" = "failed" ] || { echo "FAIL: cancelling an already-failed job should leave it failed, got: $STATUS_SECOND_CANCEL"; exit 1; }
echo "OK  cancelling an already-terminal job is idempotent"

echo
echo "=== JOB-05 CANCEL REGRESSION TEST PASSED ==="
