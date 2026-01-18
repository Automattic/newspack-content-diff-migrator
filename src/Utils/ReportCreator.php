<?php
/**
 * Creates CSV reports from run-state JSONL files.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Utils;

use Newspack\ContentDiffMigrator\Logic\RunState;
use Psr\Log\LogLevel;

/**
 * Generates human-friendly CSV reports from migration run-state data.
 *
 * Reports are generated at the end of migration and are essentially duplicates
 * of the run-state data in a more accessible format.
 */
class ReportCreator {

	/**
	 * Report filenames.
	 */
	public const REPORT_POSTS = 'posts.csv';
	public const REPORT_USERS = 'users.csv';
	public const REPORT_TERMS = 'terms.csv';

	/**
	 * RunState instance for reading JSONL data.
	 *
	 * @var RunState
	 */
	private RunState $run_state;

	/**
	 * Constructor.
	 *
	 * @param RunState $run_state RunState instance for reading JSONL data.
	 */
	public function __construct( RunState $run_state ) {
		$this->run_state = $run_state;
	}

	/**
	 * Creates all CSV reports in the specified directory.
	 *
	 * @param string $reports_dir Full path to the reports directory.
	 *
	 * @return void
	 */
	public function create_all_csvs( string $reports_dir ): void {
		// Ensure reports directory exists.
		if ( ! is_dir( $reports_dir ) ) {
			wp_mkdir_p( $reports_dir );
		}

		$reports_dir = rtrim( $reports_dir, '/' );

		// Create posts.csv.
		$posts_path = $reports_dir . '/' . self::REPORT_POSTS;
		$this->create_posts_csv( $posts_path );

		// Create users.csv.
		$users_path = $reports_dir . '/' . self::REPORT_USERS;
		$this->create_users_csv( $users_path );

		// Create terms.csv.
		$terms_path = $reports_dir . '/' . self::REPORT_TERMS;
		$this->create_terms_csv( $terms_path );
	}

	/**
	 * Creates posts.csv from imported_posts.jsonl and deleted_modified_ids.jsonl.
	 *
	 * @param string $file_path Full path to the output CSV file.
	 *
	 * @return int Number of rows written.
	 */
	private function create_posts_csv( string $file_path ): int {
		$handle = fopen( $file_path, 'w' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.
		if ( ! $handle ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'ReportCreator: Failed to open %s for writing', $file_path ) );
			return 0;
		}

		// Write header.
		// Escape='' for RFC 4180 compliance (@see https://www.php.net/manual/en/function.fputcsv.php).
		fputcsv( $handle, [ 'status', 'post_type', 'id_old', 'id_new' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

		// Track unique posts by id_new to handle deduplication.
		// A post might be imported then later modified - we want final status.
		// Status priority: modified > imported.
		$posts_by_id_new = [];

		// Read imported posts (may have status = "imported" or "modified" for MDCS field updates).
		$imported_posts = $this->run_state->read_imported_posts();
		foreach ( $imported_posts as $post ) {
			$id_new = (int) $post['id_new'];
			$status = $post['status'] ?? 'imported';

			// Only update if new status has higher priority (modified > imported).
			$current_status = $posts_by_id_new[ $id_new ]['status'] ?? '';
			if ( 'modified' === $current_status ) {
				// Already modified, keep it.
				continue;
			}

			$posts_by_id_new[ $id_new ] = [
				'status'    => $status,
				'post_type' => $post['post_type'] ?? 'post',
				'id_old'    => (int) $post['id_old'],
				'id_new'    => $id_new,
			];
		}

		// Read deleted/reimported modified posts (status = "modified").
		// These overwrite the "imported" status for the same id_new.
		$modified_posts = $this->run_state->read_deleted_modified_ids();
		foreach ( $modified_posts as $post ) {
			$id_new = (int) $post['local_id'];
			// Get post_type from the existing record or from the post itself.
			$post_type = 'post';
			if ( isset( $posts_by_id_new[ $id_new ]['post_type'] ) ) {
				$post_type = $posts_by_id_new[ $id_new ]['post_type'];
			} else {
				// Try to get post type from database.
				$wp_post = get_post( $id_new );
				if ( $wp_post ) {
					$post_type = $wp_post->post_type;
				}
			}
			$posts_by_id_new[ $id_new ] = [
				'status'    => 'modified',
				'post_type' => $post_type,
				'id_old'    => (int) $post['live_id'],
				'id_new'    => $id_new,
			];
		}

		// Write rows.
		$count = 0;
		foreach ( $posts_by_id_new as $row ) {
			// Escape='' for RFC 4180 compliance (@see https://www.php.net/manual/en/function.fputcsv.php).
			fputcsv( $handle, [ $row['status'], $row['post_type'], $row['id_old'], $row['id_new'] ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.
			$count++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		return $count;
	}

	/**
	 * Creates users.csv from imported_users.jsonl.
	 *
	 * @param string $file_path Full path to the output CSV file.
	 *
	 * @return int Number of rows written.
	 */
	private function create_users_csv( string $file_path ): int {
		$handle = fopen( $file_path, 'w' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.
		if ( ! $handle ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'ReportCreator: Failed to open %s for writing', $file_path ) );
			return 0;
		}

		// Write header.
		// Escape='' for RFC 4180 compliance (@see https://www.php.net/manual/en/function.fputcsv.php).
		fputcsv( $handle, [ 'status', 'id_old', 'id_new' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

		// Track unique users by id_new to handle deduplication.
		// Status priority: modified > merged > imported.
		$users_by_id_new = [];

		$imported_users = $this->run_state->read_imported_users();
		foreach ( $imported_users as $user ) {
			$id_new = (int) $user['id_new'];
			$status = $user['status'] ?? 'imported';

			// Only update if new status has higher priority.
			if ( ! isset( $users_by_id_new[ $id_new ] ) || $this->status_priority( $status ) > $this->status_priority( $users_by_id_new[ $id_new ]['status'] ) ) {
				$users_by_id_new[ $id_new ] = [
					'status' => $status,
					'id_old' => (int) $user['id_old'],
					'id_new' => $id_new,
				];
			}
		}

		// Write rows.
		$count = 0;
		foreach ( $users_by_id_new as $row ) {
			// Escape='' for RFC 4180 compliance (@see https://www.php.net/manual/en/function.fputcsv.php).
			fputcsv( $handle, [ $row['status'], $row['id_old'], $row['id_new'] ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.
			$count++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		return $count;
	}

	/**
	 * Creates terms.csv from imported_terms.jsonl.
	 *
	 * @param string $file_path Full path to the output CSV file.
	 *
	 * @return int Number of rows written.
	 */
	private function create_terms_csv( string $file_path ): int {
		$handle = fopen( $file_path, 'w' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.
		if ( ! $handle ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'ReportCreator: Failed to open %s for writing', $file_path ) );
			return 0;
		}

		// Write header.
		// Escape='' for RFC 4180 compliance (@see https://www.php.net/manual/en/function.fputcsv.php).
		fputcsv( $handle, [ 'status', 'term_id_old', 'term_id_new', 'taxonomy' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

		// Track unique terms by term_id_new to handle deduplication.
		// Status priority: merged > imported.
		$terms_by_id_new = [];

		$imported_terms = $this->run_state->read_imported_terms();
		foreach ( $imported_terms as $term ) {
			$id_new = (int) $term['term_id_new'];
			$status = $term['status'] ?? 'imported';

			// Only update if new status has higher priority.
			if ( ! isset( $terms_by_id_new[ $id_new ] ) || $this->status_priority( $status ) > $this->status_priority( $terms_by_id_new[ $id_new ]['status'] ) ) {
				$terms_by_id_new[ $id_new ] = [
					'status'      => $status,
					'term_id_old' => (int) $term['term_id_old'],
					'term_id_new' => $id_new,
					'taxonomy'    => $term['taxonomy'] ?? '',
				];
			}
		}

		// Write rows.
		$count = 0;
		foreach ( $terms_by_id_new as $row ) {
			// Escape='' for RFC 4180 compliance (@see https://www.php.net/manual/en/function.fputcsv.php).
			fputcsv( $handle, [ $row['status'], $row['term_id_old'], $row['term_id_new'], $row['taxonomy'] ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.
			$count++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		return $count;
	}

	/**
	 * Returns the priority of a status for deduplication.
	 * Higher priority statuses override lower ones.
	 *
	 * @param string $status Status string.
	 *
	 * @return int Priority value (higher = more important).
	 */
	private function status_priority( string $status ): int {
		return match ( $status ) {
			'modified' => 3,
			'merged'   => 2,
			'imported' => 1,
			default    => 0,
		};
	}
}
