<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Settings;

function test_rejects_plain_http_url() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'http://example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups' ) );
	wpguard_assert( false === $result['ok'], 'plain http:// must be rejected' );
	wpguard_assert( isset( $result['errors']['nextcloud_url'] ), 'error must be reported on the nextcloud_url field' );
}

function test_accepts_https_url() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'valid https:// url with required fields must be accepted' );
}

function test_rejects_folder_traversal() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => '../../etc', 'app_password' => 'pw' ) );
	wpguard_assert( false === $result['ok'], 'remote_folder containing .. must be rejected' );
}

function test_blank_app_password_keeps_existing_value() {
	$GLOBALS['__wpguard_test_options'] = array(
		Settings::OPTION_KEY => array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'existing-secret', 'ca_bundle_path' => '' ),
	);
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => '' ) );
	wpguard_assert( true === $result['ok'], 'save with blank password should succeed' );
	wpguard_assert_equals( 'existing-secret', Settings::get_app_password(), 'blank app_password submission must not clear the stored value' );
}

function test_rejects_missing_username() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => '', 'remote_folder' => 'backups' ) );
	wpguard_assert( false === $result['ok'], 'missing username must be rejected' );
}

return array(
	'test_rejects_plain_http_url',
	'test_accepts_https_url',
	'test_rejects_folder_traversal',
	'test_blank_app_password_keeps_existing_value',
	'test_rejects_missing_username',
);
