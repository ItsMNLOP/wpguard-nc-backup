<?php
/**
 * Run via: wp eval-file tests/fixtures/configure-plugin.php
 * Configures the plugin exactly as an admin would through the settings
 * page, but via Settings::save() directly for repeatable, scriptable
 * E2E testing. Reads values from environment variables so no secret is
 * ever written into this file or the command line history.
 */

use WPGuard\NCBackup\Settings;

$input = array(
	'nextcloud_url'  => getenv( 'WPGUARD_TEST_NC_URL' ) ?: '',
	'nextcloud_user' => getenv( 'WPGUARD_TEST_NC_USER' ) ?: '',
	'app_password'   => getenv( 'WPGUARD_TEST_NC_APP_PASSWORD' ) ?: '',
	'remote_folder'  => getenv( 'WPGUARD_TEST_NC_FOLDER' ) ?: '',
);

$result = Settings::save( $input );
echo wp_json_encode( $result ) . "\n";
if ( ! $result['ok'] ) {
	exit( 1 );
}
