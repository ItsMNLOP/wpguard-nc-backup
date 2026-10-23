<?php
/**
 * security audit finding H-01 regression fixture. Run via `wp eval-file lock-holder.php
 * <job_id> <hold_seconds>` from a disposable wpcli container. Acquires the
 * real Job_Store advisory lock and holds it for a fixed duration, so a
 * concurrently-running lock-waiter.php process (a genuinely separate OS
 * process and MySQL connection — the same shape as an AJAX request racing
 * a WP-Cron request) can be proven to block on it.
 */
$job_id = $args[0];
$hold_seconds = (float) $args[1];

$acquired = \WPThistle\BackupRestore\Job_Store::try_lock( $job_id );
echo 'HOLDER acquired=' . ( $acquired ? '1' : '0' ) . ' at=' . microtime( true ) . "\n";

if ( $acquired ) {
	usleep( (int) ( $hold_seconds * 1000000 ) );
	\WPThistle\BackupRestore\Job_Store::release_lock( $job_id );
	echo 'HOLDER released at=' . microtime( true ) . "\n";
}
