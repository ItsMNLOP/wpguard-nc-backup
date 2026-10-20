<?php
/**
 * Minimal test runner — no PHPUnit dependency (MVP has zero Composer
 * deps, see docs/architecture.md). Each test file returns an array of
 * function names in its own namespace; this runner requires the file,
 * calls each function, and reports pass/fail/exception.
 *
 * Usage: php tests/unit/run-all.php
 */

require __DIR__ . '/wp-shim.php';
require __DIR__ . '/assert.php';

$plugin_includes = dirname( __DIR__, 2 ) . '/plugin/wpguard-nc-backup/includes/';
foreach ( array( 'class-archive-writer.php', 'class-logger.php', 'class-manifest.php', 'class-ca-bundle.php', 'class-settings.php', 'class-update-checker.php', 'class-job-orchestrator.php', 'class-db-exporter.php' ) as $f ) {
	require $plugin_includes . $f;
}

$test_files = glob( __DIR__ . '/*Test.php' );
sort( $test_files );

$total = 0;
$failed = 0;

foreach ( $test_files as $file ) {
	$fn_names = require $file;
	foreach ( $fn_names as $fn ) {
		$total++;
		$qualified = '\\WPGuard\\NCBackup\\Tests\\' . $fn;
		try {
			$qualified();
			echo "PASS  " . basename( $file ) . "::{$fn}\n";
		} catch ( \Throwable $e ) {
			$failed++;
			echo "FAIL  " . basename( $file ) . "::{$fn} — " . $e->getMessage() . "\n";
		}
	}
}

echo "\n{$total} tests, " . ( $total - $failed ) . " passed, {$failed} failed.\n";
exit( $failed > 0 ? 1 : 0 );
