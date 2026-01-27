<?php
/**
 * Database collation utilities for Content Diff Migrator.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Utils;

use wpdb;
use Psr\Log\LogLevel;
use InvalidArgumentException;

/**
 * Database utility handling collation comparison and equalization.
 */
class DB {

	/**
	 * Core WP tables.
	 *
	 * @var array
	 */
	const CORE_WP_TABLES = [
		'commentmeta',
		'comments',
		'links',
		'options',
		'postmeta',
		'posts',
		'terms',
		'termmeta',
		'term_relationships',
		'term_taxonomy',
		'usermeta',
		'users',
	];

	/**
	 * Global $wpdb.
	 *
	 * @var wpdb Global $wpdb.
	 */
	private wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Global $wpdb.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Gets a list of all the tables in the active DB.
	 *
	 * @return array List of all tables in DB.
	 */
	public function get_all_db_tables(): array {
		$all_tables        = [];
		$all_tables_result = $this->wpdb->get_results( 'SHOW TABLES;', ARRAY_N );
		foreach ( $all_tables_result as $table ) {
			$all_tables[] = $table[0];
		}

		return $all_tables;
	}

	/**
	 * Validates that all core WP DB tables exist and have matching collations.
	 *
	 * @param string $live_table_prefix Live table prefix.
	 * @param array  $skip_tables       Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException In case tables don't exist or collations don't match.
	 */
	public function validate_db_tables( string $live_table_prefix, array $skip_tables = [] ): void {
		// Check whether core WP DB tables that exist in the installation are present in used DB.
		$all_tables       = $this->get_all_db_tables();
		$tables_validated = 0;
		
		foreach ( self::CORE_WP_TABLES as $table ) {
			if ( in_array( $table, $skip_tables ) ) {
				continue;
			}
			
			// Check if this core table exists in this WordPress installation.
			$core_tablename = $this->wpdb->prefix . $table;
			$core_exists    = in_array( $core_tablename, $all_tables );
			if ( ! $core_exists ) {
				// Core table doesn't exist in this WP installation (e.g., wp_links in newer WP), skip it.
				continue;
			}
			
			$live_tablename = $live_table_prefix . $table;
			if ( ! in_array( $live_tablename, $all_tables ) ) {
				throw new \RuntimeException( sprintf( 'Core WP DB table %s not found.', $live_tablename ) ); // phpcs:ignore -- exception message is for internal logging/debugging WordPress.Security.EscapeOutput.ExceptionNotEscaped.
			}
			$tables_validated++;
		}
		
		if ( 0 === $tables_validated ) {
			throw new \RuntimeException( 'No core WP DB tables found to validate.' );
		}

		if ( ! $this->are_table_collations_matching( $live_table_prefix, $skip_tables ) ) {
			throw new \RuntimeException( 'Table collations do not match for some (or all) WP tables.' );
		}
	}

	/**
	 * This function will compare Core WP Tables against the Live WP tables
	 * brought in for a content migration/refresh. This will be
	 * useful for determining whether a collation
	 * migration is necessary.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return array
	 */
	public function get_collation_comparison_of_live_and_core_wp_tables( string $table_prefix, array $skip_tables = [] ): array {
		$validated_tables = [];

		$core_tables = array_diff( self::CORE_WP_TABLES, $skip_tables );
		foreach ( $core_tables as $table ) {
			// Prepare and validate table names.
			$core_table = $this->wpdb->prefix . $table;
			$live_table = $table_prefix . $table;
			self::validate_table_name( $core_table );
			self::validate_table_name( $live_table );

			$core_table_status = $this->wpdb->get_row( "SHOW TABLE STATUS WHERE name LIKE '$core_table'" ); // phpcs:ignore -- query fully sanitized.
			$live_table_status = $this->wpdb->get_row( "SHOW TABLE STATUS WHERE name LIKE '$live_table'" ); // phpcs:ignore -- query fully sanitized.

			if ( is_null( $core_table_status ) ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Core table `%s` does not exist, skipping table.', $core_table ) );
				continue;
			}

			if ( is_null( $live_table_status ) ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Live table `%s` does not exist, skipping table.', $live_table ) );
				continue;
			}

		// phpcs:ignore -- ignore CamelCase param.
		$match_test = $live_table_status->Collation === $core_table_status->Collation;

			$validated_tables[] = [
				'table'                => $table,
				'core_table_name'      => $core_table,
				// phpcs:ignore -- ignore CamelCase param.
				'core_table_collation' => $core_table_status->Collation,
				'live_table_name'      => $live_table,
				// phpcs:ignore -- ignore CamelCase param.
				'live_table_collation' => $live_table_status->Collation,
				'match'                => $match_test ? 'YES' : 'NO',
				'match_bool'           => $match_test,
			];
		}

		// It's OK if validated_tables is empty due to tables not existing in this WP installation.
		// The validate_db_tables() method will catch if NO tables could be validated.
		return $validated_tables;
	}

	/**
	 * Convenience function that only returns tables which have a different collation
	 * than the Core WP DB tables.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return array
	 */
	public function filter_for_different_collated_tables( string $table_prefix, array $skip_tables = [] ): array {
		$collation_comparison = $this->get_collation_comparison_of_live_and_core_wp_tables( $table_prefix, $skip_tables );

		return array_values(
			array_filter(
				$collation_comparison,
				fn( $validated_table ) => false === $validated_table['match_bool']
			)
		);
	}

	/**
	 * Convenience function which returns a simple boolean value indicating whether all Live
	 * DB tables have matching collations with their corresponding Core WP DB tables.
	 *
	 * @param string $live_table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return bool
	 */
	public function are_table_collations_matching( string $live_table_prefix, array $skip_tables = [] ): bool {
		return empty( $this->filter_for_different_collated_tables( $live_table_prefix, $skip_tables ) );
	}

	/**
	 * Calculates the total size (data + index) in bytes for the given tables.
	 *
	 * @param array $table_names Full table names (with prefix).
	 *
	 * @return int Total size in bytes.
	 */
	public function get_total_table_size_bytes( array $table_names ): int {
		if ( empty( $table_names ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $table_names ), '%s' ) );
		$db_name      = DB_NAME;
		// phpcs:disable -- WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$query = $this->wpdb->prepare(
			"SELECT SUM(DATA_LENGTH + INDEX_LENGTH) as total_bytes
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ($placeholders)",
			array_merge( [ $db_name ], $table_names )
		);
		$result = $this->wpdb->get_var( $query );
		// phpcs:enable

		return (int) ( $result ?? 0 );
	}

	/**
	 * This function will handle the operation to move data from the
	 * incompatibly collated table to the new compatible table.
	 *
	 * Speed settings are auto-determined based on total size of tables being fixed:
	 * - Small datasets (< 4GB): 250K records/batch, no sleep between batches
	 * - Large datasets (>= 4GB): 100K records/batch, 5s sleep between batches
	 *
	 * @param string $prefix           Live table prefix.
	 * @param string $table            The Core WP Table to address.
	 * @param int    $total_size_bytes Total size of all tables being fixed (for speed determination).
	 *
	 * @throws \RuntimeException Throws various exceptions if unable to complete required SQL operations.
	 */
	public function copy_table_data_using_proper_collation( string $prefix, string $table, int $total_size_bytes = 0 ): void {
		// Auto-determine speed based on total size of tables being fixed.
		$four_gb_in_bytes = 4 * 1024 * 1024 * 1024;
		$is_small_dataset = $total_size_bytes < $four_gb_in_bytes;

		// Speed settings: small datasets get faster batching, large datasets get throttled.
		$records_per_transaction = $is_small_dataset ? 250000 : 100000;
		$sleep_between_batches   = $is_small_dataset ? 0 : 5;
		$sleep_after_table       = 10;

		// Prepare and validate all table names.
		$backup_prefix             = 'collationbak_';
		$backup_table              = $backup_prefix . $prefix . $table;
		$source_table              = $prefix . $table;
		$match_collation_for_table = $this->wpdb->prefix . $table;
		self::validate_table_name( $backup_table );
		self::validate_table_name( $source_table );
		self::validate_table_name( $match_collation_for_table );
		
		$rename_sql    = "RENAME TABLE $source_table TO $backup_table";
		$rename_result = $this->wpdb->query( $rename_sql ); // phpcs:ignore -- table names were properly validated.
		if ( false === $rename_result ) {
			throw new \RuntimeException( sprintf( "Unable to rename table: '%s', DB error: %s", $rename_sql, ( '' != $this->wpdb->last_error ) ? $this->wpdb->last_error : 'unknown error' ) ); // phpcs:ignore -- exception message is for internal logging/debugging WordPress.Security.EscapeOutput.ExceptionNotEscaped.
		}

		$create_like_table_sql = "CREATE TABLE {$source_table} LIKE $match_collation_for_table";
		// phpcs:ignore -- query fully sanitized.
	$create_result = $this->wpdb->query( $create_like_table_sql );

		if ( false === $create_result ) {
			throw new \RuntimeException( sprintf( "Unable to create table: '%s', DB error: %s", $create_like_table_sql, ( '' != $this->wpdb->last_error ) ? $this->wpdb->last_error : 'unknown error' ) ); // phpcs:ignore -- exception message is for internal logging/debugging WordPress.Security.EscapeOutput.ExceptionNotEscaped.
		}

		$limiter = [
			'start' => 0,
			'limit' => $records_per_transaction,
		];

		$table_columns_sql = "SHOW COLUMNS FROM $source_table";
		// phpcs:ignore -- query fully sanitized.
		$table_columns_results = $this->wpdb->get_results( $table_columns_sql );
		$table_columns         = implode( ',', array_map( fn( $column_row ) => "`$column_row->Field`", $table_columns_results ) );
		// phpcs:ignore -- query fully sanitized.
		$count = $this->wpdb->get_row( "SELECT COUNT(*) as counter FROM $backup_table;" );

		// Handle empty tables - just delete backup and return.
		if ( empty( $count ) || 0 === (int) $count->counter ) {
			// phpcs:ignore -- query fully sanitized.
			$this->wpdb->query( "DROP TABLE IF EXISTS $backup_table" );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( "Table '%s' has 0 rows, backup deleted.", $backup_table ) );
			return;
		}

		$iterations = ceil( $count->counter / $limiter['limit'] );
		for ( $i = 1; $i <= $iterations; $i++ ) {
			$insert_sql = "INSERT INTO `{$source_table}`({$table_columns}) SELECT {$table_columns} FROM {$backup_table} LIMIT {$limiter['start']}, {$limiter['limit']}";
			// phpcs:ignore -- query fully sanitized.
			$insert_result = $this->wpdb->query( $insert_sql );

			if ( ( false !== $insert_result ) && ( 0 !== $insert_result ) ) {
				$limiter['start'] = $limiter['start'] + $limiter['limit'];
			} else {
				$db_error = ( '' != $this->wpdb->last_error ) ? 'DB error message: ' . $this->wpdb->last_error : 'No DB error message available -- check error and debug logs.';
				// CRITICAL: Batch insert failed. Abort operation and keep backup table intact.
				throw new \RuntimeException( sprintf( "Failed to copy data at offset %d. SQL: '%s'. %s. Backup table '%s' preserved for recovery.", $limiter['start'], $insert_sql, $db_error, $backup_table ) ); // phpcs:ignore -- exception message is for internal logging/debugging WordPress.Security.EscapeOutput.ExceptionNotEscaped.
			}

			if ( $sleep_between_batches > 0 ) {
				sleep( $sleep_between_batches );
			}
		}

		// Verify row counts match before dropping backup.
		$backup_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM $backup_table" ); // phpcs:ignore -- table name was properly validated.
		$source_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM $source_table" ); // phpcs:ignore -- table name was properly validated.
		if ( $backup_count !== $source_count ) {
			throw new \RuntimeException( sprintf( "Row count mismatch after copy: backup has %d rows, source has %d rows. Backup table '%s' preserved for recovery.", $backup_count, $source_count, $backup_table ) ); // phpcs:ignore -- exception message is for internal logging/debugging WordPress.Security.EscapeOutput.ExceptionNotEscaped.
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Successfully copied %d rows from backup to source. Verified row counts match.', $source_count ) );

		// Delete backup table only after successful copy and verification.
		// phpcs:ignore -- query fully sanitized.
		$drop_result = $this->wpdb->query( "DROP TABLE IF EXISTS $backup_table" );
		if ( false === $drop_result ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( "Failed to drop backup table '%s'.", $backup_table ) );
		} else {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( "Deleted backup table '%s'.", $backup_table ) );
		}

		// Sleep after table is complete (for small datasets).
		if ( $sleep_after_table > 0 ) {
			sleep( $sleep_after_table );
		}
	}

	/**
	 * Utility to validate table names silently, or throws an exception if invalid.
	 * Used for sanitizing table names before use in SQL queries.
	 * 
	 * @param string $table_name The table name to validate.
	 * @return void
	 * @throws InvalidArgumentException If table name is invalid.
	 */
	public static function validate_table_name( string $table_name ): void {
		if ( empty( $table_name ) ) {
			throw new InvalidArgumentException( 'Table name cannot be empty.' );
		}
		if ( strlen( $table_name ) > 64 ) {
			throw new InvalidArgumentException( 'Table name exceeds maximum length of 64 characters for MySQL.' );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
			throw new InvalidArgumentException( 'Table name contains invalid characters. Only alphanumeric and underscore allowed' );
		}
	}
}
