<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Logger;

function test_redacts_known_secret_value() {
	$secret = 'xk92-superSecretAppPassword-99';
	$logger = new Logger( array( $secret ) );
	$out = $logger->redact( "PUT failed, request body auth used password {$secret} against host" );
	wpguard_assert( false === strpos( $out, $secret ), 'secret value must not appear in redacted output' );
	wpguard_assert( false !== strpos( $out, '[redacted]' ), 'redacted output should contain the redaction marker' );
}

function test_redacts_basic_auth_header_shape() {
	$logger = new Logger( array() );
	$out = $logger->redact( 'Authorization: Basic d3BndWFyZDpzdXBlcnNlY3JldA==' );
	wpguard_assert( false === strpos( $out, 'd3BndWFyZDpzdXBlcnNlY3JldA==' ), 'basic auth header value must be redacted even without a known secret list' );
}

function test_entry_redacts_message_and_context() {
	$secret = 'abc123secret';
	$logger = new Logger( array( $secret ) );
	$entry = $logger->entry( 'job-1', 'upload_parts', 'error', "failed with {$secret}", array( 'detail' => "see {$secret} here" ) );
	wpguard_assert( false === strpos( $entry['message'], $secret ), 'entry message must be redacted' );
	wpguard_assert( false === strpos( $entry['context']['detail'], $secret ), 'entry context must be redacted' );
}

function test_empty_secret_list_does_not_error() {
	$logger = new Logger( array( '', null, 'real-secret' ) );
	$out = $logger->redact( 'contains real-secret value' );
	wpguard_assert( false === strpos( $out, 'real-secret' ), 'non-empty secret should still redact when blanks are also in the list' );
}

return array(
	'test_redacts_known_secret_value',
	'test_redacts_basic_auth_header_shape',
	'test_entry_redacts_message_and_context',
	'test_empty_secret_list_does_not_error',
);
