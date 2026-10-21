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

	/**
	 * Extensions that are already near-entropy (compressed binary formats)
	 * -- DEFLATE on these spends real CPU for close to zero size benefit.
	 * Text-ish assets (css/js/svg/json/php/etc.) are deliberately left out:
	 * they're small, compress fast, and actually shrink.
	 */
	const STORE_ONLY_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'bmp',
		'mp4', 'mov', 'avi', 'webm', 'mkv',
		'mp3', 'ogg', 'oga', 'wav', 'flac',
		'zip', 'gz', 'bz2', 'rar', '7z',
		'pdf',
		'woff', 'woff2',
	);

	public static function is_path_safe( string $relative_name ): bool {
		if ( '' === $relative_name ) {
			return false;
		}
		if ( '/' === $relative_name[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $relative_name ) ) {
			return false;
		}
		// security audit finding HIGH-PATH-1 (one-click restore): a bare '..' segment
		// was the only thing rejected. A '.' (single-dot) segment or an
		// empty segment from a doubled separator ('wp-content//plugins/...')
		// both resolve as a no-op at the filesystem layer, but neither
		// matched the literal string comparisons that gate restore's
		// own never-touch exclusions (Restore_Orchestrator::is_never_restored_path()),
		// letting a crafted manifest/upload path (e.g. './wp-config.php')
		// slip past a check meant to protect exactly that file. Rejecting
		// both here, at the one shared choke point every archive-write
		// AND every restore-extraction path already calls, closes this
		// for every caller at once rather than patching each exclusion
		// check individually.
		if ( false !== strpos( $relative_name, "\0" ) ) {
			return false;
		}
		$parts = preg_split( '#[\\\\/]+#', $relative_name );
		foreach ( $parts as $part ) {
			if ( '..' === $part || '.' === $part || '' === $part ) {
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
		// security audit finding LOW-ARCH-1: `0 ?: ZipArchive::CREATE` always evaluates
		// to CREATE (0 is falsy), so the file_exists() ternary never
		// actually influenced the flag passed to open() -- harmless in
		// practice (ZipArchive::open() with CREATE on an existing file
		// opens it for appending, not overwriting, so behavior was
		// correct by accident) but misleading to read. Written directly.
		$open = $zip->open( $zip_path, file_exists( $zip_path ) ? 0 : \ZipArchive::CREATE );
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
		$ext = strtolower( pathinfo( $relative_name, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::STORE_ONLY_EXTENSIONS, true ) ) {
			// Best-effort: if this fails, the file is still archived
			// correctly, just DEFLATE-compressed instead of stored --
			// never a correctness issue, only a missed CPU saving.
			$zip->setCompressionName( $relative_name, \ZipArchive::CM_STORE );
		}
		return true;
	}

	/**
	 * security audit finding HIGH-ZIP-1: ZipArchive::close()'s return value was
	 * previously discarded entirely. libzip rolls back the ENTIRE
	 * pending batch -- every addFile() since the last successful
	 * close(), not just the one that triggered the failure -- if any
	 * added file's source disappears or becomes unreadable before
	 * close() runs (confirmed live: a file deleted between addFile()
	 * and close() causes close() to return false and the archive to
	 * end up with zero of the batch's entries, even ones whose source
	 * was never touched). The caller must know this failed -- a
	 * checkpoint that already believes those files are safely archived
	 * would otherwise let the job reach 'verified' with files silently
	 * missing from the uploaded part.
	 *
	 * @return true|\WP_Error
	 */
	public static function close_part( \ZipArchive $zip ) {
		if ( ! $zip->close() ) {
			return new \WP_Error(
				'wpguard_nc_zip_close_failed',
				'Archive part failed to finalize -- a source file likely disappeared or became unreadable mid-batch. The affected files will be re-archived on the next backup attempt.'
			);
		}
		return true;
	}

	/** @return array{bytes:int, sha256:string} */
	public static function finalize_part( string $zip_path ): array {
		return array(
			'bytes'  => filesize( $zip_path ),
			'sha256' => hash_file( 'sha256', $zip_path ),
		);
	}
}
