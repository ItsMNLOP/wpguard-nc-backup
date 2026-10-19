<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enumerates files needed for recovery (BKP-02): wp-content/** plus
 * wp-config.php and .htaccess if present. Core WordPress files
 * (wp-admin/, wp-includes/, root loader scripts) are deliberately
 * excluded — they are regenerable from a fresh WordPress download of the
 * version recorded in the manifest, and re-downloading them is far
 * cheaper than backing them up on every run.
 *
 * Symlinks are never followed (threat-model safety default; BKP-07
 * hardening is Phase 2). Each skipped symlink is recorded as a warning,
 * never silently dropped (brief: "do not silently include or exclude
 * external files; disclose backup coverage").
 */
class Inventory {

	/** @var string[] Glob-style exclusion patterns, matched against the path relative to ABSPATH. */
	const EXCLUDED_PATTERNS = array(
		'wp-content/cache/*',
		'wp-content/wpguard-nc-backup-tmp/*',
		'wp-content/wpguard-nc-backup-tmp',
	);

	/**
	 * @return array{files: array<string,string>, warnings: string[]}
	 *   files: relative_path => absolute_path
	 */
	public static function enumerate(): array {
		$abspath = rtrim( ABSPATH, '/\\' );
		$roots = array( 'wp-content' );

		$files = array();
		$warnings = array();

		foreach ( $roots as $root ) {
			$root_abs = $abspath . '/' . $root;
			if ( ! is_dir( $root_abs ) ) {
				continue;
			}
			self::walk( $root_abs, $abspath, $files, $warnings );
		}

		foreach ( array( 'wp-config.php', '.htaccess' ) as $extra ) {
			$abs = $abspath . '/' . $extra;
			if ( is_file( $abs ) && ! is_link( $abs ) ) {
				$files[ $extra ] = $abs;
			}
		}

		return array( 'files' => $files, 'warnings' => $warnings );
	}

	private static function walk( string $dir, string $abspath, array &$files, array &$warnings ): void {
		$iterator = new \DirectoryIterator( $dir );
		foreach ( $iterator as $item ) {
			if ( $item->isDot() ) {
				continue;
			}
			$abs = $item->getPathname();
			$relative = ltrim( substr( $abs, strlen( $abspath ) ), '/\\' );
			$relative = str_replace( '\\', '/', $relative );

			if ( self::is_excluded( $relative ) ) {
				continue;
			}

			if ( is_link( $abs ) ) {
				$warnings[] = "Symlink excluded (not followed): {$relative}";
				continue;
			}

			if ( $item->isDir() ) {
				self::walk( $abs, $abspath, $files, $warnings );
				continue;
			}

			if ( $item->isFile() ) {
				$files[ $relative ] = $abs;
			}
		}
	}

	private static function is_excluded( string $relative ): bool {
		foreach ( self::EXCLUDED_PATTERNS as $pattern ) {
			if ( fnmatch( $pattern, $relative ) ) {
				return true;
			}
		}
		return false;
	}
}
