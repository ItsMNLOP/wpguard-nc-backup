<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Restore_Orchestrator;

// Found live, restoring a real cross-site backup onto a plain-HTTP
// disposable instance: WordPress core infers FORCE_SSL_ADMIN from
// whatever scheme `siteurl` currently has (default-constants.php,
// since 2.6). A backup from an https:// site restored onto an
// http-only target left wp-admin force-redirecting to a scheme the
// target couldn't serve -- an effective lockout. reconcile_url_scheme()
// keeps this install's own, already-proven-working scheme on the
// restored siteurl/home value, touching nothing else about it.
// reconcile_url_scheme() is private; reached via reflection.

function invoke_reconcile_url_scheme( string $pre_restore_url, string $restored_url ): string {
	$method = new \ReflectionMethod( Restore_Orchestrator::class, 'reconcile_url_scheme' );
	$method->setAccessible( true );
	return $method->invoke( null, $pre_restore_url, $restored_url );
}

function test_same_scheme_is_a_no_op() {
	wpguard_assert_equals(
		'https://example.com',
		invoke_reconcile_url_scheme( 'https://example.com', 'https://example.com' ),
		'identical schemes must leave the restored URL completely untouched'
	);
}

function test_corrects_https_backup_onto_http_target() {
	// The exact scenario that caused the lockout: a backup from an
	// https:// site restored onto a target this install only serves
	// over plain http.
	wpguard_assert_equals(
		'http://realsite.example',
		invoke_reconcile_url_scheme( 'http://127.0.0.1:8082', 'https://realsite.example' ),
		'the pre-restore scheme must win, but the backup\'s own host must survive untouched'
	);
}

function test_preserves_https_when_backup_was_http() {
	// The reverse case: an older http:// backup restored onto a site
	// that has since moved to https -- must not downgrade it.
	wpguard_assert_equals(
		'https://realsite.example',
		invoke_reconcile_url_scheme( 'https://127.0.0.1:8082', 'http://realsite.example' ),
		'restoring an http backup onto an https-capable site must not downgrade the scheme'
	);
}

function test_preserves_host_and_path_from_restored_url() {
	wpguard_assert_equals(
		'http://new-domain.example/some/path',
		invoke_reconcile_url_scheme( 'http://127.0.0.1:8082', 'https://new-domain.example/some/path' ),
		'only the scheme is reconciled -- host and path always come from the backup, by design'
	);
}

function test_empty_pre_restore_url_is_a_no_op() {
	wpguard_assert_equals(
		'https://realsite.example',
		invoke_reconcile_url_scheme( '', 'https://realsite.example' ),
		'an unparseable/empty pre-restore URL must fail safe and leave the restored value alone'
	);
}

return array(
	'test_same_scheme_is_a_no_op',
	'test_corrects_https_backup_onto_http_target',
	'test_preserves_https_when_backup_was_http',
	'test_preserves_host_and_path_from_restored_url',
	'test_empty_pre_restore_url_is_a_no_op',
);
