<?php
namespace WPGuard\NCBackup\Tests;

function test_no_sslverify_false_anywhere_in_plugin_source() {
	$dir = dirname( __DIR__, 2 ) . '/plugin/wpguard-nc-backup';
	$offenders = array();
	$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
	foreach ( $iterator as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}
		$contents = file_get_contents( $file->getPathname() );
		$code_only = '';
		foreach ( token_get_all( $contents ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code_only .= is_array( $token ) ? $token[1] : $token;
		}
		if ( preg_match( '/sslverify[^,;]*false/i', $code_only ) ) {
			$offenders[] = $file->getPathname();
		}
	}
	wpguard_assert( empty( $offenders ), 'sslverify=>false must never appear in plugin source: ' . implode( ', ', $offenders ) );
}

return array( 'test_no_sslverify_false_anywhere_in_plugin_source' );
