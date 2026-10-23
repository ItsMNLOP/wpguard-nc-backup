#!/usr/bin/env bash
# Phase 2 scheduling regression test: Settings::get_schedule()'s stored
# value must drive a real WP-Cron recurring event (sync_scheduled_backup()
# in wpguard-nc-backup.php) -- registered, rescheduled on change, and
# cleared when disabled -- against the real wp_options-backed cron array,
# not a mock. Also exercises run_scheduled_backup() (the hook's handler):
# it must start a job when none is running, and correctly skip (not
# overlap) when one already is -- reusing create_job()'s JOB-05 reaper
# as-is.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)

wp_source() {
  # `|| true`: see run-e2e-stale-lock-recovery.sh for why a genuinely
  # empty/filtered result must not be treated as this helper failing.
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container' || true
}

echo "=== Setup: clean job table, save+sync schedule=disabled as the known starting state ==="
wp_source db query "UPDATE wp_wpguard_nc_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true
wp_source eval '
  \WPGuard\NCBackup\Settings::save(array(
    "nextcloud_url" => \WPGuard\NCBackup\Settings::get_nextcloud_url(),
    "nextcloud_user" => \WPGuard\NCBackup\Settings::get_nextcloud_user(),
    "remote_folder" => \WPGuard\NCBackup\Settings::get_remote_folder(),
    "schedule" => "disabled",
  ));
  \WPGuard\NCBackup\sync_scheduled_backup();
' > /dev/null

NEXT=$(wp_source eval 'echo wp_next_scheduled("wpguard_nc_scheduled_backup") ? "scheduled" : "none";')
[ "$NEXT" = "none" ] || { echo "FAIL: schedule=disabled must not leave a recurring event registered, got '$NEXT'"; exit 1; }
echo "OK  disabled schedule has no recurring event registered"

echo
echo "=== Case A: schedule=daily must register a real 'daily' WP-Cron event ==="
wp_source eval '
  \WPGuard\NCBackup\Settings::save(array(
    "nextcloud_url" => \WPGuard\NCBackup\Settings::get_nextcloud_url(),
    "nextcloud_user" => \WPGuard\NCBackup\Settings::get_nextcloud_user(),
    "remote_folder" => \WPGuard\NCBackup\Settings::get_remote_folder(),
    "schedule" => "daily",
  ));
  \WPGuard\NCBackup\sync_scheduled_backup();
' > /dev/null

EVENT_A=$(wp_source eval '
  $e = wp_get_scheduled_event("wpguard_nc_scheduled_backup");
  echo $e ? $e->schedule : "none";
')
[ "$EVENT_A" = "daily" ] || { echo "FAIL: expected a 'daily' recurring event, got '$EVENT_A'"; exit 1; }
echo "OK  'daily' event registered"

echo
echo "=== Case B: switching to weekly must reschedule to the real custom 'weekly' recurrence (7 days), not leave 'daily' in place ==="
wp_source eval '
  \WPGuard\NCBackup\Settings::save(array(
    "nextcloud_url" => \WPGuard\NCBackup\Settings::get_nextcloud_url(),
    "nextcloud_user" => \WPGuard\NCBackup\Settings::get_nextcloud_user(),
    "remote_folder" => \WPGuard\NCBackup\Settings::get_remote_folder(),
    "schedule" => "weekly",
  ));
  \WPGuard\NCBackup\sync_scheduled_backup();
' > /dev/null

RESULT_B=$(wp_source eval '
  $e = wp_get_scheduled_event("wpguard_nc_scheduled_backup");
  $schedules = wp_get_schedules();
  echo ($e ? $e->schedule : "none") . "|" . ($schedules["weekly"]["interval"] ?? "missing");
')
echo "weekly event: $RESULT_B"
[[ "$RESULT_B" == weekly\|604800 ]] || { echo "FAIL: expected 'weekly' at a real 604800s (7-day) interval, got '$RESULT_B'"; exit 1; }
echo "OK  rescheduled to the real custom 'weekly' recurrence (604800s)"

echo
echo "=== Case C: switching back to disabled must clear the recurring event entirely ==="
wp_source eval '
  \WPGuard\NCBackup\Settings::save(array(
    "nextcloud_url" => \WPGuard\NCBackup\Settings::get_nextcloud_url(),
    "nextcloud_user" => \WPGuard\NCBackup\Settings::get_nextcloud_user(),
    "remote_folder" => \WPGuard\NCBackup\Settings::get_remote_folder(),
    "schedule" => "disabled",
  ));
  \WPGuard\NCBackup\sync_scheduled_backup();
' > /dev/null

NEXT_AFTER_DISABLE=$(wp_source eval 'echo wp_next_scheduled("wpguard_nc_scheduled_backup") ? "scheduled" : "none";')
[ "$NEXT_AFTER_DISABLE" = "none" ] || { echo "FAIL: disabling must clear the recurring event, got '$NEXT_AFTER_DISABLE'"; exit 1; }
echo "OK  recurring event cleared after disabling"

echo
echo "=== Case D: run_scheduled_backup() starts a real job when none is running ==="
JOB_BEFORE=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_BEFORE" = "no" ] || { echo "FAIL: test setup problem -- a job is already running before Case D"; exit 1; }

wp_source eval '\WPGuard\NCBackup\run_scheduled_backup();' > /dev/null
JOB_AFTER=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_AFTER" = "yes" ] || { echo "FAIL: run_scheduled_backup() should have started a real job (has_credentials() is true in this stack), but has_running_job() is still 'no'"; exit 1; }
echo "OK  run_scheduled_backup() started a real job"

FIRST_JOB_ID=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::get_latest()["job_id"];')
echo "job_id=$FIRST_JOB_ID"

echo
echo "=== Case E: a second run_scheduled_backup() while that job is still genuinely running must NOT overlap it ==="
wp_source eval '\WPGuard\NCBackup\run_scheduled_backup();' > /dev/null
SECOND_JOB_ID=$(wp_source eval 'echo \WPGuard\NCBackup\Job_Store::get_latest()["job_id"];')
[ "$SECOND_JOB_ID" = "$FIRST_JOB_ID" ] || { echo "FAIL: a second scheduled run should not have started a new job while the first is still running (not stale); got a new id '$SECOND_JOB_ID'"; exit 1; }
echo "OK  second scheduled run correctly skipped -- no overlapping job"

echo
echo "=== Cleanup: cancel the job this test started, leave schedule disabled ==="
wp_source eval "\WPGuard\NCBackup\Job_Orchestrator::cancel('$FIRST_JOB_ID');" > /dev/null

echo
echo "=== SCHEDULING REGRESSION TEST PASSED ==="
