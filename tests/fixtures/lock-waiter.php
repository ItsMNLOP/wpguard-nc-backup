<?php
/**
 * security audit finding H-01 regression fixture — see lock-holder.php. Retries the
 * real Job_Store advisory lock non-blocking (matching try_lock's actual
 * 0-timeout GET_LOCK usage in Job_Orchestrator::advance()) and records
 * how many attempts it took before acquiring, plus the timestamp of
 * acquisition — proof that a second concurrent process was genuinely
 * excluded while the first one held the lock, not just "also happened
 * to succeed."
 */
$job_id = $args[0];
$max_attempts = (int) $args[1];

$attempts = 0;
$acquired = false;
while ( $attempts < $max_attempts ) {
	$attempts++;
	if ( \WPGuard\NCBackup\Job_Store::try_lock( $job_id ) ) {
		$acquired = true;
		break;
	}
	usleep( 100000 );
}

echo 'WAITER attempts=' . $attempts . ' acquired=' . ( $acquired ? '1' : '0' ) . ' at=' . microtime( true ) . "\n";

if ( $acquired ) {
	\WPGuard\NCBackup\Job_Store::release_lock( $job_id );
}
