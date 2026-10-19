<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keyset-paginated SQL exporter. ADR 0005: no OFFSET pagination, bounded
 * memory per batch, honest "best-effort-live-snapshot" labeling, hex
 * encoding for binary columns.
 */
class DB_Exporter {

	const BATCH_SIZE = 500;

	/** @return string[] All tables under the current $wpdb prefix (not just core tables — BKP-01). */
	public static function list_tables(): array {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Detects a usable single-column integer/string unique key to order by.
	 * Falls back to null (caller must use unordered-full-scan).
	 */
	private static function detect_keyset_column( string $table ): ?string {
		global $wpdb;
		$table_esc = str_replace( '`', '', $table );
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table_esc}` WHERE Key_name = 'PRIMARY'" );
		if ( is_array( $keys ) && 1 === count( $keys ) ) {
			return $keys[0]->Column_name;
		}
		return null;
	}

	private static function column_types( string $table ): array {
		global $wpdb;
		$table_esc = str_replace( '`', '', $table );
		$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_esc}`" );
		$types = array();
		foreach ( (array) $cols as $col ) {
			$types[ $col->Field ] = $col->Type;
		}
		return $types;
	}

	private static function is_binary_type( string $type ): bool {
		return (bool) preg_match( '/blob|binary|varbinary/i', $type );
	}

	/**
	 * Exports one table fully (CREATE TABLE + batched keyset-ordered
	 * INSERTs) to an open file handle. Bounded memory via BATCH_SIZE
	 * regardless of table size.
	 *
	 * @return array{row_count:int, ordering_strategy:string, primary_key:?string, warning:?string}
	 */
	public static function export_table( $fh, string $table ): array {
		global $wpdb;
		$table_esc = str_replace( '`', '', $table );

		fwrite( $fh, "\n-- Table: {$table_esc}\n" );
		fwrite( $fh, "DROP TABLE IF EXISTS `{$table_esc}`;\n" );
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table_esc}`", ARRAY_N );
		fwrite( $fh, $create[1] . ";\n" );

		$types = self::column_types( $table_esc );
		$columns = array_keys( $types );
		$column_list = '`' . implode( '`, `', $columns ) . '`';

		$pk = self::detect_keyset_column( $table_esc );
		$row_count = 0;
		$warning = null;

		// Threat-model T9: never export the plugin's own credential-bearing
		// option row. Row-level exclusion, not whole-table exclusion — the
		// options table holds everything else WordPress needs restored.
		$exclude_sql = '';
		if ( $table_esc === $wpdb->prefix . 'options' ) {
			$exclude_sql = $wpdb->prepare( ' AND option_name != %s', Settings::OPTION_KEY );
		}

		if ( null !== $pk ) {
			$last = null;
			while ( true ) {
				$sql = null === $last
					? "SELECT * FROM `{$table_esc}` WHERE 1=1{$exclude_sql} ORDER BY `{$pk}` ASC LIMIT " . self::BATCH_SIZE
					: $wpdb->prepare( "SELECT * FROM `{$table_esc}` WHERE `{$pk}` > %s{$exclude_sql} ORDER BY `{$pk}` ASC LIMIT " . self::BATCH_SIZE, $last );
				$rows = $wpdb->get_results( $sql, ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}
				self::write_inserts( $fh, $table_esc, $column_list, $columns, $types, $rows );
				$row_count += count( $rows );
				$last = $rows[ count( $rows ) - 1 ][ $pk ];
				if ( count( $rows ) < self::BATCH_SIZE ) {
					break;
				}
			}
			$ordering = 'keyset-primary-key';
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM `{$table_esc}` WHERE 1=1{$exclude_sql}", ARRAY_A );
			if ( ! empty( $rows ) ) {
				self::write_inserts( $fh, $table_esc, $column_list, $columns, $types, $rows );
				$row_count = count( $rows );
			}
			$ordering = 'unordered-full-scan';
			$warning = "Table {$table_esc} has no single-column primary key; exported as one unordered full scan.";
		}

		return array(
			'row_count'         => $row_count,
			'ordering_strategy' => $ordering,
			'primary_key'       => $pk,
			'warning'           => $warning,
		);
	}

	/**
	 * Neither $wpdb->prepare('%s', $val) NOR esc_sql($val) alone is safe
	 * here: both internally call wpdb::add_placeholder_escape(), which
	 * replaces literal '%' in the value with a one-time '{salt}' token
	 * so a LATER prepare() call can restore it after doing its own
	 * sprintf-style substitution. We never make that later call — we
	 * write the escaped string straight to a SQL file as a final
	 * literal — so the token was leaking into stored data verbatim
	 * (found live, during E2E restore testing, via a post slug with
	 * percent-encoded emoji bytes: "...%f0%9f..." came back as
	 * "...{64-hex-chars}f0{64-hex-chars}9f...", which then overflowed
	 * the post_name column on import). remove_placeholder_escape()
	 * undoes exactly that wrapping, restoring the real '%' characters,
	 * giving a value safe to use as a standalone terminal SQL literal.
	 */
	private static function write_inserts( $fh, string $table, string $column_list, array $columns, array $types, array $rows ): void {
		global $wpdb;
		$value_groups = array();
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $col ) {
				$val = $row[ $col ];
				if ( null === $val ) {
					$values[] = 'NULL';
				} elseif ( self::is_binary_type( $types[ $col ] ) ) {
					$values[] = "0x" . bin2hex( $val );
				} else {
					$values[] = "'" . $wpdb->remove_placeholder_escape( esc_sql( $val ) ) . "'";
				}
			}
			$value_groups[] = '(' . implode( ', ', $values ) . ')';
		}
		fwrite( $fh, "INSERT INTO `{$table}` ({$column_list}) VALUES\n" . implode( ",\n", $value_groups ) . ";\n" );
	}

	public static function write_header( $fh ): void {
		fwrite( $fh, "-- wpguard-nc-backup database export\n" );
		fwrite( $fh, '-- format_version: ' . WPGUARD_NC_FORMAT_VERSION . "\n" );
		fwrite( $fh, '-- generated_at_utc: ' . gmdate( 'Y-m-d\TH:i:s\Z' ) . "\n" );
		fwrite( $fh, "SET NAMES utf8mb4;\n" );
		fwrite( $fh, "SET FOREIGN_KEY_CHECKS=0;\n" );
	}

	public static function write_footer( $fh ): void {
		fwrite( $fh, "SET FOREIGN_KEY_CHECKS=1;\n" );
	}
}
