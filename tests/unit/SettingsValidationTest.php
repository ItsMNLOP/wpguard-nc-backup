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

// Phase 3 (multi-backend): wording/doc-link hint only (Nextcloud_Client
// itself has no backend branching -- see its docblock) -- but still a
// fixed-choice <select>, same clamp-not-fail tolerance as schedule.
function test_backend_defaults_to_nextcloud_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without a backend field must still succeed' );
	wpguard_assert_equals( 'nextcloud', Settings::get_backend(), 'an omitted backend field must default to nextcloud' );
}

function test_backend_accepts_owncloud_and_opencloud() {
	foreach ( array( 'owncloud', 'opencloud' ) as $backend ) {
		$GLOBALS['__wpguard_test_options'] = array();
		$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'backend' => $backend ) );
		wpguard_assert( true === $result['ok'], "save with backend={$backend} must succeed" );
		wpguard_assert_equals( $backend, Settings::get_backend(), "backend={$backend} must be stored and read back unchanged" );
	}
}

function test_backend_rejects_unrecognized_value_without_failing_save() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'backend' => 'dropbox' ) );
	wpguard_assert( true === $result['ok'], 'an unrecognized backend must not hard-fail the whole save' );
	wpguard_assert_equals( 'nextcloud', Settings::get_backend(), 'an unrecognized backend must clamp to the safe default, not be stored as-is' );
}

// security audit finding H-02 regression coverage. redact_wp_config_contents() is a
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

// security audit finding LOW-R1: documents the regex's known limitation (no fix
// intended here — the fail-closed check in
// Job_Orchestrator::redacted_wp_config_copy() catches THIS shape at
// archive time, since the literal value string is still present
// verbatim in the source text for strpos() to find. Nuance from the
// security re-verification, 2026-10-21 (raised alongside NEW-MEDIUM-1): that
// backstop does NOT cover every unusual define() shape equally — a
// concatenated value (`define('DB_PASSWORD', 'abc' . 'def');`) defeats
// BOTH the regex AND the fail-closed check, since the runtime-joined
// value never appears as one contiguous substring in the source text
// either. Accepted as the same class of gap as this one (no fix
// intended) on the same basis: no real wp-config.php from any
// mainstream WordPress installer or hosting panel has ever been
// observed defining a credential constant via string concatenation.
function test_redact_wp_config_known_gap_const_syntax_not_caught() {
	$content = "<?php\nconst WPGUARD_NC_APP_PASSWORD = 'leaks-through-const-syntax';\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false !== strpos( $redacted, 'leaks-through-const-syntax' ), 'documents the known gap: const syntax is not matched by the define()-shaped regex' );
}

// security audit finding NEW-MEDIUM-1 (re-verification, 2026-10-21): proven live -- the
// previous .*? pattern was non-greedy and stopped at the FIRST ');'
// anywhere in the value, so a credential containing its own literal
// ');' left everything after that point unredacted AND defeated the
// fail-closed strpos() re-check downstream (which looks for the FULL
// secret -- no longer present once part of it was consumed by the
// partial match). Covers both quote styles since the fix is shared by
// redact_wp_config_contents() and redact_wp_config_db_credentials().
function test_redact_wp_config_survives_value_containing_close_paren_semicolon() {
	$content = "<?php\ndefine('WPGUARD_NC_APP_PASSWORD', 'first);remaining');\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false === strpos( $redacted, 'remaining' ), 'a value containing its own ");" must not leave a fragment unredacted' );
	wpguard_assert( false === strpos( $redacted, 'first' ), 'the whole value must be gone, not just the tail after the embedded ");"' );
}

function test_redact_wp_config_survives_double_quoted_value_containing_close_paren_semicolon() {
	$content = '<?php' . "\n" . 'define("WPGUARD_NC_APP_PASSWORD", "first);remaining");' . "\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false === strpos( $redacted, 'remaining' ), 'a double-quoted value containing its own ");" must not leave a fragment unredacted' );
}

// Phase 3, security audit finding MEDIUM-DL-1: proven live against a real wp-config.php
// that this redaction did not previously exist at all -- a downloadable
// bundle's wp-config.php leaked the real DB_PASSWORD unchanged.
function test_redact_wp_config_db_credentials_strips_db_constants() {
	$content = "<?php\n"
		. "define('WP_DEBUG', false);\n"
		. "define('DB_NAME', 'wordpress');\n"
		. "define('DB_USER', 'wp_user');\n"
		. "define('DB_PASSWORD', 'real-db-secret');\n"
		. "define('DB_HOST', 'db.internal.example.com');\n"
		. "define('AUTH_KEY', 'leave-me-alone-auth-key');\n";
	$redacted = Settings::redact_wp_config_db_credentials( $content );
	wpguard_assert( false === strpos( $redacted, 'wordpress' ), 'DB_NAME value must not survive' );
	wpguard_assert( false === strpos( $redacted, 'wp_user' ), 'DB_USER value must not survive' );
	wpguard_assert( false === strpos( $redacted, 'real-db-secret' ), 'DB_PASSWORD value must not survive' );
	wpguard_assert( false === strpos( $redacted, 'db.internal.example.com' ), 'DB_HOST value must not survive' );
	wpguard_assert( false !== strpos( $redacted, "define('WP_DEBUG', false);" ), 'unrelated defines must be preserved verbatim' );
	wpguard_assert( false !== strpos( $redacted, 'leave-me-alone-auth-key' ), 'AUTH_KEY must be deliberately left untouched -- 2FA/encryption plugins depend on it surviving a same-host restore' );
}

// security audit finding NEW-MEDIUM-1 (re-verification, 2026-10-21): the exact proof
// case from the audit -- a DB_PASSWORD containing its own literal ");"
// (plausible: some hosting panels generate passwords from the full
// printable-ASCII set) bypassed redaction AND defeated the fail-closed
// strpos() re-check in Job_Orchestrator::redacted_wp_config_copy().
function test_redact_wp_config_db_credentials_survives_password_containing_close_paren_semicolon() {
	$content = "<?php\ndefine('DB_PASSWORD', 'first);remaining');\n";
	$redacted = Settings::redact_wp_config_db_credentials( $content );
	wpguard_assert( false === strpos( $redacted, 'remaining' ), 'a DB_PASSWORD value containing its own ");" must not leave a fragment unredacted' );
	wpguard_assert( false === strpos( $redacted, 'first' ), 'the whole value must be gone, not just the tail' );
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
	'test_backend_defaults_to_nextcloud_when_missing',
	'test_backend_accepts_owncloud_and_opencloud',
	'test_backend_rejects_unrecognized_value_without_failing_save',
	'test_redact_wp_config_strips_credential_constants',
	'test_redact_wp_config_known_gap_const_syntax_not_caught',
	'test_redact_wp_config_survives_value_containing_close_paren_semicolon',
	'test_redact_wp_config_survives_double_quoted_value_containing_close_paren_semicolon',
	'test_redact_wp_config_db_credentials_strips_db_constants',
	'test_redact_wp_config_db_credentials_survives_password_containing_close_paren_semicolon',
	'test_locked_app_password_purges_stored_wp_options_value',
);
