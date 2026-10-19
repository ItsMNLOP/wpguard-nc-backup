#!/usr/bin/env bash
# Vex audit H-01 regression test: AJAX polling and the WP-Cron fallback
# could both call Job_Orchestrator::advance() for the same job in
# overlapping requests, racing on the same checkpoint with no mutual
# exclusion (fixed with a GET_LOCK/RELEASE_LOCK-backed Job_Store::try_lock()
# around advance() — see class-job-orchestrator.php, class-job-store.php).
#
# This test does not replay the full AJAX+WP-Cron scheduling machinery
# (that needs a real browser + wall-clock cron trigger); instead it
# exercises the actual production lock primitive (Job_Store::try_lock/
# release_lock) under two genuinely concurrent OS processes, each with
# its own separate MySQL connection — the same contention shape AJAX vs.
# cron produces. See docs/operations.md and STATUS.md for this test's
# documented scope versus Vex's original ask.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-lock-race-scratch"
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

JOB_ID="lock-race-test-$(date +%s)"
HOLD_SECONDS=2

echo "=== Launching holder (acquires lock, holds it for ${HOLD_SECONDS}s) ==="
wp_source eval-file /fixtures/lock-holder.php "$JOB_ID" "$HOLD_SECONDS" > "$SCRATCH/holder.log" 2>&1 &
HOLDER_PID=$!

# Real-time coordination delay (not a poll loop): give the holder time to
# actually win the race for the lock before the waiter starts contending
# for it, so this test reliably exercises contention instead of a tie.
sleep 0.5

echo "=== Launching waiter (retries non-blocking until the holder releases) ==="
wp_source eval-file /fixtures/lock-waiter.php "$JOB_ID" 50 > "$SCRATCH/waiter.log" 2>&1 &
WAITER_PID=$!

wait "$HOLDER_PID"
wait "$WAITER_PID"

echo "--- holder log ---"
cat "$SCRATCH/holder.log"
echo "--- waiter log ---"
cat "$SCRATCH/waiter.log"

HOLDER_ACQUIRED=$(grep -oP 'HOLDER acquired=\K[01]' "$SCRATCH/holder.log" || true)
HOLDER_RELEASED_AT=$(grep -oP 'HOLDER released at=\K[0-9.]+' "$SCRATCH/holder.log" || true)
WAITER_ATTEMPTS=$(grep -oP 'WAITER attempts=\K[0-9]+' "$SCRATCH/waiter.log" || true)
WAITER_ACQUIRED=$(grep -oP 'acquired=\K[01]' "$SCRATCH/waiter.log" || true)
WAITER_ACQUIRED_AT=$(grep -oP 'WAITER .*at=\K[0-9.]+' "$SCRATCH/waiter.log" || true)

[ "$HOLDER_ACQUIRED" = "1" ] || { echo "FAIL: holder never acquired the lock"; exit 1; }
[ -n "$HOLDER_RELEASED_AT" ] || { echo "FAIL: holder never reported releasing the lock"; exit 1; }
[ "$WAITER_ACQUIRED" = "1" ] || { echo "FAIL: waiter never acquired the lock after the holder released it"; exit 1; }
echo "OK  both processes reached expected lock states"

[ "$WAITER_ATTEMPTS" -gt 1 ] || { echo "FAIL: waiter acquired the lock on its first attempt — mutual exclusion was not actually exercised (holder may not have been holding it yet)"; exit 1; }
echo "OK  waiter was genuinely blocked for $WAITER_ATTEMPTS attempt(s) while the holder held the lock"

AFTER=$(python3 -c "print(1 if $WAITER_ACQUIRED_AT >= $HOLDER_RELEASED_AT else 0)")
[ "$AFTER" = "1" ] || { echo "FAIL: waiter acquired the lock ($WAITER_ACQUIRED_AT) before the holder released it ($HOLDER_RELEASED_AT) — mutual exclusion is broken"; exit 1; }
echo "OK  waiter's acquisition ($WAITER_ACQUIRED_AT) happened at or after the holder's release ($HOLDER_RELEASED_AT) — real concurrent-process mutual exclusion holds"

echo
echo "=== H-01 LOCK-CONTENTION REGRESSION TEST PASSED ==="
