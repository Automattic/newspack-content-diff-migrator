<?php
/**
 * Content Diff migrator exports the content differential from an external WordPress site and imports it to the local
 * WordPress site while keeping the existing local content.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Command;

use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;
use Newspack\ContentDiffMigrator\Logic\DataImporter;
use Newspack\ContentDiffMigrator\Logic\DB;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\Logger;
use Newspack\ContentDiffMigrator\Utils\Progress;
use Newspack\ContentDiffMigrator\Utils\ReportCreator;
use Newspack\MigrationTools\Hooks\MemoryCleanupHook;
use Psr\Log\LogLevel;
use WP_CLI;

/**
 * Content Diff Migrator CLI commands class.
 */
class ContentDiffMigrator {

	/**
	 * Content Diff logic class.
	 *
	 * @var ContentDiffLogic Logic.
	 */
	private ContentDiffLogic $logic;

	/**
	 * DataImporter instance for importing post-related data.
	 *
	 * @var DataImporter
	 */
	private DataImporter $data_importer;

	/**
	 * Database utilities class.
	 *
	 * @var DB Database utilities.
	 */
	private DB $db;

	/**
	 * Prefix of tables from the live DB, which are imported next to local WP tables.
	 *
	 * @var null|string Live DB tables prefix.
	 */
	private ?string $live_table_prefix = null;

	/**
	 * RunState instance for managing execution state data files.
	 *
	 * @var null|RunState RunState instance.
	 */
	private ?RunState $run_state = null;

	/**
	 * Whether running in testing environment.
	 *
	 * When true, bypasses WP_CLI::confirm() calls and memory cleanup sleep time is set to 0.
	 *
	 * @var bool
	 */
	private bool $test_env;

	/**
	 * Constructor.
	 *
	 * @param bool $test_env For testing: suppresses confirmations and removes memory cleanup sleep time.
	 */
	public function __construct( bool $test_env = false ) {
		// $wpdb is global here because ContentDiffMigrator is integration-tested (real DB), while Logic classes accept injectable $wpdb for unit testing with mocks.
		global $wpdb;

		$this->data_importer = new DataImporter( $wpdb );
		$this->logic         = new ContentDiffLogic( $wpdb, null, $this->data_importer );
		$this->db            = new DB( $wpdb );
		$this->test_env      = $test_env;
	}

	/**
	 * Set RunState instance, used in testing environment.
	 *
	 * @param RunState $run_state RunState instance.
	 */
	public function set_run_state( RunState $run_state ): void {
		$this->run_state = $run_state;
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-diff-migrator search-new-content-on-live',
			[ __CLASS__, 'cmd_search_new_content_on_live' ],
			[
				'shortdesc' => 'Searches for new posts existing in the Live site tables and not in the local site tables, and exports the IDs to a file.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'data-dir',
						'description' => 'Directory to store migration data and logs.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'source-hostname',
						'description' => 'Source hostname (e.g., www.example.com). Used to namespace old ID metadata.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types-csv',
						'description' => 'Defaults are set/hardcoded at the top of the command, in the variable $post_types. CSV of all the post types to scan, no extra spaces. E.g. --post-types-csv=post,page,attachment,custom_cpt1. Note: For CoAuthors Plus Guest Authors support, include guest-author CPT, and in the migrate command make sure author taxonomy is migrated (author taxonomy is already a default value in --custom-taxonomies-csv).',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-diff-migrator migrate-live-content',
			[ __CLASS__, 'cmd_migrate_live_content' ],
			[
				'shortdesc' => 'Migrates content from Live site tables to local site tables.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'data-dir',
						'description' => 'Directory containing migration data and logs.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'source-hostname',
						'description' => 'Source hostname (e.g., www.example.com).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'custom-taxonomies-csv',
						'description' => 'Defaults are set/hardcoded at the top of the command, in the variable $taxonomies_to_migrate. CSV of all the taxonomies to import. If you are adding custom taxonomies and modifying the defaults, make sure to include default WP taxonomies (category,post_tag,author), e.g. --custom-taxonomies-csv=post_tag,category,author,brand,custom_taxonomy.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-diff-migrator list-previously-migrated-source-hostnames',
			[ __CLASS__, 'cmd_list_migrated_source_hostnames' ],
			[
				'shortdesc' => 'Lists all source hostnames from which content has been imported.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-diff-migrator attribute-existing-content-to-hostname',
			[ __CLASS__, 'cmd_attribute_existing_content_to_hostname' ],
			[
				'shortdesc' => 'Attributes existing local content to a source hostname by comparing with those live DB tables and adding source-specific metadata.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'source-hostname',
						'description' => 'Source hostname (e.g., www.example.com).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types-csv',
						'description' => 'Defaults are set/hardcoded at the top of the command, in the variable $post_types. CSV of post types to attribute. Note: For CoAuthors Plus Guest Authors support, include guest-author CPT, and in the migrate command make sure author taxonomy is migrated (author taxonomy is already a default value in --custom-taxonomies-csv).',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-diff-migrator display-collations-comparison',
			[ __CLASS__, 'cmd_compare_collations_of_live_and_core_wp_tables' ],
			[
				'shortdesc' => 'Display a table comparing collations of Live and Core WP tables.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-tables',
						'description' => 'CSV of tables to skip checking for collation.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'different-collations-only',
						'description' => 'This flag determines to only display tables with differing collations.',
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-diff-migrator correct-collations-for-live-wp-tables',
			[ __CLASS__, 'cmd_correct_collations_for_live_wp_tables' ],
			[
				'shortdesc' => 'This command will handle the necessary operations to match collations across Live and Core WP tables. Speed is auto-determined based on total table size.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-tables',
						'description' => 'Skip checking a particular set of tables from the collation checks.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-diff-migrator search-new-content-on-live`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 * 
	 * @throws \Exception If error occurs.
	 */
	public function cmd_search_new_content_on_live( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		$data_dir          = $assoc_args['data-dir'] ?? false;
		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;
		$source_hostname   = $assoc_args['source-hostname'] ?? false;
		$post_types        = isset( $assoc_args['post-types-csv'] ) ? explode( ',', $assoc_args['post-types-csv'] ) : [ 'post', 'page', 'attachment' ];
		
		// Set instance properties.
		global $wpdb;
		// Only create RunState if not already injected (integration tests inject their own testable RunState).
		if ( null === $this->run_state ) {
			$this->run_state = new RunState( rtrim( $data_dir, '/' ) . '/run-state' );
		}

		// Init logger.
		Logger::instance()->init( rtrim( $data_dir, '/' ) . '/' . __FUNCTION__ . '.log' );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, 'Starting command content-diff-search-new-content-on-live...' );

		// Check if previous run-state exists. Warn and exit to protect previous logs and run-state data (useful for debugging and backtracking migrations).
		// Skip in test environment to allow testing of multiple search cycles.
		$existing_manifest = $this->run_state->get_manifest();
		if ( ! $this->test_env && $existing_manifest ) {
			$existing_source = $existing_manifest['source_hostname'] ?? 'unknown';
			$existing_date   = $existing_manifest['created_at'] ?? 'unknown';

			if ( $existing_source !== $source_hostname ) {
				Logger::instance()->log(
					Logger::OUTPUT_BOTH,
					LogLevel::ERROR,
					sprintf( 'This --data-dir contains run-state from a DIFFERENT source hostname (%s, created %s). Please use a new --data-dir.', $existing_source, $existing_date ) 
				);
			} else {
				Logger::instance()->log(
					Logger::OUTPUT_BOTH,
					LogLevel::ERROR,
					sprintf( 'This --data-dir contains run-state from a previous migration run (created %s). Please use a new --data-dir to preserve previous logs for debugging.', $existing_date ) 
				);
			}
			return; // Exit early (a return is friendly to both CLI and test environments).
		}

		try {
			$this->db->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $e->getMessage() . " About to run `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --skip-tables=options` ..." );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'skip-tables'       => 'options',
				]
			);
		}

		// List previously migrated source hostnames and warn if a similar hostname exists (to detect www or non-www variants of the same hostname).
		$this->cmd_list_migrated_source_hostnames( [], [] );
		$this->warn_if_similar_hostname_exists( $source_hostname );

		// Search distinct Post types in live DB.
		$live_table_prefix_escaped = esc_sql( $live_table_prefix );
		$cpts_live = $wpdb->get_col( "SELECT DISTINCT( post_type ) FROM {$live_table_prefix_escaped}posts ;" ); // phpcs:ignore -- table prefix string value was escaped.

		// Validate selected CPTs and remove invalid ones.
		$post_types = array_values(
			array_filter(
				$post_types,
				function ( $v ) use ( $cpts_live ) {
					if ( ! in_array( $v, $cpts_live, true ) ) {
						Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'The selected Post Type `%s` is not found in live DB and will not be migrated.', $v ) );
						return false;
					}
					return true;
				}
			)
		);

		// Notify which CPTs are being migrated.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'Proceeding to migrate Post Types: %s', "\n- " . implode( "\n- ", $post_types ) ) );

		// Show remaining post types found in live DB that won't be migrated.
		$unmigrated_post_types = array_diff( $cpts_live, $post_types );
		if ( ! empty( $unmigrated_post_types ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Other Post Types found in live DB which will not be migrated: %s', "\n- " . implode( "\n- ", $unmigrated_post_types ) ) );
		}

		// Warn if there is content on local which has not been migrated from any source hostname (has no "old_id meta"), and which will not be considered/compared during migration.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for unattributed content...' );
		$unattributed_count = $this->check_and_warn_if_there_is_unattributed_content( $post_types );
		if ( $unattributed_count > 0 && ! $this->test_env ) {
			WP_CLI::confirm( sprintf( 'This unattributed local content will not be diff-ed against the live content during migration. Duplicates may be created if this existing unattributed content does belong to the source hostname %s. Enter (y) to continue with migration, or (n) to quit now and first run `attribute-existing-content-to-hostname`?', $source_hostname ) );
		}

		// Get post types other than attachments.
		$post_types_non_attachments = $post_types;
		$key                        = array_search( 'attachment', $post_types_non_attachments );
		if ( false !== $key ) {
			unset( $post_types_non_attachments[ $key ] );
			$post_types_non_attachments = array_values( $post_types_non_attachments );
		}

		// Get already migrated old_id=>new_id mappings for all objects: posts/CPTs, attachments, users, terms (more memory efficient).
		$post_old_id_map = [];
		if ( ! empty( $post_types_non_attachments ) ) {
			$post_old_id_map = $this->logic->get_imported_post_id_mapping_from_db( $source_hostname, $post_types_non_attachments );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		}
		$attachment_old_id_map = [];
		if ( in_array( 'attachment', $post_types ) ) {
			$attachment_old_id_map = $this->logic->get_imported_post_id_mapping_from_db( $source_hostname, [ 'attachment' ] );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		}
		$user_old_id_map = $this->logic->get_imported_user_id_mapping_from_db( $source_hostname );
		$term_old_id_map = $this->logic->get_imported_term_id_mapping_from_db( $source_hostname );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		try {
			// Query live DB for posts and CPTs.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Searching live DB for CPTs: %s ...', implode( ',', $post_types_non_attachments ) ) );
			$results_live_posts  = [];
			$results_local_posts = [];
			if ( ! empty( $post_types_non_attachments ) ) {
				$results_live_posts  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );
				$results_local_posts = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			}

			// Check new objects.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %s total from live site, checking for new ones...', count( $results_live_posts ) ) );
			$new_live_ids = $this->logic->filter_new_live_ids( $results_live_posts, $post_old_id_map );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d new IDs found (see %s).', count( $new_live_ids ), rtrim( $data_dir, '/' ) . '/run-state/' . RunState::FILE_NEW_IDS ) );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Per MDCS: Exclude new pages on consecutive runs (pages are only imported on first run).
			// Check if any pages have been imported from this source hostname.
			$imported_page_count = $this->logic->get_imported_post_count_by_type( $source_hostname, 'page' );
			if ( $imported_page_count > 0 && ! empty( $new_live_ids ) ) {
				// Build a map of live post IDs to their post types for quick lookup.
				$live_post_types_map = [];
				foreach ( $results_live_posts as $live_post ) {
					$live_post_types_map[ $live_post['ID'] ] = $live_post['post_type'];
				}
				// Filter out pages from new IDs.
				$new_live_ids_before_filter = count( $new_live_ids );
				$new_live_ids               = array_filter(
					$new_live_ids,
					fn( $id ) => ! isset( $live_post_types_map[ $id ] ) || 'page' !== $live_post_types_map[ $id ]
				);
				$new_live_ids               = array_values( $new_live_ids ); // Re-index.
				$pages_filtered             = $new_live_ids_before_filter - count( $new_live_ids );
				if ( $pages_filtered > 0 ) {
					Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d new pages excluded per MDCS (pages only imported on first run).', $pages_filtered ) );
				}
			}

			// Check modified objects -- according to the Migration Data Consistency Standard -- these will get reimported fully.
			// Note: Pages and attachments are excluded (attachments are checked for individual field updates separately in update_modified_attachments). All other post types (including custom CPTs) are checked for modifications.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for content which was modified on live...' );
			$results_live_posts_for_modified_check  = array_filter( $results_live_posts, fn( $p ) => 'page' !== $p['post_type'] && 'attachment' !== $p['post_type'] );
			$results_local_posts_for_modified_check = array_filter( $results_local_posts, fn( $p ) => 'page' !== $p['post_type'] && 'attachment' !== $p['post_type'] );
			$modified_live_ids                      = $this->logic->filter_modified_live_ids(
				$results_live_posts_for_modified_check,
				$results_local_posts_for_modified_check,
				$post_old_id_map,
				$live_table_prefix,
				$user_old_id_map,
				$attachment_old_id_map,
				$term_old_id_map
			);
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d modified IDs found (see %s).', count( $modified_live_ids ), rtrim( $data_dir, '/' ) . '/run-state/' . RunState::FILE_MODIFIED_IDS ) );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Query live DB for attachments.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Searching live DB for attachments ...' );
			$results_live_attachments = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Check new attachments.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %s total from live site, checking for new ones...', count( $results_live_attachments ) ) );
			$new_live_attachment_ids = $this->logic->filter_new_live_ids( $results_live_attachments, $attachment_old_id_map );
			$new_live_ids            = array_merge( $new_live_ids, $new_live_attachment_ids );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d new attachment IDs found (see %s).', count( $new_live_attachment_ids ), rtrim( $data_dir, '/' ) . '/run-state/' . RunState::FILE_NEW_IDS ) );

		} catch ( \Exception $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $e->getMessage() );
			throw $e;
		}

		// Write new IDs to run-state file -- even if there are no new IDs, write the empty list -- migrate command will continue to allow other data to be migrated (users, modified IDs, etc.).
		$this->run_state->write_new_ids( $new_live_ids );
		if ( 0 === count( $new_live_ids ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'List of new IDs to migrate stored to run-state file %s', RunState::FILE_NEW_IDS ) );
		}

		// Write modified IDs to run-state file.
		$this->run_state->write_modified_ids( $modified_live_ids );
		if ( count( $modified_live_ids ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'List of modified IDs to reimport stored to run-state file %s', RunState::FILE_MODIFIED_IDS ) );
		}

		// Save manifest.json with migration TOC.
		$manifest = [
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'source_hostname'   => $source_hostname,
			'live_table_prefix' => $live_table_prefix,
			'post_types'        => $post_types,
			'counts'            => [
				'new_ids'      => count( $new_live_ids ),
				'modified_ids' => count( $modified_live_ids ),
			],
		];
		$this->run_state->write_manifest( $manifest );

		// Display info about available logs.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Full logs were saved to %s:', rtrim( (string) $data_dir, '/' ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '- debug/action log: %s', basename( Logger::instance()->get_log_file_path() ?? '' ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '- run-state manifest: %s', RunState::FILE_MANIFEST ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All done searching for new content on live 🙌  Proceed by running the `migrate-live-content` command 🚀' );
	}

	/**
	 * Callable for `newspack-content-diff-migrator migrate-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 * 
	 * @throws \RuntimeException If run-state file not found or empty.
	 */
	public function cmd_migrate_live_content( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		global $wpdb;

		$data_dir              = $assoc_args['data-dir'] ?? false;
		$live_table_prefix     = $assoc_args['live-table-prefix'] ?? false;
		$source_hostname       = $assoc_args['source-hostname'] ?? false;
		$taxonomies_to_migrate = isset( $assoc_args['custom-taxonomies-csv'] ) ? explode( ',', $assoc_args['custom-taxonomies-csv'] ) : [ 'category', 'post_tag', 'author' ];
		
		// Init logger.
		Logger::instance()->init( rtrim( $data_dir, '/' ) . '/' . __FUNCTION__ . '.log' );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::INFO, sprintf( 'Starting %s | source hostname: %s', __FUNCTION__, $source_hostname ) );

		// Set instance properties.
		$this->live_table_prefix = $live_table_prefix;
		if ( null === $this->run_state ) {
			$this->run_state = new RunState( rtrim( $data_dir, '/' ) . '/run-state' );
		}
		// Set RunState on DataImporter for tracking users/terms.
		$this->data_importer->set_run_state( $this->run_state );

		// In case custom taxonomies were explicitly provided, but category/post_tag/author were not among those, warn the user that they won't be migrated and ask for confirmation to continue.
		if ( isset( $assoc_args['custom-taxonomies-csv'] ) ) {
			if ( ! in_array( 'category', $taxonomies_to_migrate ) ) {
				if ( ! $this->test_env ) {
					WP_CLI::confirm( 'Warning, category was not given in --custom-taxonomies-csv argument and so categories will not be migrated. Continue?' );
				}
			}
			if ( ! in_array( 'post_tag', $taxonomies_to_migrate ) ) {
				if ( ! $this->test_env ) {
					WP_CLI::confirm( 'Warning, post_tag was not given in --custom-taxonomies-csv argument and so tags will not be migrated. Continue?' );
				}
			}
			if ( ! in_array( 'author', $taxonomies_to_migrate ) ) {
				if ( ! $this->test_env ) {
					WP_CLI::confirm( 'Warning, author was not given in --custom-taxonomies-csv argument and so co-authors will not be migrated. Continue?' );
				}
			}
		}

		// Validate DBs.
		try {
			$this->db->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $e->getMessage() );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, "Now running command `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --skip-tables=options` ..." );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'skip-tables'       => 'options',
				]
			);
		}

		// List previously migrated source hostnames and warn if similar hostname exists (to detect www or non-www variants of the same hostname)..
		$this->cmd_list_migrated_source_hostnames( [], [] );
		$this->warn_if_similar_hostname_exists( $source_hostname );

		// Read post_types from manifest (saved by search command).
		$manifest = $this->run_state->get_manifest();
		if ( is_null( $manifest ) ) {
			throw new \RuntimeException( sprintf( 'Can not find manifest file (%s).', esc_html( RunState::FILE_MANIFEST ) ) );
		}

		// Get all taxonomies which exist in Live DB.
		$live_table_prefix_escaped = esc_sql( $live_table_prefix );
		$live_taxonomies = $wpdb->get_col( "SELECT DISTINCT( taxonomy ) FROM {$live_table_prefix_escaped}term_taxonomy ;" ); // phpcs:ignore -- table prefix string value was escaped.

		// Validate hierarchical taxonomies have valid parents. If they don't they should be fixed first.
		$taxonomies_to_migrate = $this->validate_and_fix_hierarchical_taxonomies( $taxonomies_to_migrate, $live_taxonomies );
		if ( ! empty( $taxonomies_to_migrate ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'Proceeding to migrate Taxonomies: %s', "\n- " . implode( "\n- ", $taxonomies_to_migrate ) ) );
		} else {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, 'No taxonomies to migrate found. Proceeding with migration to allow for edge cases, however please double-check whether this was intended.' );
		}

		// Show remaining taxonomies found in live DB that won't be migrated.
		$unmigrated_taxonomies = array_diff( $live_taxonomies, $taxonomies_to_migrate );
		if ( ! empty( $unmigrated_taxonomies ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Other Taxonomies found in live DB which will not be migrated: %s', "\n- " . implode( "\n- ", $unmigrated_taxonomies ) ) );
		}

		// Migrate all WP_Users (for WooComm data).
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Migrating all WP_Users...' );
		$inserted_wp_users_updates = $this->logic->migrate_all_users( $live_table_prefix, $source_hostname );
		Logger::instance()->log_brief_and_verbose( LogLevel::DEBUG, sprintf( 'Inserted %d WP_Users.', count( $inserted_wp_users_updates ) ), $inserted_wp_users_updates );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Get new IDs which will be migrated.
		$new_live_ids = $this->run_state->get_new_ids();
		if ( null === $new_live_ids ) {
			$message = sprintf( 'Run-state file %s not found or empty.', RunState::FILE_NEW_IDS );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, $message );
			throw new \RuntimeException( esc_html( $message ) );
		} elseif ( empty( $new_live_ids ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'No new posts to migrate.' );
			// Continue to allow even without new IDs, to allow other data to be migrated (users, modified IDs, etc.).
		}

		// Process modified IDs -- delete them, then reimport.
		// Get map (old => new) modified IDs.
		$modified_ids_map = $this->run_state->get_modified_ids_map();
		if ( null !== $modified_ids_map && ! empty( $modified_ids_map ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Deleting %s modified posts which will be reimported...', count( $modified_ids_map ) ) );
			
			// Get modified IDs which were already deleted, and skip them.
			$already_deleted_modified_ids_map = $this->run_state->get_deleted_modified_ids_map();
			$local_ids_to_delete              = array_values( array_diff( array_values( $modified_ids_map ), array_values( $already_deleted_modified_ids_map ) ) );
			
			// Delete modified posts so they can be re-imported.
			foreach ( $local_ids_to_delete as $id ) {
				$deleted = wp_delete_post( $id, true );
				if ( false === $deleted && null === $deleted ) {
					$context = [
						'local_id' => $id,
						'live_id'  => array_search( $id, $modified_ids_map ),
					];
					Logger::instance()->log_brief_and_verbose( LogLevel::ERROR, sprintf( 'Failed to delete modified post local ID %d, this post will not be updated/reimported.', $id ), $context );
					// Don't continue and save to run-state if deletion failed.
					continue;
				}

				// Save run-state info that this modified ID was deleted.
				$this->run_state->append_deleted_modified_id(
					[
						'live_id'  => array_search( $id, $modified_ids_map ),
						'local_id' => $id,
					] 
				);
			}

			// Merge modified posts IDs with $all_live_posts_ids for reimport.
			$new_live_ids = array_merge( $new_live_ids, array_keys( $modified_ids_map ) );
		}

		// If no new/modified posts to migrate, return early.
		if ( empty( $new_live_ids ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'No new/modified posts to migrate.' );
			return;
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Importing %d objects, hold tight...', count( $new_live_ids ) ) );
		$imported_posts_data = $this->import_posts( $new_live_ids, $taxonomies_to_migrate, $source_hostname );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Fetch all imported IDs just once for all post-processing methods (single DB query for performance).
		$all_imported_ids = $this->logic->get_all_imported_post_id_mapping_from_db( $source_hostname );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		$imported_attachment_ids_map    = $this->logic->filter_imported_attachments( $all_imported_ids );
		$imported_nonattachment_ids_map = $this->logic->filter_imported_non_attachments( $all_imported_ids );
		$imported_ids_map               = [];
		foreach ( $all_imported_ids as $record ) {
			$imported_ids_map[ $record['old_id'] ] = $record['new_id'];
		}
		unset( $all_imported_ids );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating Post parent IDs...' );
		$this->update_post_parent_ids( $new_live_ids, $imported_posts_data, $source_hostname, $imported_ids_map );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating Featured images IDs...' );
		$this->update_featured_image_ids( $imported_posts_data, $source_hostname, $imported_nonattachment_ids_map, $imported_attachment_ids_map );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating attachment IDs in block content...' );
		$this->update_attachment_ids_in_blocks( $imported_posts_data, $source_hostname, $imported_nonattachment_ids_map, $imported_attachment_ids_map );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Recalculate counts for all migrated taxonomies.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Recalculating term counts...' );
		$this->recalculate_term_counts( $taxonomies_to_migrate );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Migration Data Consistency Standard: Update modified fields of migrated objects.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for modified user fields...' );
		$user_updates = $this->logic->update_modified_users( $live_table_prefix, $source_hostname, $imported_attachment_ids_map );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Checked %d users, updated %d.', $user_updates['checked'], $user_updates['updated'] ) );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for modified attachment fields...' );
		$attachment_updates = $this->logic->update_modified_attachments( $live_table_prefix, $source_hostname, $imported_attachment_ids_map );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Checked %d attachments, updated %d.', $attachment_updates['checked'], $attachment_updates['updated'] ) );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for modified term fields...' );
		$term_updates = $this->logic->update_modified_terms( $live_table_prefix, $source_hostname, [ 'category', 'post_tag' ] );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Checked %d terms, updated %d.', $term_updates['checked'], $term_updates['updated'] ) );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Generate CSV reports from run-state JSONL files.
		$reports_dir    = rtrim( (string) $data_dir, '/' ) . '/reports';
		$report_creator = new ReportCreator( $this->run_state );
		$report_creator->create_all_csvs( $reports_dir );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( "Reports generated in %s/ :\n- %s\n- %s\n- %s", $reports_dir, ReportCreator::REPORT_POSTS, ReportCreator::REPORT_USERS, ReportCreator::REPORT_TERMS ) );

		// Display info about available logs.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'Full logs were saved to %s:', rtrim( (string) $data_dir, '/' ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '- debug/action log: %s', basename( Logger::instance()->get_log_file_path() ?? '' ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '- run-state manifest: %s', RunState::FILE_MANIFEST ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'All done migrating content from %s! 🙌 ', $source_hostname ) );
		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-diff-migrator list-previously-migrated-source-hostnames`.
	 *
	 * Lists all source hostnames from which content has been imported.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_list_migrated_source_hostnames( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		Logger::instance()->init( __FUNCTION__ . '.log' );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::INFO, sprintf( 'Starting %s', __FUNCTION__ ) );

		$source_sites = $this->logic->get_migrated_source_hostnames();
		if ( empty( $source_sites ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'No previously migrated source hostnames (sites) found.' );
			return;
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Previously migrated source hostnames: ' . implode( ', ', $source_sites ) );
	}

	/**
	 * Checks if the given source hostname is similar to any previously migrated hostname.
	 * If a substring match is found (in either direction), prompts the user for confirmation.
	 *
	 * @param string $source_hostname The source hostname provided by the user.
	 */
	private function warn_if_similar_hostname_exists( string $source_hostname ): void {
		$existing_hostnames = $this->logic->get_migrated_source_hostnames();
		if ( empty( $existing_hostnames ) ) {
			return;
		}

		// Check for substring matches (both directions).
		foreach ( $existing_hostnames as $existing ) {
			if ( $existing === $source_hostname ) {
				continue; // Exact match is fine, skip.
			}
			// Check if one is substring of the other.
			if ( false !== strpos( $existing, $source_hostname ) || false !== strpos( $source_hostname, $existing ) ) {
				if ( ! $this->test_env ) {
					WP_CLI::confirm( sprintf( 'Did you mean `%s` (previously migrated)? Enter (y) to continue with `%s` as a separate site, or (n) to quit and change your `--source-hostname` to `%s`.', $existing, $source_hostname, $existing ) );
				}
				return; // Only warn once for the first match.
			}
		}
	}

	/**
	 * Callable for `newspack-content-diff-migrator attribute-existing-content-to-hostname`.
	 *
	 * Attributes existing local content to a source hostname by comparing with live DB and adding
	 * source-specific metadata.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_attribute_existing_content_to_hostname( array $args, array $assoc_args ): void {
		global $wpdb;

		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;
		$source_hostname   = $assoc_args['source-hostname'] ?? false;
		$post_types        = isset( $assoc_args['post-types-csv'] ) ? explode( ',', $assoc_args['post-types-csv'] ) : [ 'post', 'page', 'attachment' ];
		
		// Init logger.
		Logger::instance()->init( __FUNCTION__ . '.log' );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::INFO, sprintf( 'Starting %s | source hostname: %s', __FUNCTION__, $source_hostname ) );

		// Introductory message (CLI only).
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, sprintf( 'This command will match existing local content to live DB records (assign migration metas) and thereby attribute this content to source hostname "%s". That will let Content Diff know that this content came from this specificsource hostname, and that it should be matched/compared agains the existing live content and properly import the newest differences.', $source_hostname ) );

		// Validate DBs.
		try {
			$this->db->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, $e->getMessage() );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'About to run command correct-collations-for-live-wp-tables ...' );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'skip-tables'       => 'options',
				]
			);
		}

		// Show count of unattributed content that will be processed.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for unattributed content...' );
		$unattributed_count = $this->check_and_warn_if_there_is_unattributed_content( $post_types );
		if ( 0 === $unattributed_count ) {
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, 'No unattributed content found. Nothing to do.' );
			return;
		}

		// Confirmation prompt.
		if ( ! $this->test_env ) {
			WP_CLI::confirm( sprintf( 'This will attribute ALL matched content to source hostname "%s". Continue?', $source_hostname ) );
		}

		// Variables.
		$meta_key = $this->logic->get_old_id_meta_key( $source_hostname );
		// Post statuses by type.
		$statuses_regular    = [ 'publish', 'future', 'draft', 'pending', 'private' ];
		$statuses_attachment = [ 'inherit' ];

		// Show existing source hostnames.
		$existing_source_sites = $this->logic->get_migrated_source_hostnames();
		if ( ! empty( $existing_source_sites ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Existing imported source hostnames: ' . implode( ', ', $existing_source_sites ) );
		} else {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'No previous imported source hostnames found.' );
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Matching local content (CPTs %s and users) to live DB, and attributing matches to source hostname %s ...', implode( ',', $post_types ), $source_hostname ) );
		
		// Process non-attachment post types.
		$post_types_non_attachments = array_filter( $post_types, fn( $pt ) => 'attachment' !== $pt );
		if ( ! empty( $post_types_non_attachments ) ) {
			// Match non-attachment post types.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Querying %s types...', implode( ',', $post_types_non_attachments ) ) );
			$results_local_posts = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, $statuses_regular );
			$results_live_posts  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', $post_types_non_attachments, $statuses_regular );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %d local, %d live. Matching local posts to live posts...', count( $results_local_posts ), count( $results_live_posts ) ) );
			$matched_posts = $this->logic->match_local_to_live_posts( $results_local_posts, $results_live_posts );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Save metas for matched posts, skip if already attributed.
			foreach ( $matched_posts as $match ) {
				$existing_meta = get_post_meta( $match['local_id'], $meta_key, true );
				if ( ! empty( $existing_meta ) ) {
					continue;
				}
				update_post_meta( $match['local_id'], $meta_key, $match['live_id'] );
				$context = [
					'local_id' => $match['local_id'],
					'live_id'  => $match['live_id'],
				];
				Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, sprintf( 'Object attributed to source_hostname %s', $source_hostname ), $context );
			}
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d local non-attachment objects attributed out of %d total.', count( $matched_posts ), count( $results_local_posts ) ) );
		}

		// Process attachments separately (like in cmd_search).
		$process_attachments = in_array( 'attachment', $post_types, true );
		if ( $process_attachments ) {
			// Match attachments.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Querying attachments...' );
			$results_local_attachments = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', [ 'attachment' ], $statuses_attachment );
			$results_live_attachments  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], $statuses_attachment );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %d local, %d live. Matching local attachments to live attachments...', count( $results_local_attachments ), count( $results_live_attachments ) ) );
			$matched_attachments = $this->logic->match_local_to_live_posts( $results_local_attachments, $results_live_attachments );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Attribute matched attachments to source hostname, skip if already attributed.
			foreach ( $matched_attachments as $match ) {
				$existing_meta = get_post_meta( $match['local_id'], $meta_key, true );
				if ( ! empty( $existing_meta ) ) {
					continue;
				}
				update_post_meta( $match['local_id'], $meta_key, $match['live_id'] );
				// Detailed log to file only.
				$context = [
					'local_id' => $match['local_id'],
					'live_id'  => $match['live_id'],
				];
				Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, sprintf( 'Attachment attributed to source_hostname %s', $source_hostname ), $context );
			}
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d local attachments attributed out of %d total.', count( $matched_attachments ), count( $results_local_attachments ) ) );
		}

		// Match users.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Querying users...' );
		$results_local_users = $this->logic->get_users_rows_for_attribution( $wpdb->prefix );
		$results_live_users  = $this->logic->get_users_rows_for_attribution( $live_table_prefix );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %d local, %d live. Matching local users to live users...', count( $results_local_users ), count( $results_live_users ) ) );
		$matched_users = $this->logic->match_local_to_live_users( $results_local_users, $results_live_users );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Attribute matched users to source hostname, skip if already attributed.
		foreach ( $matched_users as $match ) {
			$existing_meta = get_user_meta( $match['local_id'], $meta_key, true );
			if ( ! empty( $existing_meta ) ) {
				continue;
			}
			update_user_meta( $match['local_id'], $meta_key, $match['live_id'] );
			// Detailed log to file only.
			$context = [
				'local_user_id' => $match['local_id'],
				'live_user_id'  => $match['live_id'],
			];
			Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, sprintf( 'User attributed to source_hostname %s', $source_hostname ), $context );
		}
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d local users attributed out of %d total.', count( $matched_users ), count( $results_local_users ) ) );

		// Match terms.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Querying terms...' );
		$results_local_terms = $this->logic->get_terms_rows_for_attribution( $wpdb->prefix );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		$results_live_terms = $this->logic->get_terms_rows_for_attribution( $live_table_prefix );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %d local, %d live. Matching local terms to live terms...', count( $results_local_terms ), count( $results_live_terms ) ) );
		$matched_terms = $this->logic->match_local_to_live_terms( $results_local_terms, $results_live_terms );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Attribute matched terms to source hostname, skip if already attributed.
		foreach ( $matched_terms as $match ) {
			$existing_meta = get_term_meta( $match['local_id'], $meta_key, true );
			if ( ! empty( $existing_meta ) ) {
				continue;
			}
			update_term_meta( $match['local_id'], $meta_key, $match['live_id'] );
			// Detailed log to file only.
			$context = [
				'local_term_id' => $match['local_id'],
				'live_term_id'  => $match['live_id'],
			];
			Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, sprintf( 'Term attributed to source_hostname %s', $source_hostname ), $context );
		}
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d local terms attributed out of %d total.', count( $matched_terms ), count( $results_local_terms ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'All done attributing content to %s 🙌 ', $source_hostname ) );
	}

	/**
	 * This function will display a table comparing the collations of Live and Core WP tables.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Optional arguments.
	 */
	public function cmd_compare_collations_of_live_and_core_wp_tables( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		$live_table_prefix     = $assoc_args['live-table-prefix'];
		$skip_tables           = ! empty( $assoc_args['skip-tables'] ) ? explode( ',', $assoc_args['skip-tables'] ) : [];
		$different_tables_only = $assoc_args['different-collations-only'] ?? false;

		Logger::instance()->init( __FUNCTION__ . '.log' );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, 'Starting command compare-collations-of-live-and-core-wp-tables...' );

		$tables = [];
		if ( $different_tables_only ) {
			$tables = $this->db->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );
		} else {
			$tables = $this->db->get_collation_comparison_of_live_and_core_wp_tables( $live_table_prefix, $skip_tables );
		}
		if ( ! empty( $tables ) ) {
			ob_start();
			\WP_CLI\Utils\format_items( 'table', $tables, array_keys( $tables[0] ) );
			$output = ob_get_clean();
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, $output );
		} else {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Live and Core WP DB table collations match.' );
		}
	}

	/**
	 * This function will execute the necessary steps to get Live WP
	 * tables to match the collation of Core WP tables.
	 * Speed is auto-determined based on total size of tables to fix.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Optional arguments.
	 */
	public function cmd_correct_collations_for_live_wp_tables( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		$live_table_prefix = $assoc_args['live-table-prefix'];
		$skip_tables       = isset( $assoc_args['skip-tables'] ) ? explode( ',', $assoc_args['skip-tables'] ) : [];
		
		Logger::instance()->init( __FUNCTION__ . '.log' );

		$tables_with_differing_collations = $this->db->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );

		if ( empty( $tables_with_differing_collations ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All table collations already match. Nothing to fix.' );
			return;
		}

		ob_start();
		\WP_CLI\Utils\format_items( 'table', $tables_with_differing_collations, array_keys( $tables_with_differing_collations[0] ) );
		$output = ob_get_clean();
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, $output );

		// Calculate total size of tables to fix for speed determination.
		$table_names      = array_map( fn( $t ) => $t['live_table_name'], $tables_with_differing_collations );
		$total_size_bytes = $this->db->get_total_table_size_bytes( $table_names );
		$total_size_gb    = round( $total_size_bytes / ( 1024 * 1024 * 1024 ), 2 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Total size of tables to fix: %.2f GB', $total_size_gb ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Using %s mode.', $total_size_bytes < 4 * 1024 * 1024 * 1024 ? 'fast (< 4GB)' : 'throttled (>= 4GB)' ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, "Now fixing $live_table_prefix tables collations..." );

		foreach ( $tables_with_differing_collations as $result ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Addressing ' . $result['table'] . ' table...' );
			$this->db->copy_table_data_using_proper_collation( $live_table_prefix, $result['table'], $total_size_bytes );
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Collation fixing complete. All backup tables have been deleted.' );
	}

	/**
	 * Validates local DB and live DB taxonomies. Checks if the taxonomies's parent term_ids are correct in the live DB, and sets those to zero if they are not correct.
	 *
	 * @param array $taxonomies_to_migrate Hierarchical taxonomies to migrate.
	 * @param array $live_taxonomies       List of all taxonomies found in the Live DB.
	 *
	 * @return array $taxonomies_to_migrate Validated and filtered hierarchical taxonomies to migrate.
	 */
	private function validate_and_fix_hierarchical_taxonomies( array $taxonomies_to_migrate, array $live_taxonomies ): array {
		global $wpdb;

		// Check if any of the taxonomies does not exist in the live DB.
		foreach ( $taxonomies_to_migrate as $key_taxonomy_to_migrate => $taxonomy_to_migrate ) {
			if ( ! in_array( $taxonomy_to_migrate, $live_taxonomies ) ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'The selected Taxonomy `%s` is not found in live DB and will not be migrated.', $taxonomy_to_migrate ) );
				unset( $taxonomies_to_migrate[ $key_taxonomy_to_migrate ] );
			}
		}
		$taxonomies_to_migrate = array_values( $taxonomies_to_migrate );
		if ( empty( $taxonomies_to_migrate ) ) {
			return [];
		}

		// Fix local taxonomies with nonexistent parent term_ids.
		$fixed_local = $this->logic->get_data_importer()->fix_hierarchical_taxonomies_parents( $wpdb->prefix, $taxonomies_to_migrate );
		if ( ! empty( $fixed_local ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Fixed %d local DB hierarchical taxonomies with invalid parent IDs (set to 0).', count( $fixed_local ) ) );
		}

		// Fix live taxonomies with nonexistent parent term_ids.
		$fixed_live = $this->logic->get_data_importer()->fix_hierarchical_taxonomies_parents( $this->live_table_prefix, $taxonomies_to_migrate );
		if ( ! empty( $fixed_live ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Fixed %d live DB hierarchical taxonomies with invalid parent IDs (set to 0).', count( $fixed_live ) ) );
		}

		return $taxonomies_to_migrate;
	}

	/**
	 * Checks for unattributed content and logs a warning if found. Checks for:
	 *   - posts and CPTs,
	 *   - attachments,
	 *   - users, and
	 *   - terms
	 * that don't have old_id meta attribution.
	 * 
	 * If any unattributed content is found, it logs a warning. The caller is responsible for
	 * handling the confirmation/flow based on the returned count.
	 *
	 * @param array $post_types      Post types to check for unattributed content.
	 * @return int Total count of unattributed objects.
	 */
	private function check_and_warn_if_there_is_unattributed_content( array $post_types ): int {
		$post_types_without_attachments = array_filter( $post_types, fn( $pt ) => 'attachment' !== $pt );
		$post_ids                       = $this->logic->get_unattributed_post_ids( $post_types_without_attachments );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		$attachment_ids = in_array( 'attachment', $post_types, true )
			? $this->logic->get_unattributed_attachment_ids()
			: [];
		$user_ids       = $this->logic->get_unattributed_user_ids();
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		$term_ids = $this->logic->get_unattributed_term_ids();
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		$total = count( $post_ids ) + count( $attachment_ids ) + count( $user_ids ) + count( $term_ids );
		if ( $total > 0 ) {
			// Save all unattributed IDs to a JSONL file.
			$file_path = $this->run_state->write_unattributed_content(
				$post_ids,
				$attachment_ids,
				$user_ids,
				$term_ids
			);
			Logger::instance()->log(
				Logger::OUTPUT_BOTH,
				LogLevel::WARNING,
				sprintf(
					"There are a total of %d existing objects on local without any `%s*` metas. See %s for full IDs, following is a summary with some sample IDs:\n- posts/CPTs: %s\n- attachments: %s\n- users: %s\n- terms: %s",
					$total,
					ContentDiffLogic::SAVED_META_LIVE_ID_PREFIX,
					$file_path,
					$this->format_log_message_count_with_sample_ids( $post_ids ),
					$this->format_log_message_count_with_sample_ids( $attachment_ids ),
					$this->format_log_message_count_with_sample_ids( $user_ids ),
					$this->format_log_message_count_with_sample_ids( $term_ids )
				)
			);
		}

		return $total;
	}

	/**
	 * Formats a count with sample IDs for display (shows up to 10 IDs).
	 *
	 * @param array $ids Array of IDs.
	 *
	 * @return string Formatted message like, "5 total (sample IDs: 1,2,3,4,5)", or "15 total (sample IDs: 1,2,...,10,...)" or "0".
	 */
	private function format_log_message_count_with_sample_ids( array $ids ): string {
		$count = count( $ids );
		if ( 0 === $count ) {
			return '0';
		}
		$sample_ids = array_slice( $ids, 0, 10 );
		$ids_str    = implode( ',', $sample_ids );
		if ( $count > count( $sample_ids ) ) {
			$ids_str .= ',...';
		}
		return sprintf( '%d total (sample IDs: %s)', $count, $ids_str );
	}

	/**
	 * Creates and imports posts and all related post data. Skips previously imported IDs found in $this->log_imported_post_ids.
	 *
	 * @param array  $new_live_ids          New Live IDs to be imported.
	 * @param array  $taxonomies_to_migrate List of taxonomies allowed to be migrated.
	 * @param string $source_hostname       Source hostname.
	 *
	 * @return array $imported_posts_data {
	 *     Array with subarray records for all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	private function import_posts( array $new_live_ids, array $taxonomies_to_migrate, string $source_hostname ): array {
		$imported_posts_data = [];

		// Get IDs which were already imported, and skip them.
		$already_imported_ids_map = $this->run_state->get_imported_post_ids_map() ?? [];

		// Modified posts (which were deleted and should be reimported) should not be skipped, unless their reimport already completed.
		// Check if the post actually exists at the preserved ID to determine if reimport is already completed.
		$deleted_modified_ids_map = $this->run_state->get_deleted_modified_ids_map();
		foreach ( $deleted_modified_ids_map as $live_id => $deleted_local_id ) {
			// If the post exists at the preserved ID, reimport already completed - don't force reimport.
			if ( null !== get_post( (int) $deleted_local_id ) ) {
				continue;
			}
			// Post was deleted but not yet reimported - force reimport by removing from skip list.
			unset( $already_imported_ids_map[ $live_id ] );
		}

		$live_ids_to_import = array_values( array_diff( $new_live_ids, array_keys( $already_imported_ids_map ) ) );
		if ( empty( $live_ids_to_import ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts were already imported, moving on.' );
			return $imported_posts_data;
		}
		if ( count( $already_imported_ids_map ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d of %d IDs were already imported, continuing from there...', count( $already_imported_ids_map ), count( $new_live_ids ) ) );
		}

		// Import posts.
		$progress = new Progress( count( $live_ids_to_import ), 20 );
		foreach ( $live_ids_to_import as $key_live_id => $id_live ) {
			// Output progress by 10%.
			$progress_milestone = $progress->tick( $key_live_id + 1 );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( $progress_milestone ) );
			}

			// Check if this is a modified post being reimported - if so, preserve its local ID.
			$existing_local_id = isset( $deleted_modified_ids_map[ (int) $id_live ] )
				? (int) $deleted_modified_ids_map[ (int) $id_live ]
				: null;

			// Import single post via Logic.
			try {
				$result                = $this->logic->import_single_post(
					(int) $id_live,
					$this->live_table_prefix,
					$taxonomies_to_migrate,
					$source_hostname,
					$existing_local_id
				);
				$imported_posts_data[] = $result;

				// Log imported post to run-state for resume capability.
				$this->run_state->append_imported_post( $result );
			} catch ( \Exception $e ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'import_posts error importing Live ID %d : %s', $id_live, $e->getMessage() ) );
				// Continue importing other posts.
			}
		}
		if ( $progress->finish() ) {
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( 100 ) );
		}

		// Flush the cache for DB updates to take effect.
		wp_cache_flush();

		return $imported_posts_data;
	}

	/**
	 * Updates all Posts' post_parent IDs.
	 *
	 * @param array  $all_live_posts_ids  Old (Live) IDs to have their post_parent updated.
	 * @param array  $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 * @param string $source_hostname     Source hostname.
	 * @param array  $imported_ids_map    Map of old_id => new_id for all imported posts.
	 */
	private function update_post_parent_ids( array $all_live_posts_ids, array $imported_posts_data, string $source_hostname, array $imported_ids_map ): void {
		global $wpdb;

		// Get IDs which already had their post_parent updated, and skip them.
		$already_updated_ids_map = $this->run_state->get_updated_parents_post_ids_map();

		// Modified posts (deleted and reimported) need their parents updated again.
		$deleted_modified_ids_map = $this->run_state->get_deleted_modified_ids_map();
		foreach ( array_keys( $deleted_modified_ids_map ) as $live_id ) {
			unset( $already_updated_ids_map[ $live_id ] );
		}

		$live_ids_for_parents_update = array_values( array_diff( $all_live_posts_ids, array_keys( $already_updated_ids_map ) ) );
		if ( empty( $live_ids_for_parents_update ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts already had their post_parent updated, moving on.' );
			return;
		}
		if ( count( $already_updated_ids_map ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d of %d post_parent IDs were already updated, continuing from there...', count( $already_updated_ids_map ), count( $all_live_posts_ids ) ) );
		}

		// Update parent IDs.
		$dispayed_cli_error_get_local_id     = false;
		$dispayed_cli_warning_update_parents = false;
		$progress                            = new Progress( count( $live_ids_for_parents_update ), 20 );
		foreach ( $live_ids_for_parents_update as $key_id_old => $id_old ) {
			// Output progress by 10%.
			$progress_milestone = $progress->tick( $key_id_old + 1 );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( $progress_milestone ) );
			}

			// Get new local Post ID.
			$id_new = $imported_ids_map[ $id_old ] ?? null;
			if ( null === $id_new ) {
				Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::ERROR, sprintf( 'update_post_parent_ids: live ID %d has no local mapping, skipping.', $id_old ) );
				if ( false === $dispayed_cli_error_get_local_id ) {
					Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::ERROR, sprintf( 'update_post_parent_ids: some live IDs have no local mapping. See %s for full list (first example: $id_old=%s).', Logger::instance()->get_log_file_path(), $id_old ) );
					$dispayed_cli_error_get_local_id = true;
				}
				continue;
			}

			// Get the local Post's post_parent, which is still set to old live ID value.
			$post_row      = $this->logic->select_post_row( $wpdb->prefix, $id_new );
			$parent_id_old = $post_row['post_parent'] ?? null;
			if ( ( '0' == $parent_id_old ) || empty( $parent_id_old ) ) {
				// No update on parent ID 0.
				continue;
			}

			// Get new post_parent from imported IDs map, if it exists. It's also possible that this $post's post_parent already existed in
			// local DB before the Content Diff import was run, in which case it won't be present in the list of the posts we imported.
			$parent_id_new = $imported_ids_map[ $parent_id_old ] ?? null;
			// 1/3 - First try searching for new parent ID by "old ID postmeta", in case a previous content diff imported it.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = $this->logic->get_current_post_id_by_old_id( $parent_id_old, $source_hostname );
			}
			// 2/3 - Next try searching for the new parent_id by comparing the local and live DB tables.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = $this->logic->get_current_post_id_by_comparing_with_live_db( $parent_id_old, $this->live_table_prefix );
			}
			// 3/3 - If it can't be found, set parent to 0 and log warning. This might be legit, e.g. the parent object being a
			// post_type different than the supported post type, or an invalid relationship in live DB if post_parent object is actually missing.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = 0;
				// These warnings are relatively common and never an actual error. Log all the cases to file, and display just the first one to CLI.
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::WARNING,
					'update_post_parent_ids: parent ID is missing in live or not being migrated (different post type), $parent_id_new is set to 0.',
					[
						'id_old'        => $id_old,
						'id_new'        => $id_new,
						'parent_id_old' => $parent_id_old,
					] 
				);
				if ( false === $dispayed_cli_warning_update_parents ) {
					Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::WARNING, sprintf( 'update_post_parent_ids: some parent IDs not found on live. This is usually not an error (happens when parent_ids are not found on live, or are different post types that are not being migrated). See %s for full list (first example: $id_old=%s, $id_new=%s, $parent_id_old=%s; $parent_id_new set to 0).', Logger::instance()->get_log_file_path(), $id_old, $id_new, $parent_id_old ) );
					$dispayed_cli_warning_update_parents = true;
				}
			}

			// Update.
			if ( $parent_id_old != $parent_id_new ) {
				$this->logic->update_post_parent( $id_new, $parent_id_new );
			}
			// Log the parent update to RunState for resume capability (even if post wasn't updated, it's still been processed).
			$log_entry = [
				'id_old'        => $id_old,
				'id_new'        => $id_new,
				'parent_id_old' => $parent_id_old,
				'parent_id_new' => $parent_id_new,
			];
			$this->run_state->append_updated_parent( $log_entry );
		}
		if ( $progress->finish() ) {
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( 100 ) );
		}
	}

	/**
	 * Updates all Featured Images IDs.
	 *
	 * @param array  $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 * @param string $source_hostname              Source hostname.
	 * @param array  $imported_nonattachment_ids_map Map of old_id => new_id for non-attachment posts.
	 * @param array  $imported_attachment_ids_map    Map of old_id => new_id for attachments.
	 */
	private function update_featured_image_ids( array $imported_posts_data, string $source_hostname, array $imported_nonattachment_ids_map, array $imported_attachment_ids_map ): void {

		// Get IDs which already had featured images updated, and skip them.
		$already_updated_ids_map = $this->run_state->get_updated_featured_image_post_ids_map();

		// Modified posts (deleted and reimported) need their featured images updated again.
		$deleted_modified_ids_map = $this->run_state->get_deleted_modified_ids_map();
		foreach ( array_keys( $deleted_modified_ids_map ) as $live_id ) {
			unset( $already_updated_ids_map[ $live_id ] );
		}

		$ids_map_for_featured_update = array_diff_key( $imported_nonattachment_ids_map, $already_updated_ids_map );
		if ( empty( $ids_map_for_featured_update ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts already had their featured images updated, moving on.' );
			return;
		}
		if ( count( $already_updated_ids_map ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d of %d featured image IDs were already updated, continuing from there...', count( $already_updated_ids_map ), count( $imported_nonattachment_ids_map ) ) );
		}

		// Update featured images.
		$progress = new Progress( count( $ids_map_for_featured_update ), 20 );
		$step     = 0;
		foreach ( $ids_map_for_featured_update as $id_old => $id_new ) {
			// Output progress by 10%.
			++$step;
			$progress_milestone = $progress->tick( $step );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( $progress_milestone ) );
			}

			$this->logic->update_featured_image( $id_new, $imported_attachment_ids_map );

			// Save to run-state for resume capability (even if post's featured image wasn't updated, it has still been processed).
			$this->run_state->append_updated_featured_image_post(
				[
					'id_old' => $id_old,
					'id_new' => $id_new,
				]
			);
		}
		if ( $progress->finish() ) {
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( 100 ) );
		}
	}

	/**
	 * Updates Attachment IDs in Post contents.
	 *
	 * Some Gutenberg Blocks contain `id` or `ids` of Attachments attributes in their headers, and image elements contain those
	 * IDs too.
	 *
	 * @param array  $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 * @param string $source_hostname              Source hostname.
	 * @param array  $imported_nonattachment_ids_map Map of old_id => new_id for non-attachment posts.
	 * @param array  $imported_attachment_ids_map    Map of old_id => new_id for attachments.
	 */
	private function update_attachment_ids_in_blocks( array $imported_posts_data, string $source_hostname, array $imported_nonattachment_ids_map, array $imported_attachment_ids_map ): void {

		// Get IDs which already had block attachment IDs updated, and skip them.
		$already_updated_ids_map = $this->run_state->get_updated_block_post_ids_map();

		// Modified posts (deleted and reimported) need their blocks updated again.
		$deleted_modified_ids_map = $this->run_state->get_deleted_modified_ids_map();
		foreach ( array_keys( $deleted_modified_ids_map ) as $live_id ) {
			unset( $already_updated_ids_map[ $live_id ] );
		}

		$ids_map_for_blocks_update = array_diff_key( $imported_nonattachment_ids_map, $already_updated_ids_map );
		if ( empty( $ids_map_for_blocks_update ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts already had their blocks\' attachment IDs updated, moving on.' );
			return;
		}
		if ( count( $already_updated_ids_map ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d of %d posts already had their blocks\' IDs updated, continuing from there...', count( $already_updated_ids_map ), count( $imported_nonattachment_ids_map ) ) );
		}

		// Update block attachment IDs.
		$progress = new Progress( count( $ids_map_for_blocks_update ), 20 );
		$step     = 0;
		foreach ( $ids_map_for_blocks_update as $id_old => $id_new ) {
			// Output progress by 10%.
			++$step;
			$progress_milestone = $progress->tick( $step );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( $progress_milestone ) );
			}

			$this->logic->update_blocks_ids( $id_new, $imported_attachment_ids_map );

			// Save to run-state for resume capability (even if post's blocks weren't updated, it has still been processed).
			$this->run_state->append_updated_block_post(
				[
					'id_old' => $id_old,
					'id_new' => $id_new,
				]
			);
		}
		if ( $progress->finish() ) {
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( 100 ) );
		}
	}

	/**
	 * Recalculates term counts for all migrated taxonomies.
	 *
	 * @param array $taxonomies_to_migrate Taxonomies to migrate.
	 */
	private function recalculate_term_counts( array $taxonomies_to_migrate ): void {
		foreach ( $taxonomies_to_migrate as $taxonomy ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				] 
			);
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_update_term_count_now( $terms, $taxonomy );
			}
		}
	}
}
