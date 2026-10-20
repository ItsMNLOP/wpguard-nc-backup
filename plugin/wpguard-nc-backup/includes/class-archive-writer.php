<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ZipArchive wrapper. Each call opens the on-disk part, makes one change,
 * and closes it again — jobs advance one request (AJAX/CLI call) at a
 * time, so no handle is held open across requests (ADR 0003).
 *
 * Enforces path-safety at write time (threat-model T6): refuses to add an
 * entry whose name is absolute or contains a ".." segment, independent of
 * whatever extraction-time protections a future restore tool might add.
 */
class Archive_Writer {

	public static function is_path_safe( string $relative_name ): bool {
		if ( '' === $relative_name ) {
			return false;
		}
		if ( '/' === $relative_name[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $relative_name ) ) {
			return false;
		}
		$parts = preg_split( '#[\\\\/]+#', $relative_name );
		foreach ( $parts as $part ) {
			if ( '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Opens (creating if needed) a ZIP part for writing multiple files
	 * across one batch. Found live (2026-10-19): a real backup with 8,559
	 * small files took ~17 minutes for only ~50MB of content because the
	 * previous add_file() opened and closed the archive -- rewriting its
	 * whole central directory -- on every single file, which gets slower
	 * as the directory grows. Caller now opens once per batch, calls
	 * add_file_to() per file, and closes once (close_part()) before
	 * returning to the AJAX/cron caller, same as ADR 0003 intends (no
	 * handle held open *across* separate requests -- just within one).
	 *
	 * @return \ZipArchive|\WP_Error
	 */
	public static function open_part( string $zip_path ) {
		$zip = new \ZipArchive();
		$flags = file_exists( $zip_path ) ? 0 : \ZipArchive::CREATE;
		$open = $zip->open( $zip_path, $flags ?: \ZipArchive::CREATE );
		if ( true !== $open ) {
			return new \WP_Error( 'wpguard_nc_zip_open_failed', "Could not open archive part (code {$open})." );
		}
		return $zip;
	}

	/** @return true|\WP_Error */
	public static function add_file_to( \ZipArchive $zip, string $abs_source, string $relative_name ) {
		if ( ! self::is_path_safe( $relative_name ) ) {
			return new \WP_Error( 'wpguard_nc_unsafe_path', "Refusing to archive unsafe path: {$relative_name}" );
		}
		if ( ! $zip->addFile( $abs_source, $relative_name ) ) {
			return new \WP_Error( 'wpguard_nc_zip_add_failed', "Could not add file to archive: {$relative_name}" );
		}
		return true;
	}

	public static function close_part( \ZipArchive $zip ): void {
		$zip->close();
	}

	/** @return array{bytes:int, sha256:string} */
	public static function finalize_part( string $zip_path ): array {
		return array(
			'bytes'  => filesize( $zip_path ),
			'sha256' => hash_file( 'sha256', $zip_path ),
		);
	}
}
