<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Manifest;

function test_accepts_current_format_version() {
	$result = Manifest::validate_format_version( array( 'format_version' => \WPTHISTLE_BACKUP_FORMAT_VERSION ) );
	wpguard_assert( true === $result, 'current format_version must validate' );
}

function test_rejects_future_format_version() {
	$result = Manifest::validate_format_version( array( 'format_version' => \WPTHISTLE_BACKUP_FORMAT_VERSION + 1 ) );
	wpguard_assert( is_string( $result ), 'a newer-than-supported format_version must be rejected, not guessed at' );
}

function test_rejects_missing_format_version() {
	$result = Manifest::validate_format_version( array() );
	wpguard_assert( is_string( $result ), 'a manifest with no format_version must be rejected' );
}

function test_rejects_non_integer_format_version() {
	$result = Manifest::validate_format_version( array( 'format_version' => '1' ) );
	wpguard_assert( is_string( $result ), 'format_version must be a real integer, not a numeric string' );
}

// Phase 2 (incremental backups bumped format_version 1 -> 2): restoring
// an OLDER real backup already on a site must keep working -- only
// forward-compatibility (a newer manifest than this plugin understands)
// is meant to be rejected.
function test_accepts_older_format_version() {
	$result = Manifest::validate_format_version( array( 'format_version' => 1 ) );
	wpguard_assert( true === $result, 'an older (format_version 1) manifest from a pre-incremental backup must still validate, not be rejected as unsupported' );
}

return array(
	'test_accepts_current_format_version',
	'test_accepts_older_format_version',
	'test_rejects_future_format_version',
	'test_rejects_missing_format_version',
	'test_rejects_non_integer_format_version',
);
