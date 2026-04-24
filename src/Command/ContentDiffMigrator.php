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
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\DB;
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
	 * Default post types to migrate. Can be changed with --post-types-csv argument.
	 *
	 * @var array
	 */
	const DEFAULT_POST_TYPES = [ 'post', 'page', 'attachment', 'wp_block' ];

	/**
	 * Default taxonomies to migrate. Can be changed with --custom-taxonomies-csv argument.
	 *
	 * @var array
	 */
	const DEFAULT_TAXONOMIES = [ 'category', 'post_tag', 'author', 'brand' ];

	/**
	 * Number of post objects processed in a batch which is memory-safe for large datasets.
	 *
	 * @var int
	 */
	const MEMORY_SAFE_BATCH_SIZE = 10000;

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
	 * Command definitions by category.
	 * Single source of truth for both registration and interactive index.
	 *
	 * @var array
	 */
	private const COMMANDS = [
		'main'    => [
			'search-new-content-on-live' => [
				'method'    => 'cmd_search_new_content_on_live',
				'shortdesc' => 'Searches for new posts existing in the Live site tables and not in the local site tables, and exports the IDs to a file.',
				'longdesc'  => '',
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
						'description' => 'Source hostname (e.g. www.example.com). Used to namespace old ID metadata.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types-csv',
						'description' => 'CSV of all the post types to scan, no extra spaces. E.g. --post-types-csv=post,page,attachment,wp_block,guest-author,custom_cpt1. Note: For CoAuthors Plus Guest Authors support, include guest-author CPT, and in the migrate command make sure author taxonomy is migrated (author taxonomy is already a default value in --custom-taxonomies-csv). Defaults are defined by the constant DEFAULT_POST_TYPES.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'custom-taxonomies-csv',
						'description' => 'CSV of taxonomies to match during auto-attribution. This affects which terms get attributed during the automatic matching phase. Note: Terms are matched by name+taxonomy+parent during import regardless of attribution, so unattributed terms from taxonomies not listed here will still be handled correctly during migrate. Defaults are defined by the constant DEFAULT_TAXONOMIES.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			],
			'migrate-live-content'       => [
				'method'    => 'cmd_migrate_live_content',
				'shortdesc' => 'Migrates content from Live site tables to local site tables.',
				'longdesc'  => '',
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
						'description' => 'Source hostname (e.g. www.example.com).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'custom-taxonomies-csv',
						'description' => 'CSV of all the taxonomies to import. If you are adding custom taxonomies, make sure to include default WP taxonomies, e.g. --custom-taxonomies-csv=category,post_tag,author,brand,custom_taxonomy. Defaults are defined by the constant DEFAULT_TAXONOMIES.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			],
		],
		'utility' => [
			'list-previously-migrated-source-hostnames' => [
				'method'    => 'cmd_list_migrated_source_hostnames',
				'shortdesc' => 'Lists all source hostnames from which content has been imported.',
				'longdesc'  => '',
				'synopsis'  => [],
			],
			'attribute-ids'                             => [
				'method'    => 'cmd_attribute_ids',
				'shortdesc' => 'Attribute specific content by ID to source hostname. Takes specific IDs of `posts` (and all CPTs), `users`, and/or `terms` and attributes just those objects to a source hostname. **Use case**: Some different custom migration tool was used in parallel on same content for some reason, and then you also wish to also run CDiff on that content. Use this command to first assign the existing/unattributed content the "old ID and hostname metas", so that CDiff does not create duplicates. This is very much an edge case, made for such special ops.',
				'longdesc'  => "Attributes specific posts, attachments, users, or terms (by ID) to the source hostname. Provide IDs via comma-separated values or files (one ID per line).\n"
								. 'USE CASE: Targeted attribution for specific records.\n'
								. "DATA TYPES WHICH CAN BE ATTRIBUTED WITH THEIR OWN METAS:\n"
								. "  - Posts/Pages/CPTs (wp_postmeta)\n"
								. "  - Attachments (wp_postmeta)\n"
								. "  - Users (wp_usermeta)\n"
								. '  - Terms (wp_termmeta)',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'source-hostname',
						'description' => 'Source hostname (e.g. www.example.com).',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'data-dir',
						'description' => 'Data directory for logs and reports.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'Path to JSONL file with ID pairs, one pair of IDs per each line, e.g.: {"old_id": 100, "local_id": 200}',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'attachment-ids',
						'description' => 'Path to JSONL file with ID pairs, one pair of IDs per each line, e.g.: {"old_id": 100, "local_id": 200}',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'user-ids',
						'description' => 'Path to JSONL file with ID pairs, one pair of IDs per each line, e.g.: {"old_id": 100, "local_id": 200}',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'term-ids',
						'description' => 'Path to JSONL file with ID pairs, one pair of IDs per each line, e.g.: {"old_id": 100, "local_id": 200}',
						'optional'    => true,
					],
				],
			],
			'display-collations-comparison'             => [
				'method'    => 'cmd_compare_collations_of_live_and_core_wp_tables',
				'shortdesc' => 'Display a table comparing collations of Live and Core WP tables that differ.',
				'longdesc'  => '',
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
				],
			],
			'correct-collations-for-live-wp-tables'     => [
				'method'    => 'cmd_correct_collations_for_live_wp_tables',
				'shortdesc' => 'This command will handle the necessary operations to match collations across Live and Core WP tables. Speed is auto-determined based on total table size.',
				'longdesc'  => '',
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
			],
		],
	];

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
		foreach ( self::COMMANDS as $category => $commands ) {
			foreach ( $commands as $cmd_name => $cmd_config ) {
				WP_CLI::add_command(
					'newspack-content-diff-migrator ' . $cmd_name,
					[ __CLASS__, $cmd_config['method'] ],
					[
						'shortdesc' => $cmd_config['shortdesc'],
						'longdesc'  => $cmd_config['longdesc'] ?? '',
						'synopsis'  => $cmd_config['synopsis'] ?? [],
					]
				);
			}
		}
	}

	/**
	 * Get command definitions for interactive index.
	 *
	 * @return array Command definitions organized by category.
	 */
	public static function get_commands(): array {
		return self::COMMANDS;
	}

	/**
	 * Callable for `newspack-content-diff-migrator search-new-content-on-live`.
	 *
	 * @param array $pos_args   Positional CLI args.
	 * @param array $assoc_args Associative CLI args.
	 * 
	 * @throws \Exception If error occurs.
	 */
	public function cmd_search_new_content_on_live( array $pos_args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		$data_dir          = $assoc_args['data-dir'] ?? false;
		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;
		$source_hostname   = $assoc_args['source-hostname'] ?? false;
		$post_types        = isset( $assoc_args['post-types-csv'] ) ? explode( ',', $assoc_args['post-types-csv'] ) : self::DEFAULT_POST_TYPES;
		$taxonomies        = isset( $assoc_args['custom-taxonomies-csv'] ) ? explode( ',', $assoc_args['custom-taxonomies-csv'] ) : self::DEFAULT_TAXONOMIES;
		
		// Set instance properties.
		global $wpdb;
		// Only create RunState if not already injected (integration tests inject their own testable RunState).
		if ( null === $this->run_state ) {
			$this->run_state = new RunState( rtrim( $data_dir, '/' ) . '/run-state' );
		}

		// Init logger.
		Logger::instance()->init( rtrim( $data_dir, '/' ) . '/' . __FUNCTION__ . '.log' );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, 'Starting command content-diff-search-new-content-on-live...' );

		// Check if the run-state and a manifest exist from a previous interrupted command run and handle.
		$existing_manifest = $this->run_state->get_manifest();
		if ( ! $this->test_env && $existing_manifest ) {
			$existing_source = $existing_manifest['source_hostname'] ?? 'unknown';
			$existing_date   = $existing_manifest['created_at'] ?? 'unknown';
			$search_status   = $existing_manifest['search_status'] ?? null;

			// Case 1: Different hostname - always refuse to proceed.
			if ( $existing_source !== $source_hostname ) {
				Logger::instance()->log(
					Logger::OUTPUT_BOTH,
					LogLevel::ERROR,
					sprintf( 'This --data-dir contains run-state from a DIFFERENT source hostname (%s, created %s). Please use a new --data-dir.', $existing_source, $existing_date ) 
				);
				return;
			}

			// Case 2: Search already completed - nothing left to do.
			if ( RunState::STATUS_COMPLETED === $search_status ) {
				Logger::instance()->log(
					Logger::OUTPUT_BOTH,
					LogLevel::INFO,
					sprintf( 'Search already completed for this --data-dir (created %s). Nothing left to do.', $existing_date ) 
				);
				return;
			}

			// Case 3: Same hostname, search not completed - allow re-run, begin by deleting previous partial state to start fresh.
			Logger::instance()->log(
				Logger::OUTPUT_BOTH,
				LogLevel::DEBUG,
				sprintf( 'Incomplete search detected (created %s). Deleting previous run-state and re-running...', $existing_date )
			);
			$this->run_state->delete_run_state();
		}

		// Write initial manifest with "started" status.
		$this->run_state->write_manifest(
			[
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
				'source_hostname'   => $source_hostname,
				'search_status'     => RunState::STATUS_STARTED,
				'live_table_prefix' => $live_table_prefix,
				'post_types'        => $post_types,
				// --custom-taxonomies-csv are used for auto-attribution in the search command. The migrate command uses --custom-taxonomies-csv to actually migrate defined taxonomies, which is where the "taxonomies" field will be recorded.
			]
		);

		try {
			$this->db->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, $e->getMessage() . " About to run `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --skip-tables=options` ..." );
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
		// Prepare and validate table name.
		$table_live_posts = $live_table_prefix . 'posts';
		DB::validate_table_name( $table_live_posts );
		$cpts_live = $wpdb->get_col( "SELECT DISTINCT( post_type ) FROM {$table_live_posts} ;" ); // phpcs:ignore -- table name was properly validated.

		// Validate selected CPTs and remove invalid ones.
		$post_types = array_values(
			array_filter(
				$post_types,
				function ( $v ) use ( $cpts_live ) {
					if ( ! in_array( $v, $cpts_live, true ) ) {
						Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'The selected Post Type `%s` is not found in live DB and will not be migrated.', $v ) );
						return false;
					}
					return true;
				}
			)
		);

		// Notify which CPTs are being migrated.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Proceeding to migrate Post Types: %s', implode( ', ', $post_types ) ) );

		// Show remaining post types found in live DB that won't be migrated.
		$unmigrated_post_types = array_diff( $cpts_live, $post_types );
		if ( ! empty( $unmigrated_post_types ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Other Post Types found in live DB which will not be migrated: %s', implode( ', ', $unmigrated_post_types ) ) );
		}

		// Check for unattributed content and automatically attribute it by matching local content to live tables and assigning metas.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for unattributed content...' );
		$unattributed = $this->check_unattributed_content( $post_types );

		if ( $unattributed['count'] > 0 ) {
			// Auto-match unattributed content to live tables and attribute matches.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'There are %d total objects on local without `%s*` metas. Trying to automatically attribute this content by matching it with live tables...', $unattributed['count'], ContentDiffLogic::SAVED_META_LIVE_ID_PREFIX ) );
			$attribution_counts = $this->do_attribution_match_to_live_tables(
				$live_table_prefix,
				$source_hostname,
				$post_types,
				$taxonomies
			);

			$total_attributed = array_sum( $attribution_counts );
			Logger::instance()->log(
				Logger::OUTPUT_BOTH,
				LogLevel::INFO,
				sprintf(
					'Auto-attributed %d objects: %d posts, %d attachments, %d users, %d terms.',
					$total_attributed,
					$attribution_counts['posts'],
					$attribution_counts['attachments'],
					$attribution_counts['users'],
					$attribution_counts['terms']
				)
			);

			// Check for remaining unattributed content.
			$remaining = $this->check_unattributed_content( $post_types );
			if ( $remaining['count'] > 0 ) {
				Logger::instance()->log(
					Logger::OUTPUT_BOTH,
					LogLevel::DEBUG,
					sprintf(
						'There are %d original objects on staging/local without `%s*` metas, see %s for their IDs.',
						$remaining['count'],
						ContentDiffLogic::SAVED_META_LIVE_ID_PREFIX,
						$remaining['file_path']
					)
				);
			}
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
				$term_old_id_map,
				$taxonomies
			);
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d modified IDs found (see %s).', count( $modified_live_ids ), rtrim( $data_dir, '/' ) . '/run-state/' . RunState::FILE_MODIFIED_IDS ) );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Query live DB for attachments in batches.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Searching live DB for attachments ...' );
			$batch_size              = self::MEMORY_SAFE_BATCH_SIZE;
			$total_live_attachments  = $this->logic->count_posts_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );
			$new_live_attachment_ids = [];
			$total_batches           = (int) ceil( $total_live_attachments / $batch_size );
			$current_batch           = 0;
			for ( $offset = 0; $offset < $total_live_attachments; $offset += $batch_size ) {
				++$current_batch;
				$live_batch = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], [ 'inherit' ], $batch_size, $offset );
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, sprintf( 'Batch %d/%d...', $current_batch, $total_batches ) );
				// More verbose log for action/debug log file.
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::DEBUG,
					sprintf( 'Processing live attachments batch: offset=%d, limit=%d, total=%d, fetched=%d', $offset, $batch_size, $total_live_attachments, count( $live_batch ) )
				);
				$new_ids_batch           = $this->logic->filter_new_live_ids( $live_batch, $attachment_old_id_map );
				$new_live_attachment_ids = array_merge( $new_live_attachment_ids, $new_ids_batch );
				unset( $live_batch, $new_ids_batch );
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			}
			$new_live_ids = array_merge( $new_live_ids, $new_live_attachment_ids );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d new attachment IDs found (see %s).', count( $new_live_attachment_ids ), rtrim( $data_dir, '/' ) . '/run-state/' . RunState::FILE_NEW_IDS ) );
			unset( $new_live_attachment_ids );

		} catch ( \Exception $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $e->getMessage() );
			throw $e;
		}

		// Write new IDs to run-state file -- even if there are no new IDs, write the empty list -- migrate command will continue to allow other data to be migrated (users, modified IDs, etc.).
		$this->run_state->write_new_ids( $new_live_ids );
		if ( count( $new_live_ids ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'List of new IDs to migrate stored to run-state file %s', RunState::FILE_NEW_IDS ) );
		}

		// Write modified IDs to run-state file.
		$this->run_state->write_modified_ids( $modified_live_ids );
		if ( count( $modified_live_ids ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'List of modified IDs to reimport stored to run-state file %s', RunState::FILE_MODIFIED_IDS ) );
		}

		// Update manifest with completed status and counts.
		$manifest                  = $this->run_state->get_manifest() ?? [];
		$manifest['search_status'] = RunState::STATUS_COMPLETED;
		$manifest['counts']        = [
			'new_ids'      => count( $new_live_ids ),
			'modified_ids' => count( $modified_live_ids ),
		];
		$this->run_state->write_manifest( $manifest );

		// Display info about available logs.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Full logs were saved to %s:', rtrim( (string) $data_dir, '/' ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '- debug/action log: %s', basename( Logger::instance()->get_log_file_path() ?? '' ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '- run-state manifest: %s', RunState::FILE_MANIFEST ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All done searching for new content on live 🙌  Proceed to run the `migrate-live-content` command 🚀' );
	}

	/**
	 * Callable for `newspack-content-diff-migrator migrate-live-content`.
	 *
	 * @param array $pos_args   Positional CLI args.
	 * @param array $assoc_args CLI assoc args.
	 * 
	 * @throws \RuntimeException If run-state file not found or empty.
	 */
	public function cmd_migrate_live_content( array $pos_args, array $assoc_args ): void {
		global $wpdb;

		$data_dir              = $assoc_args['data-dir'] ?? false;
		$live_table_prefix     = $assoc_args['live-table-prefix'] ?? false;
		$source_hostname       = $assoc_args['source-hostname'] ?? false;
		$taxonomies_to_migrate = isset( $assoc_args['custom-taxonomies-csv'] ) ? explode( ',', $assoc_args['custom-taxonomies-csv'] ) : self::DEFAULT_TAXONOMIES;
		
		// Init logger.
		Logger::instance()->init( rtrim( $data_dir, '/' ) . '/' . __FUNCTION__ . '.log' );
		Logger::instance()->log(
			Logger::OUTPUT_FILE,
			LogLevel::INFO,
			sprintf( 'Starting %s', __FUNCTION__ ),
			[
				'pos_args'   => $pos_args,
				'assoc_args' => $assoc_args,
			] 
		);

		// Set instance properties.
		$this->live_table_prefix = $live_table_prefix;
		if ( null === $this->run_state ) {
			$this->run_state = new RunState( rtrim( $data_dir, '/' ) . '/run-state' );
		}
		// Set RunState on DataImporter for tracking users/terms.
		$this->data_importer->set_run_state( $this->run_state );

		// Read manifest (written by search command).
		$manifest = $this->run_state->get_manifest();
		if ( is_null( $manifest ) ) {
			throw new \RuntimeException( sprintf( 'Can not find manifest file (%s). Run search-new-content-on-live first.', RunState::FILE_MANIFEST ) ); // phpcs:ignore -- exception message is for internal logging/debugging WordPress.Security.EscapeOutput.ExceptionNotEscaped.
		}

		// Check manifest for hostname mismatch or already completed status.
		if ( ! $this->test_env ) {
			$existing_source = $manifest['source_hostname'] ?? 'unknown';
			$existing_date   = $manifest['created_at'] ?? 'unknown';
			$migrate_status  = $manifest['migrate_status'] ?? null;

			// Case 1: Different hostname - always refuse to proceed.
			if ( $existing_source !== $source_hostname ) {
				Logger::instance()->log(
					Logger::OUTPUT_BOTH,
					LogLevel::ERROR,
					sprintf( 'This --data-dir contains run-state from a DIFFERENT source hostname (%s, created %s). Please use a new --data-dir.', $existing_source, $existing_date )
				);
				return;
			}

			// Case 2: Migrate already completed - nothing left to do.
			if ( RunState::STATUS_COMPLETED === $migrate_status ) {
				Logger::instance()->log(
					Logger::OUTPUT_BOTH,
					LogLevel::INFO,
					sprintf( 'Migration already completed for this --data-dir (created %s). Nothing left to do.', $existing_date )
				);
				return;
			}
		}

		// Set migrate_status to "started".
		$manifest['migrate_status'] = RunState::STATUS_STARTED;
		$this->run_state->write_manifest( $manifest );

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

		// Get all taxonomies which exist in Live DB.
		$table_live_term_taxonomy = $live_table_prefix . 'term_taxonomy';
		DB::validate_table_name( $table_live_term_taxonomy );
		$live_taxonomies = $wpdb->get_col( "SELECT DISTINCT( taxonomy ) FROM {$table_live_term_taxonomy} ;" ); // phpcs:ignore -- table name was properly validated.

		// Validate hierarchical taxonomies have valid parents. If they don't they should be fixed first.
		$taxonomies_to_migrate = $this->validate_and_fix_hierarchical_taxonomies( $taxonomies_to_migrate, $live_taxonomies );
		if ( ! empty( $taxonomies_to_migrate ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Proceeding to migrate Taxonomies: %s', implode( ', ', $taxonomies_to_migrate ) ) );
		} else {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, 'No taxonomies to migrate found. Proceeding with migration to allow for edge cases, however please double-check whether this was intended.' );
		}

		// Show remaining taxonomies found in live DB that won't be migrated.
		$unmigrated_taxonomies = array_diff( $live_taxonomies, $taxonomies_to_migrate );
		if ( ! empty( $unmigrated_taxonomies ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Other Taxonomies found in live DB which will not be migrated: %s', implode( ', ', $unmigrated_taxonomies ) ) );
		}

		// Register taxonomies (before deletion and reimport of modified, so wp_delete_post() properly cleans up term relationships).
		foreach ( $taxonomies_to_migrate as $taxonomy_name ) {
			$this->data_importer->ensure_taxonomy_registered( $taxonomy_name );
		}

		// Migrate all WP_Users (for WooComm data).
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Migrating all WP_Users...' );
		$inserted_wp_users_updates = $this->logic->migrate_all_users( $live_table_prefix, $source_hostname );
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, sprintf( 'Inserted %d WP_Users.', count( $inserted_wp_users_updates ) ) );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Get new IDs which will be migrated.
		$new_live_ids = $this->run_state->get_new_ids();
		if ( null === $new_live_ids ) {
			$message = sprintf( 'Run-state file %s not found or empty.', RunState::FILE_NEW_IDS );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, $message );
			throw new \RuntimeException( $message ); // phpcs:ignore -- exception message is for internal logging/debugging WordPress.Security.EscapeOutput.ExceptionNotEscaped.
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
			$already_deleted_count            = count( $already_deleted_modified_ids_map );
			if ( $already_deleted_count > 0 ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d posts were already deleted, continuing from there...', $already_deleted_count ) );
			}
			
			// Batch delete modified posts for speed, so they can be re-imported.
			$batch_size        = 500;
			$offset            = 0;
			$total_to_delete   = count( $local_ids_to_delete );
			$failed_delete_ids = [];
			while ( $offset < $total_to_delete ) {
				$batch        = array_slice( $local_ids_to_delete, $offset, $batch_size );
				$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

				// Using single table DELETE IGNORE to ensure that individual row failures don't prevent other rows in the batch from being deleted.
				// Delete revisions' metas and term_relationships (subquery finds revision IDs), then revisions.
				// phpcs:disable -- $placeholders is safely constructedWordPress.DB.PreparedSQL.InterpolatedNotPrepared.
				$wpdb->query( $wpdb->prepare( "DELETE IGNORE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($placeholders) AND post_type = 'revision')", $batch ) );
				$wpdb->query( $wpdb->prepare( "DELETE IGNORE FROM {$wpdb->term_relationships} WHERE object_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($placeholders) AND post_type = 'revision')", $batch ) );
				$wpdb->query( $wpdb->prepare( "DELETE IGNORE FROM {$wpdb->posts} WHERE post_parent IN ($placeholders) AND post_type = 'revision'", $batch ) );
				// phpcs:enable
				
				// Delete commentmeta (subquery finds comment IDs), comments.
				// phpcs:disable -- $placeholders is safely constructedWordPress.DB.PreparedSQL.InterpolatedNotPrepared.
				$wpdb->query( $wpdb->prepare( "DELETE IGNORE FROM {$wpdb->commentmeta} WHERE comment_id IN (SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders))", $batch ) );
				$wpdb->query( $wpdb->prepare( "DELETE IGNORE FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders)", $batch ) );
				// phpcs:enable

				// Delete postmeta, term_relationships, posts.
				// phpcs:disable -- $placeholders is safely constructedWordPress.DB.PreparedSQL.InterpolatedNotPrepared.
				$wpdb->query( $wpdb->prepare( "DELETE IGNORE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)", $batch ) );
				$wpdb->query( $wpdb->prepare( "DELETE IGNORE FROM {$wpdb->term_relationships} WHERE object_id IN ($placeholders)", $batch ) );
				$wpdb->query( $wpdb->prepare( "DELETE IGNORE FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $batch ) );
				// phpcs:enable

				// Check if some IDs failed to be deleted and track for exclusion from reimport.
				$still_exist = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $batch ) ); // phpcs:ignore -- $placeholders is safely constructedWordPress.DB.PreparedSQL.InterpolatedNotPrepared.
				foreach ( $still_exist as $failed_id ) {
					$failed_delete_ids[] = (int) $failed_id;
					Logger::instance()->log_brief_and_verbose(
						LogLevel::ERROR,
						sprintf( 'Failed to delete modified post local ID %d, this post will not be updated/reimported.', $failed_id ),
						[
							'local_id' => $failed_id,
							'live_id'  => array_search( $failed_id, $modified_ids_map ),
						]
					);
				}

				// Save run-state for successfully deleted IDs.
				foreach ( array_diff( $batch, $still_exist ) as $deleted_id ) {
					$this->run_state->append_deleted_modified_id(
						[
							'live_id'  => array_search( $deleted_id, $modified_ids_map ),
							'local_id' => $deleted_id,
						]
					);
				}
				
				// Update offset for next batch.
				$offset += $batch_size;

				// One cache flush per batch.
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			}

			// Merge only successfully deleted modified posts for reimport (exclude failed).
			$live_ids_to_reimport = [];
			foreach ( $modified_ids_map as $live_id => $local_id ) {
				if ( ! in_array( $local_id, $failed_delete_ids, true ) ) {
					$live_ids_to_reimport[] = $live_id;
				}
			}
			$new_live_ids = array_merge( $new_live_ids, $live_ids_to_reimport );
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
		$imported_wp_block_ids_map      = $this->logic->filter_imported_wp_blocks( $all_imported_ids );
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
		$this->update_attachment_ids_in_blocks( $imported_posts_data, $source_hostname, $imported_nonattachment_ids_map, $imported_attachment_ids_map, $imported_wp_block_ids_map );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Recalculate counts for all migrated taxonomies.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating term counts...' );
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

		// Display info about available logs.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'Full logs are saved to %s:', rtrim( (string) $data_dir, '/' ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '- debug/action log (check for ERRORs or WARNINGs): %s', basename( Logger::instance()->get_log_file_path() ?? '' ) ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '- run-state manifest: %s', RunState::FILE_MANIFEST ) );

		// Generate CSV reports from run-state JSONL files, and output list.
		$reports_dir    = rtrim( (string) $data_dir, '/' ) . '/reports';
		$report_creator = new ReportCreator( $this->run_state );
		$report_creator->create_all_csvs( $reports_dir );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( "CSV Reports are saved to %s:\n- %s\n- %s\n- %s", $reports_dir, ReportCreator::REPORT_POSTS, ReportCreator::REPORT_USERS, ReportCreator::REPORT_TERMS ) );

		// Output migration summary.
		$summary = $this->run_state->get_migration_summary();
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'Migration summary:' );
		foreach ( $summary['posts'] as $post_type => $count ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '- total %s: %s', $post_type, number_format( $count ) ) );
		}
		if ( ! empty( $summary['users']['imported'] ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '- total new users: %s', number_format( $summary['users']['imported'] ) ) );
		}
		if ( ! empty( $summary['users']['merged'] ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '- total merged users: %s', number_format( $summary['users']['merged'] ) ) );
		}

		// Mark migrate as completed and record taxonomies.
		$manifest                   = $this->run_state->get_manifest() ?? [];
		$manifest['taxonomies']     = $taxonomies_to_migrate;
		$manifest['migrate_status'] = RunState::STATUS_COMPLETED;
		$this->run_state->write_manifest( $manifest );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'All done migrating content from %s! 🙌 ', $source_hostname ) );
		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-diff-migrator list-previously-migrated-source-hostnames`.
	 *
	 * Lists all source hostnames from which content has been imported.
	 *
	 * @param array $pos_args   Positional CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_list_migrated_source_hostnames( array $pos_args, array $assoc_args ): void {
		Logger::instance()->init( __FUNCTION__ . '.log' );
		Logger::instance()->log(
			Logger::OUTPUT_FILE,
			LogLevel::INFO,
			sprintf( 'Starting %s', __FUNCTION__ ),
			[
				'pos_args'   => $pos_args,
				'assoc_args' => $assoc_args,
			] 
		);

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
	 * Callable for `newspack-content-diff-migrator attribute-ids`.
	 *
	 * Attributes specific content by ID to source hostname.
	 *
	 * @param array $pos_args   Positional CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_attribute_ids( array $pos_args, array $assoc_args ): void {
		$source_hostname = $assoc_args['source-hostname'] ?? false;
		$data_dir        = $assoc_args['data-dir'] ?? false;

		// Get ID pairs from JSONL files.
		$post_pairs       = isset( $assoc_args['post-ids'] ) ? $this->parse_jsonl_id_pairs( $assoc_args['post-ids'] ) : [];
		$attachment_pairs = isset( $assoc_args['attachment-ids'] ) ? $this->parse_jsonl_id_pairs( $assoc_args['attachment-ids'] ) : [];
		$user_pairs       = isset( $assoc_args['user-ids'] ) ? $this->parse_jsonl_id_pairs( $assoc_args['user-ids'] ) : [];
		$term_pairs       = isset( $assoc_args['term-ids'] ) ? $this->parse_jsonl_id_pairs( $assoc_args['term-ids'] ) : [];

		// Validate at least one ID pair argument provided.
		if ( empty( $post_pairs ) && empty( $attachment_pairs ) && empty( $user_pairs ) && empty( $term_pairs ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, 'At least one argument with ID pairs to attribute is required.' );
			return;
		}

		// Init logger.
		Logger::instance()->init( $data_dir . '/' . __FUNCTION__ . '.log' );
		Logger::instance()->log(
			Logger::OUTPUT_FILE,
			LogLevel::INFO,
			sprintf( 'Starting %s', __FUNCTION__ ),
			[
				'pos_args'   => $pos_args,
				'assoc_args' => $assoc_args,
			] 
		);

		// Variables.
		global $wpdb;
		$meta_key        = $this->logic->get_old_id_meta_key( $source_hostname );
		$timestamp       = gmdate( 'Ymd_His' );
		$attributed_data = [
			'posts' => [],
			'users' => [],
			'terms' => [],
		];

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Attributing IDs to %s...', $source_hostname ) );

		// Attribute posts.
		$posts_attributed_count = 0;
		if ( ! empty( $post_pairs ) ) {
			$local_ids        = array_column( $post_pairs, 'local_id' );
			$ids_placeholders = implode( ',', array_fill( 0, count( $local_ids ), '%d' ) );
			// phpcs:disable -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare.
			$existing_posts_results = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ( {$ids_placeholders} )", $local_ids ), ARRAY_A );
			// phpcs:enable
			$existing_posts = array_column( $existing_posts_results, 'post_type', 'ID' );
			
			// Fetch already-attributed post IDs.
			$already_attributed_posts     = $this->logic->get_attributed_post_ids( $source_hostname, $local_ids );
			$already_attributed_posts_map = array_flip( $already_attributed_posts );
			
			foreach ( $post_pairs as $key => $pair ) {
				$local_id = $pair['local_id'];
				$old_id   = $pair['old_id'];
				
				if ( ! isset( $existing_posts[ $local_id ] ) ) {
					Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Post local_id %d not found in database, skipping attribution.', $local_id ) );
					continue;
				}
				// Skip if already attributed.
				if ( isset( $already_attributed_posts_map[ $local_id ] ) ) {
					continue;
				}
				
				update_post_meta( $local_id, $meta_key, $old_id );
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key, 1000 );
				
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::DEBUG,
					sprintf( 'Post attributed to %s', $source_hostname ),
					[
						'old_id'   => $old_id,
						'local_id' => $local_id,
					] 
				);
				$attributed_data['posts'][] = [
					'live_id'   => $old_id,
					'local_id'  => $local_id,
					'post_type' => $existing_posts[ $local_id ],
				];
				++$posts_attributed_count;
			}
		}
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d posts attributed.', $posts_attributed_count ) );

		// Attribute attachments.
		$attachments_attributed_count = 0;
		if ( ! empty( $attachment_pairs ) ) {
			$local_ids        = array_column( $attachment_pairs, 'local_id' );
			$ids_placeholders = implode( ',', array_fill( 0, count( $local_ids ), '%d' ) );
			// phpcs:disable -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare.
			$existing_attachments_results = $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ( {$ids_placeholders} ) AND post_type = 'attachment'", $local_ids ), ARRAY_A );
			// phpcs:enable
			// Re-index as flat array for isset checks.
			$existing_attachments = array_column( $existing_attachments_results, 'ID', 'ID' );
			
			// Fetch already-attributed attachment IDs.
			$already_attributed_attachments     = $this->logic->get_attributed_attachment_ids( $source_hostname, $local_ids );
			$already_attributed_attachments_map = array_flip( $already_attributed_attachments );
			
			foreach ( $attachment_pairs as $key => $pair ) {
				$local_id = $pair['local_id'];
				$old_id   = $pair['old_id'];
				
				if ( ! isset( $existing_attachments[ $local_id ] ) ) {
					Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Attachment local_id %d not found in database or is not an attachment, skipping attribution.', $local_id ) );
					continue;
				}
				// Skip if already attributed.
				if ( isset( $already_attributed_attachments_map[ $local_id ] ) ) {
					continue;
				}
				
				update_post_meta( $local_id, $meta_key, $old_id );
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key, 1000 );
				
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::DEBUG,
					sprintf( 'Attachment attributed to %s', $source_hostname ),
					[
						'old_id'   => $old_id,
						'local_id' => $local_id,
					] 
				);
				$attributed_data['posts'][] = [
					'live_id'   => $old_id,
					'local_id'  => $local_id,
					'post_type' => 'attachment',
				];
				++$attachments_attributed_count;
			}
		}
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d attachments attributed.', $attachments_attributed_count ) );

		// Attribute users.
		$users_attributed_count = 0;
		if ( ! empty( $user_pairs ) ) {
			$local_ids        = array_column( $user_pairs, 'local_id' );
			$ids_placeholders = implode( ',', array_fill( 0, count( $local_ids ), '%d' ) );
			// phpcs:disable -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare.
			$existing_users_results = $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID IN ( {$ids_placeholders} )", $local_ids ), ARRAY_A );
			// phpcs:enable
			// Re-index as flat array for isset checks.
			$existing_users = array_column( $existing_users_results, 'ID', 'ID' );
			
			// Fetch already-attributed user IDs.
			$already_attributed_users     = $this->logic->get_attributed_user_ids( $source_hostname, $local_ids );
			$already_attributed_users_map = array_flip( $already_attributed_users );
			
			foreach ( $user_pairs as $key => $pair ) {
				$local_id = $pair['local_id'];
				$old_id   = $pair['old_id'];
				
				if ( ! isset( $existing_users[ $local_id ] ) ) {
					Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'User local_id %d not found in database, skipping attribution.', $local_id ) );
					continue;
				}
				// Skip if already attributed.
				if ( isset( $already_attributed_users_map[ $local_id ] ) ) {
					continue;
				}
				
				update_user_meta( $local_id, $meta_key, $old_id );
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key, 1000 );
				
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::DEBUG,
					sprintf( 'User attributed to %s', $source_hostname ),
					[
						'old_id'   => $old_id,
						'local_id' => $local_id,
					] 
				);
				$attributed_data['users'][] = [
					'live_id'  => $old_id,
					'local_id' => $local_id,
				];
				++$users_attributed_count;
			}
		}
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d users attributed.', $users_attributed_count ) );

		// Attribute terms.
		$terms_attributed_count = 0;
		if ( ! empty( $term_pairs ) ) {
			$local_ids        = array_column( $term_pairs, 'local_id' );
			$ids_placeholders = implode( ',', array_fill( 0, count( $local_ids ), '%d' ) );
			// phpcs:disable -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare.
			$existing_terms_results = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, tt.taxonomy  FROM {$wpdb->terms} t  INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id  WHERE t.term_id IN ( {$ids_placeholders} )", $local_ids ), ARRAY_A );
			// phpcs:enable
			// Re-index by term_id for quick lookup.
			$existing_terms = array_column( $existing_terms_results, 'taxonomy', 'term_id' );
			
			// Fetch already-attributed term IDs.
			$already_attributed_terms     = $this->logic->get_attributed_term_ids( $source_hostname, $local_ids );
			$already_attributed_terms_map = array_flip( $already_attributed_terms );
			
			foreach ( $term_pairs as $key => $pair ) {
				$local_id = $pair['local_id'];
				$old_id   = $pair['old_id'];
				
				if ( ! isset( $existing_terms[ $local_id ] ) ) {
					Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Term local_id %d not found in database, skipping attribution.', $local_id ) );
					continue;
				}
				// Skip if already attributed.
				if ( isset( $already_attributed_terms_map[ $local_id ] ) ) {
					continue;
				}
				
				update_term_meta( $local_id, $meta_key, $old_id );
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key, 1000 );
				
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::DEBUG,
					sprintf( 'Term attributed to %s', $source_hostname ),
					[
						'old_id'   => $old_id,
						'local_id' => $local_id,
					] 
				);
				$attributed_data['terms'][] = [
					'live_id'  => $old_id,
					'local_id' => $local_id,
					'taxonomy' => $existing_terms[ $local_id ],
				];
				++$terms_attributed_count;
			}
		}
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d terms attributed.', $terms_attributed_count ) );

		// Generate timestamped CSV reports.
		$reports_dir     = rtrim( $data_dir, '/' ) . '/reports';
		$this->run_state = new RunState( $data_dir );
		$report_creator  = new ReportCreator( $this->run_state );
		$created_files   = $report_creator->create_attributed_csvs( $reports_dir, $attributed_data, $source_hostname, $timestamp );

		// Re-count and display remaining unattributed content.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for remaining unattributed content...' );
		$remaining = $this->check_unattributed_content( self::DEFAULT_POST_TYPES );
		if ( $remaining['count'] > 0 ) {
			Logger::instance()->log(
				Logger::OUTPUT_BOTH,
				LogLevel::DEBUG,
				sprintf(
					'There are %d original objects on staging/local without `%s*` metas, see %s for their IDs.',
					$remaining['count'],
					ContentDiffLogic::SAVED_META_LIVE_ID_PREFIX,
					$remaining['file_path']
				)
			);
		}

		// Display summary.
		$this->attribute_display_summary( $reports_dir, $timestamp, $created_files );
		
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'All done attributing content to %s! 🙌', $source_hostname ) );
	}

	/**
	 * This function will display a table comparing the collations of Live and Core WP tables.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_compare_collations_of_live_and_core_wp_tables( array $pos_args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		$live_table_prefix = $assoc_args['live-table-prefix'];
		$skip_tables       = ! empty( $assoc_args['skip-tables'] ) ? explode( ',', $assoc_args['skip-tables'] ) : [];

		Logger::instance()->init( __FUNCTION__ . '.log' );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, 'Starting command compare-collations-of-live-and-core-wp-tables...' );

		$tables = $this->db->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );
		
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
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_correct_collations_for_live_wp_tables( array $pos_args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
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
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'The selected Taxonomy `%s` is not found in live DB and will not be migrated.', $taxonomy_to_migrate ) );
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
	 * Checks for unattributed content (posts/CPTs, attachments, users, terms without old_id meta).
	 * If any found, writes them to a JSONL file in run-state.
	 *
	 * @param array $post_types Post types to check for unattributed content.
	 * @return array{count: int, file_path: string|null} Count and file path (null if count is 0).
	 */
	private function check_unattributed_content( array $post_types ): array {
		$post_types_without_attachments = array_filter( $post_types, fn( $pt ) => 'attachment' !== $pt );
		$posts                          = $this->logic->get_unattributed_post_ids( $post_types_without_attachments );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		$attachment_ids = in_array( 'attachment', $post_types, true )
			? $this->logic->get_unattributed_attachment_ids()
			: [];
		$user_ids       = $this->logic->get_unattributed_user_ids();
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		$term_ids = $this->logic->get_unattributed_term_ids();
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		$total     = count( $posts ) + count( $attachment_ids ) + count( $user_ids ) + count( $term_ids );
		$file_path = null;
		if ( $total > 0 ) {
			// Save all unattributed IDs to a JSONL file.
			$file_path = $this->run_state->write_unattributed_content(
				$posts,
				$attachment_ids,
				$user_ids,
				$term_ids
			);
		}

		return [
			'count'     => $total,
			'file_path' => $file_path,
		];
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
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key_live_id, 1000 );

				// Append post to run-state for resume capability and reports.
				$status = null !== $existing_local_id ? 'modified' : 'imported';
				$this->data_importer->append_post( (int) $result['id_old'], (int) $result['id_new'], $result['post_type'], $status );
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
	 * @param string $source_hostname  Source hostname.
	 * @param array  $imported_ids_map Map of old_id => new_id for all imported posts.
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
		$displayed_cli_warning_get_local_id   = false;
		$displayed_cli_warning_update_parents = false;
		$progress                             = new Progress( count( $live_ids_for_parents_update ), 20 );
		foreach ( $live_ids_for_parents_update as $key_id_old => $id_old ) {
			// Output progress by 10%.
			$progress_milestone = $progress->tick( $key_id_old + 1 );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, Progress::format( $progress_milestone ) );
			}

			// Get new local Post ID.
			$id_new = $imported_ids_map[ $id_old ] ?? null;
			if ( null === $id_new ) {
				Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::WARNING, sprintf( 'update_post_parent_ids: live ID %d has no local mapping, skipping.', $id_old ) );
				if ( false === $displayed_cli_warning_get_local_id ) {
					Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::WARNING, sprintf( 'update_post_parent_ids: some live IDs have no local mapping. See %s for full list (first example: $id_old=%s).', Logger::instance()->get_log_file_path(), $id_old ) );
					$displayed_cli_warning_get_local_id = true;
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

			// Skip if current parent is already a valid local ID, i.e. was already updated (prevents ID overlap/collision on subsequent runs).
			if ( in_array( (int) $parent_id_old, array_values( $imported_ids_map ), true ) ) {
				// Still log to run-state for resume capability, marking as unchanged.
				$this->run_state->append_updated_parent(
					[
						'id_old'        => $id_old,
						'id_new'        => $id_new,
						'parent_id_old' => $parent_id_old,
						'parent_id_new' => $parent_id_old,
					] 
				);
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
				if ( false === $displayed_cli_warning_update_parents ) {
					Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::WARNING, sprintf( 'update_post_parent_ids: some parent IDs not found on live. This is usually not an error (happens when parent_ids are not found on live, or are different post types that are not being migrated). See %s for full list (first example: $id_old=%s, $id_new=%s, $parent_id_old=%s; $parent_id_new set to 0).', Logger::instance()->get_log_file_path(), $id_old, $id_new, $parent_id_old ) );
					$displayed_cli_warning_update_parents = true;
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
	 * @param array  $imported_wp_block_ids_map      Map of old_id => new_id for wp_block patterns.
	 */
	private function update_attachment_ids_in_blocks( array $imported_posts_data, string $source_hostname, array $imported_nonattachment_ids_map, array $imported_attachment_ids_map, array $imported_wp_block_ids_map ): void {

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

		// Merge attachment and wp_block IDs for block updating.
		$block_reference_ids_map = $imported_attachment_ids_map + $imported_wp_block_ids_map;

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

			$this->logic->update_blocks_ids( $id_new, $block_reference_ids_map );

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

	/**
	 * Matches unattributed local content to live DB tables and attributes matches.
	 *
	 * This is the core attribution logic extracted for reuse by both the search command
	 * (auto-attribution) and potentially other commands.
	 *
	 * @param string $live_table_prefix Live DB table prefix.
	 * @param string $source_hostname   Source hostname.
	 * @param array  $post_types        Post types to attribute.
	 * @param array  $taxonomies        Taxonomies to attribute.
	 * @return array Attribution counts: ['posts' => int, 'attachments' => int, 'users' => int, 'terms' => int]
	 */
	private function do_attribution_match_to_live_tables(
		string $live_table_prefix,
		string $source_hostname,
		array $post_types,
		array $taxonomies
	): array {
		global $wpdb;

		$meta_key            = $this->logic->get_old_id_meta_key( $source_hostname );
		$statuses_regular    = [ 'publish', 'future', 'draft', 'pending', 'private' ];
		$statuses_attachment = [ 'inherit' ];

		$posts_attributed_count       = 0;
		$attachments_attributed_count = 0;
		$users_attributed_count       = 0;
		$terms_attributed_count       = 0;

		/**
		 * Match and attribute non-attachments post_types in batches, for memory performance with large datasets.
		 */
		$post_types_non_attachments = array_filter( $post_types, fn( $post_type ) => 'attachment' !== $post_type );
		if ( ! empty( $post_types_non_attachments ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Attributing %s types...', implode( ',', $post_types_non_attachments ) ) );

			// Build live lookup once from all live posts.
			$results_live_posts = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', $post_types_non_attachments, $statuses_regular );
			$live_posts_lookup  = null;
			// Populate $live_posts_lookup (passed by reference) with the live posts.
			$this->logic->match_local_to_live_posts( [], $results_live_posts, $live_posts_lookup );
			unset( $results_live_posts );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Get posts that are truly unattributed (no meta from ANY source).
			$unattributed_posts     = $this->logic->get_unattributed_post_ids( $post_types_non_attachments );
			$unattributed_posts_map = array_flip( array_column( $unattributed_posts, 'ID' ) );
			unset( $unattributed_posts );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Process local posts in batches, reusing the live lookup.
			$batch_size    = self::MEMORY_SAFE_BATCH_SIZE;
			$total_local   = $this->logic->count_posts_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, $statuses_regular );
			$total_batches = (int) ceil( $total_local / $batch_size );
			$current_batch = 0;
			for ( $offset = 0; $offset < $total_local; $offset += $batch_size ) {
				++$current_batch;
				$local_batch   = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, $statuses_regular, $batch_size, $offset );
				$matched_batch = $this->logic->match_local_to_live_posts( $local_batch, [], $live_posts_lookup );
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, sprintf( 'Batch %d/%d...', $current_batch, $total_batches ) );
				// More verbose log for action/debug log file.
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::DEBUG,
					sprintf( 'Processing posts batch: offset=%d, limit=%d, total=%d, fetched=%d, matched=%d', $offset, $batch_size, $total_local, count( $local_batch ), count( $matched_batch ) )
				);
				unset( $local_batch );

				// Per-batch existence check: verify matched IDs exist in DB.
				$existing_ids_map = [];
				if ( ! empty( $matched_batch ) ) {
					$local_ids        = array_column( $matched_batch, 'local_id' );
					$ids_placeholders = implode( ',', array_fill( 0, count( $local_ids ), '%d' ) );
					// phpcs:disable -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare.
					$existing_results = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ( {$ids_placeholders} )", $local_ids ) );
					// phpcs:enable
					$existing_ids_map = array_flip( array_map( 'intval', $existing_results ) );
				}

				foreach ( $matched_batch as $key_match => $match ) {
					// Skip if post doesn't exist in DB.
					if ( ! isset( $existing_ids_map[ $match['local_id'] ] ) ) {
						Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Post ID %d not found in database, skipping attribution.', $match['local_id'] ) );
						continue;
					}
					// Skip if already attributed to ANY source.
					if ( ! isset( $unattributed_posts_map[ $match['local_id'] ] ) ) {
						continue;
					}
					// Attribute post.
					update_post_meta( $match['local_id'], $meta_key, $match['live_id'] );
					MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key_match, 1000 );
					Logger::instance()->log(
						Logger::OUTPUT_FILE,
						LogLevel::DEBUG,
						sprintf( 'Post attributed to %s', $source_hostname ),
						[
							'local_id' => $match['local_id'],
							'live_id'  => $match['live_id'],
						]
					);
					++$posts_attributed_count;
				}

				unset( $matched_batch, $existing_ids_map );
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			}
			unset( $live_posts_lookup, $unattributed_posts_map );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		}

		/**
		 * Match and attribute attachments in batches, for memory performance with large datasets.
		 */
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Attributing attachments...' );

		// Build live lookup once from all live attachments.
		$results_live_attachments = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], $statuses_attachment );
		$live_attachment_lookup   = null;
		// Populate $live_attachment_lookup (passed by reference) with the live attachments.
		$this->logic->match_local_to_live_posts( [], $results_live_attachments, $live_attachment_lookup );
		unset( $results_live_attachments );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Get attachments that are truly unattributed (no meta from ANY source).
		$unattributed_attachments     = $this->logic->get_unattributed_attachment_ids();
		$unattributed_attachments_map = array_flip( $unattributed_attachments );
		unset( $unattributed_attachments );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Process local attachments in batches, reusing the live lookup.
		$batch_size    = self::MEMORY_SAFE_BATCH_SIZE;
		$total_local   = $this->logic->count_posts_for_content_diff( $wpdb->prefix . 'posts', [ 'attachment' ], $statuses_attachment );
		$total_batches = (int) ceil( $total_local / $batch_size );
		$current_batch = 0;
		for ( $offset = 0; $offset < $total_local; $offset += $batch_size ) {
			++$current_batch;
			$local_batch   = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', [ 'attachment' ], $statuses_attachment, $batch_size, $offset );
			$matched_batch = $this->logic->match_local_to_live_posts( $local_batch, [], $live_attachment_lookup );
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, sprintf( 'Batch %d/%d...', $current_batch, $total_batches ) );
			// More verbose log for action/debug log file.
			Logger::instance()->log(
				Logger::OUTPUT_FILE,
				LogLevel::DEBUG,
				sprintf( 'Processing attachments batch: offset=%d, limit=%d, total=%d, fetched=%d, matched=%d', $offset, $batch_size, $total_local, count( $local_batch ), count( $matched_batch ) )
			);
			unset( $local_batch );

			foreach ( $matched_batch as $key_match => $match ) {
				// Skip if already attributed to ANY source.
				if ( ! isset( $unattributed_attachments_map[ $match['local_id'] ] ) ) {
					continue;
				}
				update_post_meta( $match['local_id'], $meta_key, $match['live_id'] );
				MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key_match, 1000 );
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::DEBUG,
					sprintf( 'Attachment attributed to %s', $source_hostname ),
					[
						'local_id' => $match['local_id'],
						'live_id'  => $match['live_id'],
					]
				);
				++$attachments_attributed_count;
			}

			unset( $matched_batch );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		}
		unset( $live_attachment_lookup, $unattributed_attachments_map );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		/**
		 * Match and attribute users.
		 */
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Attributing users...' );
		$results_local_users = $this->logic->get_users_rows_for_attribution( $wpdb->prefix );
		$results_live_users  = $this->logic->get_users_rows_for_attribution( $live_table_prefix );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
		$matched_users = $this->logic->match_local_to_live_users( $results_local_users, $results_live_users );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Get users that are truly unattributed (no meta from ANY source).
		$unattributed_users     = $this->logic->get_unattributed_user_ids();
		$unattributed_users_map = array_flip( $unattributed_users );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Do the actual attribution and set the metas.
		foreach ( $matched_users as $key_match => $match ) {
			// Skip if already attributed to ANY source.
			if ( ! isset( $unattributed_users_map[ $match['local_id'] ] ) ) {
				continue;
			}
			// Attribute user.
			update_user_meta( $match['local_id'], $meta_key, $match['live_id'] );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key_match, 1000 );
			Logger::instance()->log(
				Logger::OUTPUT_FILE,
				LogLevel::DEBUG,
				sprintf( 'User attributed to %s', $source_hostname ),
				[
					'local_id' => $match['local_id'],
					'live_id'  => $match['live_id'],
				] 
			);
			++$users_attributed_count;
		}
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		/**
		 * Match and attribute terms.
		 */
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Attributing terms...' );
		$results_local_terms = $this->logic->get_terms_rows_for_attribution( $wpdb->prefix );
		$results_live_terms  = $this->logic->get_terms_rows_for_attribution( $live_table_prefix );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Filter by specified taxonomies.
		$results_local_terms = array_filter( $results_local_terms, fn( $term ) => in_array( $term['taxonomy'], $taxonomies, true ) );
		$results_live_terms  = array_filter( $results_live_terms, fn( $term ) => in_array( $term['taxonomy'], $taxonomies, true ) );
		$matched_terms       = $this->logic->match_local_to_live_terms( $results_local_terms, $results_live_terms );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Fetch taxonomies for matched terms.
		$term_taxonomies_map = [];
		if ( ! empty( $matched_terms ) ) {
			$local_ids        = array_column( $matched_terms, 'local_id' );
			$ids_placeholders = implode( ',', array_fill( 0, count( $local_ids ), '%d' ) );
			// phpcs:disable -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare.
			$term_taxonomies_results = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, tt.taxonomy  FROM {$wpdb->terms} t  INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id  WHERE t.term_id IN ( {$ids_placeholders} )", $local_ids ), ARRAY_A );
			// phpcs:enable
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );
			$term_taxonomies_map = array_column( $term_taxonomies_results, 'taxonomy', 'term_id' );
		}

		// Get terms that are truly unattributed (no meta from ANY source).
		$unattributed_terms     = $this->logic->get_unattributed_term_ids();
		$unattributed_terms_map = array_flip( $unattributed_terms );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Do the actual attribution and set the metas.
		foreach ( $matched_terms as $key_match => $match ) {
			// Skip if term doesn't exist in DB.
			if ( ! isset( $term_taxonomies_map[ $match['local_id'] ] ) ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Term ID %d not found in database, skipping attribution.', $match['local_id'] ) );
				continue;
			}
			// Skip if already attributed to ANY source.
			if ( ! isset( $unattributed_terms_map[ $match['local_id'] ] ) ) {
				continue;
			}
			// Attribute term.
			update_term_meta( $match['local_id'], $meta_key, $match['live_id'] );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1, $key_match, 1000 );
			Logger::instance()->log(
				Logger::OUTPUT_FILE,
				LogLevel::DEBUG,
				sprintf( 'Term attributed to %s', $source_hostname ),
				[
					'local_id' => $match['local_id'],
					'live_id'  => $match['live_id'],
				] 
			);
			++$terms_attributed_count;
		}
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		return [
			'posts'       => $posts_attributed_count,
			'attachments' => $attachments_attributed_count,
			'users'       => $users_attributed_count,
			'terms'       => $terms_attributed_count,
		];
	}

	/**
	 * Displays final attribution summary with report paths.
	 *
	 * @param string $reports_dir   Reports directory path.
	 * @param string $timestamp     Timestamp used in filenames.
	 * @param array  $created_files Array of created file paths.
	 */
	private function attribute_display_summary( string $reports_dir, string $timestamp, array $created_files ): void {
		if ( ! empty( $created_files ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '📊 CSV Reports saved to %s/:', $reports_dir ) );
			foreach ( $created_files as $file_path ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '- %s', basename( $file_path ) ) );
			}
		}
	}

	/**
	 * Parses a JSONL file containing ID pairs for attribution.
	 *
	 * Each line must be a JSON object with 'old_id' and 'local_id' integer fields.
	 * Example: {"old_id": 100, "local_id": 200}
	 *
	 * @param string $file_path Path to the JSONL file.
	 *
	 * @return array Array of associative arrays with 'old_id' and 'local_id' keys.
	 */
	private function parse_jsonl_id_pairs( string $file_path ): array {
		if ( ! file_exists( $file_path ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'JSONL file not found: %s', $file_path ) );
			return [];
		}

		$lines = file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore -- WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( false === $lines ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'Failed to read JSONL file: %s', $file_path ) );
			return [];
		}

		$pairs       = [];
		$line_number = 0;
		foreach ( $lines as $line ) {
			++$line_number;
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$data = json_decode( $line, true );
			if ( ! is_array( $data ) || ! isset( $data['old_id'] ) || ! isset( $data['local_id'] ) || ! is_numeric( $data['old_id'] ) || ! is_numeric( $data['local_id'] ) ) {
				Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::WARNING, sprintf( 'Invalid JSON on line %d: %s', $line_number, $line ) );
				continue;
			}

			$pairs[] = [
				'old_id'   => (int) $data['old_id'],
				'local_id' => (int) $data['local_id'],
			];
		}

		return $pairs;
	}
}
