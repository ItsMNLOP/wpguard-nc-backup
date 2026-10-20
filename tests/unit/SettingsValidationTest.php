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

// Phase 2 (scheduling). The admin UI is always a <select> with a fixed
// option list, so these cover: the field is omitted entirely (an older
// stored option, or a non-UI caller), a valid choice, and tampering /
// a future form mismatch -- none of these should ever hard-fail a save,
// since scheduling is optional and must degrade to the safe default.
function test_schedule_defaults_to_disabled_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without a schedule field must still succeed' );
	wpguard_assert_equals( 'disabled', Settings::get_schedule(), 'an omitted schedule field must default to disabled' );
}

function test_accepts_valid_schedule_value() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule' => 'weekly' ) );
	wpguard_assert( true === $result['ok'], 'a valid schedule value must be accepted' );
	wpguard_assert_equals( 'weekly', Settings::get_schedule(), 'the valid schedule value must be stored and read back' );
}

function test_rejects_unrecognized_schedule_falls_back_to_disabled() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule' => 'hourly-or-something-not-offered' ) );
	wpguard_assert( true === $result['ok'], 'an unrecognized schedule value must not hard-fail the whole save' );
	wpguard_assert_equals( 'disabled', Settings::get_schedule(), 'an unrecognized schedule value must clamp to the safe default, not be stored verbatim' );
}

// Phase 2 (retention). Pruning is destructive (permanently deletes
// backups), so these cover: the field is omitted (must default to 0 =
// keep-all, never silently enable deletion), a valid positive count,
// and a negative/non-numeric value clamping to 0 rather than erroring
// or being stored as something that could underflow a count elsewhere.
function test_retention_defaults_to_zero_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without a retention_count field must still succeed' );
	wpguard_assert_equals( 0, Settings::get_retention_count(), 'an omitted retention_count must default to 0 (keep all)' );
}

function test_accepts_valid_retention_count() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'retention_count' => 14 ) );
	wpguard_assert( true === $result['ok'], 'a valid retention_count must be accepted' );
	wpguard_assert_equals( 14, Settings::get_retention_count(), 'the valid retention_count must be stored and read back' );
}

function test_rejects_negative_retention_count() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'retention_count' => -5 ) );
	wpguard_assert( true === $result['ok'], 'a negative retention_count must not hard-fail the whole save' );
	wpguard_assert_equals( 0, Settings::get_retention_count(), 'a negative retention_count must clamp to 0, not be stored as negative' );
}

// Phase 2 (incremental backups). A plain checkbox: must default to off
// (never silently start skipping files on an existing site after an
// update), accept an explicit on, and never hard-fail the save either
// way.
function test_incremental_defaults_to_disabled_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without an incremental_enabled field must still succeed' );
	wpguard_assert( false === Settings::get_incremental_enabled(), 'an omitted incremental_enabled field must default to disabled' );
}

function test_incremental_accepts_enabled() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'incremental_enabled' => '1' ) );
	wpguard_assert( true === $result['ok'], 'a truthy incremental_enabled must be accepted' );
	wpguard_assert( true === Settings::get_incremental_enabled(), 'incremental_enabled must be stored and read back as enabled' );
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

// Vex audit LOW-R1: documents the regex's known limitation (no fix
// intended here — this is exactly the gap the fail-closed check in
// Job_Orchestrator::redacted_wp_config_copy() exists to catch at
// archive time, not something to try to make this regex perfect for).
function test_redact_wp_config_known_gap_const_syntax_not_caught() {
	$content = "<?php\nconst WPGUARD_NC_APP_PASSWORD = 'leaks-through-const-syntax';\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false !== strpos( $redacted, 'leaks-through-const-syntax' ), 'documents the known gap: const syntax is not matched by the define()-shaped regex' );
}

// Defines a real PHP constant — must run last in this file, since PHP
// constants cannot be undefined and would otherwise lock every test
// after it. run-all.php sorts files alphabetically and UpdateCheckerTest.php
// now sorts after this file, but defines no constants, so the ordering
// requirement here is still satisfied in practice.
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
	'test_schedule_defaults_to_disabled_when_missing',
	'test_accepts_valid_schedule_value',
	'test_rejects_unrecognized_schedule_falls_back_to_disabled',
	'test_retention_defaults_to_zero_when_missing',
	'test_accepts_valid_retention_count',
	'test_rejects_negative_retention_count',
	'test_incremental_defaults_to_disabled_when_missing',
	'test_incremental_accepts_enabled',
	'test_redact_wp_config_strips_credential_constants',
	'test_redact_wp_config_known_gap_const_syntax_not_caught',
	'test_locked_app_password_purges_stored_wp_options_value',
);
