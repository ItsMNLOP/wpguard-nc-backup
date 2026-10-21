<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 2.5 (migration on restore): serialization-safe URL rewriting
 * across every text column of every table under a given prefix.
 *
 * Checking "this backup is from a different site" on restore means
 * migration, not merely "I know, proceed anyway" -- this is what makes
 * that true. Runs against the restore's own SHADOW-prefixed tables,
 * after import but before restore_swap promotes them to live, so a
 * failure partway through still leaves the live site completely
 * untouched -- the same point-of-no-return discipline the rest of
 * restore already uses.
 *
 * A plain str_replace() across raw column bytes would corrupt any
 * PHP-serialized value (widget settings, several core options) whose
 * replacement text is a different length than the original, since a
 * serialized string carries an explicit byte-length prefix that a
 * naive replace never updates. Every text value is therefore checked
 * for being serialized first; if so, unserialized, walked recursively
 * replacing only string leaves/keys, and re-serialized (which
 * recomputes every length prefix correctly) rather than ever touching
 * the serialized bytes directly.
 *
 * Deliberately scoped to literal, scheme-qualified URL occurrences
 * (the backup's recorded home_url, and its http<->https counterpart,
 * to catch pre-TLS-migration links the backup's own content might
 * still carry) -- never a bare hostname. Replacing a bare hostname
 * would also mangle an unrelated mention of the same domain in plain
 * text (an email address, a quoted sentence), which a URL occurrence
 * never would.
 */
class DB_Migrator {

	const BATCH_SIZE = 200;
	const TEXT_DATA_TYPES = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext' );

	/** @return array{0:string,1:string}[] Search/replace pairs to apply, longest first. */
	public static function replace_pairs_for( string $old_home_url, string $new_home_url ): array {
		$old_home_url = rtrim( $old_home_url, '/' );
		$new_home_url = rtrim( $new_home_url, '/' );
		if ( '' === $old_home_url || '' === $new_home_url || $old_home_url === $new_home_url ) {
			return array();
		}
		$pairs = array( array( $old_home_url, $new_home_url ) );
		$swapped = self::swap_scheme( $old_home_url );
		if ( null !== $swapped && $swapped !== $old_home_url ) {
			$pairs[] = array( $swapped, $new_home_url );
		}
		// Longest search string first: with http/https variants of the
		// same host, neither is ever a substring of the other (they
		// differ at the very start), so order doesn't actually change
		// the result here -- kept for safety against any future variant
		// that could overlap.
		usort( $pairs, static function ( $a, $b ) {
			return strlen( $b[0] ) - strlen( $a[0] );
		} );
		return $pairs;
	}

	private static function swap_scheme( string $url ): ?string {
		if ( 0 === strpos( $url, 'https://' ) ) {
			return 'http://' . substr( $url, strlen( 'https://' ) );
		}
		if ( 0 === strpos( $url, 'http://' ) ) {
			return 'https://' . substr( $url, strlen( 'http://' ) );
		}
		return null;
	}

	/** @return array{table:string,columns:string[],pk:?string}[] Eligible tables/columns under $prefix, PK-less tables included with pk=null (caller skips those). */
	public static function discover_targets( string $prefix ): array {
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$targets = array();
		foreach ( (array) $tables as $table ) {
			$columns = $wpdb->get_col( $wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND DATA_TYPE IN (' .
				implode( ',', array_fill( 0, count( self::TEXT_DATA_TYPES ), '%s' ) ) . ')',
				array_merge( array( $table ), self::TEXT_DATA_TYPES )
			) );
			if ( empty( $columns ) ) {
				continue;
			}
			$targets[] = array(
				'table'   => $table,
				'columns' => $columns,
				'pk'      => self::detect_primary_key_column( $table ),
			);
		}
		return $targets;
	}

	private static function detect_primary_key_column( string $table ): ?string {
		global $wpdb;
		// `SHOW KEYS` returns several columns (Table, Non_unique,
		// Key_name, Seq_in_index, Column_name, ...) -- fetched by name,
		// not position, since $wpdb->get_col() with no explicit index
		// grabs column 0 (Table), not Column_name.
		$rows = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		return 1 === count( $rows ) ? $rows[0]['Column_name'] : null;
	}

	/**
	 * Processes one batch (bounded by $deadline) of a single table,
	 * starting after $after_pk (null = start of table). Returns the
	 * last PK processed in this batch (null if the table has no more
	 * rows), the row count examined, and the row count actually
	 * changed.
	 */
	public static function migrate_table_batch( string $table, array $columns, string $pk, $after_pk, array $pairs, float $deadline ): array {
		global $wpdb;
		$examined = 0;
		$changed = 0;
		$last_pk = $after_pk;
		do {
			$column_list = '`' . implode( '`, `', array_merge( array( $pk ), $columns ) ) . '`';
			if ( null === $after_pk ) {
				$rows = $wpdb->get_results( "SELECT {$column_list} FROM `{$table}` ORDER BY `{$pk}` ASC LIMIT " . self::BATCH_SIZE, ARRAY_A );
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT {$column_list} FROM `{$table}` WHERE `{$pk}` > %s ORDER BY `{$pk}` ASC LIMIT " . self::BATCH_SIZE,
					$after_pk
				), ARRAY_A );
			}
			if ( empty( $rows ) ) {
				return array( 'last_pk' => null, 'examined' => $examined, 'changed' => $changed );
			}
			foreach ( $rows as $row ) {
				$after_pk = $row[ $pk ];
				$last_pk = $after_pk;
				$examined++;
				$updates = array();
				foreach ( $columns as $column ) {
					$value = $row[ $column ];
					if ( null === $value || '' === $value ) {
						continue;
					}
					$new_value = self::replace_in_scalar_or_serialized( $value, $pairs );
					if ( $new_value !== $value ) {
						$updates[ $column ] = $new_value;
					}
				}
				if ( ! empty( $updates ) ) {
					$wpdb->update( $table, $updates, array( $pk => $row[ $pk ] ) );
					$changed++;
				}
				if ( microtime( true ) >= $deadline ) {
					return array( 'last_pk' => $last_pk, 'examined' => $examined, 'changed' => $changed );
				}
			}
		} while ( count( $rows ) === self::BATCH_SIZE && microtime( true ) < $deadline );
		return array( 'last_pk' => $last_pk, 'examined' => $examined, 'changed' => $changed );
	}

	/** @param array{0:string,1:string}[] $pairs */
	public static function replace_in_scalar_or_serialized( string $value, array $pairs ): string {
		if ( empty( $pairs ) ) {
			return $value;
		}
		if ( self::is_serialized_value( $value ) ) {
			// allowed_classes => false: found live, restoring a real
			// backup with Action Scheduler data (used by WooCommerce and
			// many other plugins) -- an object whose class isn't loaded
			// at this point in the restore unserializes as a safe
			// __PHP_Incomplete_Class stand-in regardless, so this changes
			// nothing about what unserialize() already does here by
			// default; it just makes that the guaranteed behavior for
			// every object, rather than depending on which classes
			// happen to be autoloaded at this exact moment, and avoids
			// ever running a real class's __wakeup().
			$data = @unserialize( $value, array( 'allowed_classes' => false ) );
			if ( false !== $data || 'b:0;' === $value ) {
				return serialize( self::replace_recursive( $data, $pairs ) );
			}
			// Looked serialized by shape but failed to actually
			// unserialize (corrupt/legacy value) -- fall through to a
			// plain string replace rather than risk silently dropping
			// or further corrupting it.
		}
		return self::str_replace_pairs( $value, $pairs );
	}

	private static function str_replace_pairs( string $value, array $pairs ): string {
		foreach ( $pairs as $pair ) {
			$value = str_replace( $pair[0], $pair[1], $value );
		}
		return $value;
	}

	/** @param array{0:string,1:string}[] $pairs */
	private static function replace_recursive( $data, array $pairs ) {
		if ( is_string( $data ) ) {
			return self::str_replace_pairs( $data, $pairs );
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$new_key = is_string( $key ) ? self::str_replace_pairs( $key, $pairs ) : $key;
				$out[ $new_key ] = self::replace_recursive( $value, $pairs );
			}
			return $out;
		}
		if ( $data instanceof \__PHP_Incomplete_Class ) {
			// Found live: an object whose class isn't loaded at this
			// point in the restore (e.g. Action Scheduler's own
			// schedule objects) unserializes safely into this stand-in,
			// but PHP explicitly forbids writing a property on one --
			// doing so is a fatal error, not a catchable exception.
			// Left completely unchanged rather than crashing the whole
			// migration over one object WordPress itself can't fully
			// reconstruct in this context either.
			return $data;
		}
		if ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = self::replace_recursive( $value, $pairs );
			}
			return $data;
		}
		// int/float/bool/null -- never a URL, left untouched.
		return $data;
	}

	/** Mirrors WordPress core's own is_serialized() shape check -- reimplemented here so this stays unit-testable against the project's lightweight shim rather than depending on a full WP bootstrap. */
	public static function is_serialized_value( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}
		if ( 'N;' === $value ) {
			return true;
		}
		if ( strlen( $value ) < 4 ) {
			return false;
		}
		if ( ':' !== $value[1] ) {
			return false;
		}
		$last_char = substr( $value, -1 );
		if ( ';' !== $last_char && '}' !== $last_char ) {
			return false;
		}
		switch ( $value[0] ) {
			case 's':
				// Unlike every other type, a serialized string's own
				// closing quote sits one character BEFORE the trailing
				// ';' already checked above -- not the last character.
				return '"' === substr( $value, -2, 1 );
			case 'a':
			case 'O':
			case 'b':
			case 'i':
			case 'd':
				return true;
		}
		return false;
	}
}
