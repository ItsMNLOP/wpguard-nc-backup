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

// Vex audit H-02 regression coverage. redact_wp_config_contents() is a
// pure string transform (no WP functions), safe to test directly.
function test_redact_wp_config_strips_credential_constants() {
	$content = "<?php\n"
		. "define('WP_DEBUG', false);\n"
		. "define('WPGUARD_NC_APP_PASSWORD', 'super-secret-app-pw');\n"
		. "define('WPGUARD_NC_CA_BUNDLE', '/path/to/ca.crt');\n"
		. "define('DB_NAME', 'wordpress');\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false === strpos( $redacted, 'super-secret-app-pw' ), 'app-password constant value must not survive redaction' );
	wpguard_assert( false === strpos( $redacted, '/path/to/ca.crt' ), 'ca-bundle constant value must not survive redaction' );
	wpguard_assert( false !== strpos( $redacted, "define('WP_DEBUG', false);" ), 'unrelated defines must be preserved verbatim' );
	wpguard_assert( false !== strpos( $redacted, "define('DB_NAME', 'wordpress');" ), 'unrelated defines must be preserved verbatim' );
}

// Defines a real PHP constant — must run last in this file (and this
// file is last alphabetically among *Test.php), since PHP constants
// cannot be undefined and would otherwise lock every test after it.
function test_locked_app_password_purges_stored_wp_options_value() {
	$GLOBALS['__wpguard_test_options'] = array(
		Settings::OPTION_KEY => array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'old-option-stored-secret', 'ca_bundle_path' => '' ),
	);
	define( 'WPGUARD_NC_APP_PASSWORD', 'constant-sourced-secret' );
	wpguard_assert( Settings::is_app_password_locked(), 'defining the constant must lock the app password' );
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups' ) );
	wpguard_assert( true === $result['ok'], 'save must still succeed while the password is constant-locked' );
	wpguard_assert_equals( 'constant-sourced-secret', Settings::get_app_password(), 'get_app_password() must read the constant while locked' );
	$stored = Settings::get_all();
	wpguard_assert_equals( '', $stored['app_password'], 'the stale wp_options value must be purged once the constant takes over (H-02)' );
}

return array(
	'test_rejects_plain_http_url',
	'test_accepts_https_url',
	'test_rejects_folder_traversal',
	'test_blank_app_password_keeps_existing_value',
	'test_rejects_missing_username',
	'test_redact_wp_config_strips_credential_constants',
	'test_locked_app_password_purges_stored_wp_options_value',
);
