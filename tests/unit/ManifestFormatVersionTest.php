<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Manifest;

function test_accepts_current_format_version() {
	$result = Manifest::validate_format_version( array( 'format_version' => \WPGUARD_NC_FORMAT_VERSION ) );
	wpguard_assert( true === $result, 'current format_version must validate' );
}

function test_rejects_future_format_version() {
	$result = Manifest::validate_format_version( array( 'format_version' => \WPGUARD_NC_FORMAT_VERSION + 1 ) );
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

return array(
	'test_accepts_current_format_version',
	'test_rejects_future_format_version',
	'test_rejects_missing_format_version',
	'test_rejects_non_integer_format_version',
);
