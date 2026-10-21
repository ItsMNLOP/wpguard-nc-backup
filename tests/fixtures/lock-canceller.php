<?php
/**
 * security audit finding LOW-C1 regression fixture. Run via `wp eval-file
 * lock-canceller.php <job_id>` while a concurrent lock-holder.php process
 * holds the real Job_Store advisory lock. Calls Job_Orchestrator::cancel()
 * and reports what it got back -- proves cancel() correctly reports
 * lock_contended (and changes nothing) instead of silently "succeeding"
 * while advance() is actively running, same as lock-waiter.php proves for
 * try_lock() directly but exercised through the actual cancel() code path.
 */
$job_id = $args[0];

$job = \WPGuard\NCBackup\Job_Orchestrator::cancel( $job_id );
echo 'CANCELLER cancel_result=' . ( $job['cancel_result'] ?? '' ) . ' status=' . ( $job['status'] ?? '' ) . ' at=' . microtime( true ) . "\n";
