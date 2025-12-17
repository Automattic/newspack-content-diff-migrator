<?php
/**
 * Content Diff migrator exports and imports the content differential from one site to the local site
 * while keeping the existing local content.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Command;

use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\Logger;
use Newspack\ContentDiffMigrator\Utils\PHP as PHPUtil;
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
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->logic = new ContentDiffLogic( $wpdb );
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-list-source-hostnames',
			[ __CLASS__, 'cmd_list_source_hostnames' ],
			[
				'shortdesc' => 'Lists all source hostnames from which content has been imported.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-attribute-initial-content',
			[ __CLASS__, 'cmd_attribute_initial_content' ],
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
						'description' => 'CSV of post types to attribute. Default: post,page,attachment.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-search-new-content-on-live',
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
						'description' => 'CSV of all the post types to scan, no extra spaces. E.g. --post-types-csv=post,page,attachment,some_cpt. Default value is post,attachment.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-migrate-live-content',
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
						'description' => 'CSV of all the taxonomies to import, no extra spaces. NOTE, if you are modifying this list, make sure to include category,post_tag,author or else these will not be migrated. E.g. --custom-taxonomies-csv=post_tag,category,author,brand,custom_taxonomy.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator display-collations-comparison',
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
			'newspack-content-migrator correct-collations-for-live-wp-tables',
			[ __CLASS__, 'cmd_correct_collations_for_live_wp_tables' ],
			[
				'shortdesc' => 'This command will handle the necessary operations to match collations across Live and Core WP tables',
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
						'name'        => 'mode',
						'description' => 'Determines how large the SQL insert transactions are and the latency between them.',
						'optional'    => true,
						'default'     => 'regular',
						'options'     => [
							'aggressive',
							'regular',
							'slow',
						],
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-tables',
						'description' => 'Skip checking a particular set of tables from the collation checks.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'backup-table-prefix',
						'description' => 'Prefix to use when backing up the Live tables.',
						'optional'    => true,
						'default'     => 'bak_',
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-list-source-hostnames`.
	 *
	 * Lists all source hostnames from which content has been imported.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_list_source_hostnames( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		Logger::instance()->init( __FUNCTION__ );

		$source_sites = $this->logic->get_migrated_source_hostnames();

		if ( empty( $source_sites ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'No source hostnames found.' );
			return;
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Migrated source hostnames:' );
		foreach ( $source_sites as $site ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '  - %s', $site ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-attribute-initial-content`.
	 *
	 * Attributes/assigns existing local content to a source hostname by comparing with live DB
	 * and adding source-specific metadata.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_attribute_initial_content( array $args, array $assoc_args ): void {
		global $wpdb;

		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;
		$source_hostname   = $assoc_args['source-hostname'] ?? false;
		$post_types        = isset( $assoc_args['post-types-csv'] ) ? explode( ',', $assoc_args['post-types-csv'] ) : [ 'post', 'page', 'attachment' ];
		
		Logger::instance()->init( __FUNCTION__ );

		// Show existing source hostnames.
		$existing_source_sites = $this->logic->get_migrated_source_hostnames();
		if ( ! empty( $existing_source_sites ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Existing imported source hostnames: ' . implode( ', ', $existing_source_sites ) );
		} else {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'No previous imported source hostnames found.' );
		}

		// Validate DBs.
		try {
			$this->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, $e->getMessage() );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'About to run command correct-collations-for-live-wp-tables ...' );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'mode'              => 'generous',
					'skip-tables'       => 'options',
				]
			);
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Attributing local users and CPTs %s to source hostname %s ...', implode( ',', $post_types ), $source_hostname ) );
		
		// Post statuses by type.
		$statuses_regular    = [ 'publish', 'future', 'draft', 'pending', 'private' ];
		$statuses_attachment = [ 'inherit' ];
		
		$total_attributed = 0;
		$total_local      = 0;
		
		// Get list of post types except attachments (handled separately like in cmd_search).
		$post_types_non_attachments = array_filter( $post_types, fn( $pt ) => 'attachment' !== $pt );
		$process_attachments        = in_array( 'attachment', $post_types, true );
		
		$meta_key = $this->logic->get_old_id_meta_key( $source_hostname );

		// Process non-attachment post types.
		if ( ! empty( $post_types_non_attachments ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Querying %s types...', implode( ',', $post_types_non_attachments ) ) );

			$results_local_posts = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, $statuses_regular );
			$results_live_posts  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', $post_types_non_attachments, $statuses_regular );
			MemoryCleanupHook::cleanup( 1 );

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %d local, %d live. Matching local posts to live posts...', count( $results_local_posts ), count( $results_live_posts ) ) );
			$matched_posts = $this->logic->match_local_to_live_posts( $results_local_posts, $results_live_posts );
			MemoryCleanupHook::cleanup( 1 );

			// Save metas for matched posts.
			foreach ( $matched_posts as $match ) {
				update_post_meta( $match['local_id'], $meta_key, $match['live_id'] );
			}

			$total_attributed += count( $matched_posts );
			$total_local      += count( $results_local_posts );

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '%d posts attributed out of %d local.', count( $matched_posts ), count( $results_local_posts ) ) );
			MemoryCleanupHook::cleanup( 1 );
		}

		// Process attachments separately (like in cmd_search).
		if ( $process_attachments ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Querying attachments...' );

			$results_local_attachments = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', [ 'attachment' ], $statuses_attachment );
			$results_live_attachments  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], $statuses_attachment );
			MemoryCleanupHook::cleanup( 1 );

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %d local, %d live. Matching local attachments to live attachments...', count( $results_local_attachments ), count( $results_live_attachments ) ) );
			$matched_attachments = $this->logic->match_local_to_live_posts( $results_local_attachments, $results_live_attachments );
			MemoryCleanupHook::cleanup( 1 );

			// Save metas for matched attachments.
			foreach ( $matched_attachments as $match ) {
				update_post_meta( $match['local_id'], $meta_key, $match['live_id'] );
			}

			$total_attributed += count( $matched_attachments );
			$total_local      += count( $results_local_attachments );

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '%d attachments attributed out of %d local.', count( $matched_attachments ), count( $results_local_attachments ) ) );
			MemoryCleanupHook::cleanup( 1 );
		}

		// Process users.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Querying users...' );

		$results_local_users = $this->logic->get_users_rows_for_attribution( $wpdb->prefix );
		$results_live_users  = $this->logic->get_users_rows_for_attribution( $live_table_prefix );
		MemoryCleanupHook::cleanup( 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %d local, %d live. Matching local users to live users...', count( $results_local_users ), count( $results_live_users ) ) );
		$matched_users = $this->logic->match_local_to_live_users( $results_local_users, $results_live_users );
		MemoryCleanupHook::cleanup( 1 );

		// Save metas for matched users.
		foreach ( $matched_users as $match ) {
			update_user_meta( $match['local_id'], $meta_key, $match['live_id'] );
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( '%d users attributed out of %d local.', count( $matched_users ), count( $results_local_users ) ) );

		Logger::instance()->log(
			Logger::OUTPUT_BOTH,
			LogLevel::INFO,
			sprintf(
				'Done! Total: %d posts/attachments attributed out of %d. %d users attributed out of %d.',
				$total_attributed,
				$total_local,
				count( $matched_users ),
				count( $results_local_users )
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-search-new-content-on-live`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 * 
	 * @throws \RuntimeException If file not found or empty.
	 * @throws \Exception If error occurs.
	 */
	public function cmd_search_new_content_on_live( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		$data_dir          = $assoc_args['data-dir'] ?? false;
		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;
		$source_hostname   = $assoc_args['source-hostname'] ?? false;
		$post_types        = isset( $assoc_args['post-types-csv'] ) ? explode( ',', $assoc_args['post-types-csv'] ) : [ 'post', 'attachment' ];
		
		// Init logger.
		Logger::instance()->init( __FUNCTION__ );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, 'Starting command content-diff-search-new-content-on-live...' );

		// Disable CAP's "guest-author" CPT.
		if ( in_array( 'guest-author', $post_types ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, "CAP's 'guest-author' CPT is not supported at this point as CAP data requires a dedicated migrator for its complexity and special cases. Please remove 'guest-author' from the list of CPTs to migrate and re-run the command." );
			throw new \RuntimeException( "CAP's 'guest-author' CPT is not supported at this point as CAP data requires a dedicated migrator for its complexity and special cases. Please remove 'guest-author' from the list of CPTs to migrate and re-run the command." );
		}

		global $wpdb;
		try {
			$this->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $e->getMessage() . " - about to run `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --mode=regular --skip-tables=options` ..." );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'mode'              => 'regular',
					'skip-tables'       => 'options',
				]
			);
		}

		// Search distinct Post types in live DB.
		$live_table_prefix_escaped = esc_sql( $live_table_prefix );
		// phpcs:ignore -- table prefix string value was escaped.
		$cpts_live = $wpdb->get_col( "SELECT DISTINCT( post_type ) FROM {$live_table_prefix_escaped}posts ;" );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Following Post types found in live DB: %s', "\n- " . implode( "\n- ", $cpts_live ) ) );

		// Validate selected post types.
		array_walk(
			$post_types,
			function ( &$v, $k ) use ( $cpts_live ) { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
				if ( ! in_array( $v, $cpts_live ) ) {
					Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'Selected post type %s is not found in live DB. It will not be migrated.', $v ) );
				}
			}
		);

		// Get list of post types except attachments.
		$post_types_non_attachments = $post_types;
		$key                        = array_search( 'attachment', $post_types_non_attachments );
		if ( false !== $key ) {
			unset( $post_types_non_attachments[ $key ] );
			$post_types_non_attachments = array_values( $post_types_non_attachments );
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'Searching live DB for new content...' );
		try {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Querying %s ...', implode( ',', $post_types_non_attachments ) ) );
			$results_live_posts  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );
			$results_local_posts = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );
			MemoryCleanupHook::cleanup( 1 );

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %s total from live site, checking which ones are new...', count( $results_live_posts ) ) );
			$new_live_ids = $this->logic->filter_new_live_ids( $results_live_posts, $results_local_posts );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d new IDs found.', count( $new_live_ids ) ) );
			MemoryCleanupHook::cleanup( 1 );

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for content which was modified on live...' );
			$modified_live_ids = $this->logic->filter_modified_live_ids( $results_live_posts, $results_local_posts );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d modified IDs found.', count( $modified_live_ids ) ) );
			MemoryCleanupHook::cleanup( 1 );

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Querying attachments ...' );
			$results_live_attachments  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );
			$results_local_attachments = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );
			MemoryCleanupHook::cleanup( 1 );

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %s total from live site, checking which ones are new...', count( $results_live_attachments ) ) );
			$new_live_attachment_ids = $this->logic->filter_new_live_ids( $results_live_attachments, $results_local_attachments );
			$new_live_ids            = array_merge( $new_live_ids, $new_live_attachment_ids );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d new IDs found.', count( $new_live_attachment_ids ) ) );

		} catch ( \Exception $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $e->getMessage() );
			throw $e;
		}

		/**
		 * Get run-state (with access to all the data that needs to be migrated, and keeps progress of the migration).
		 * 
		 * Run-state data is stored in formatted files in the subfolder:
		 *      {--data-dir}/{--source-hostname}/run-state
		 * And the regular logs are stored directly in:
		 *      {--data-dir}/{--source-hostname}
		 */
		$run_state_dir   = rtrim( $data_dir, '/' ) . '/' . $source_hostname . '/run-state';
		$this->run_state = new RunState( $run_state_dir );

		// Write new IDs to migrate.
		if ( count( $new_live_ids ) > 0 ) {
			$this->run_state->write_new_ids( $new_live_ids );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'New IDs exported to %s', $this->run_state->get_file_path( 'new_ids.json' ) ) );
		}

		// Write IDs which are modified and need to be reimported.
		if ( count( $modified_live_ids ) > 0 ) {
			$this->run_state->write_modified_ids( $modified_live_ids );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'Modified IDs exported to %s', $this->run_state->get_file_path( 'modified_ids.json' ) ) );
		}

		// Save manifest.json with migration TOC.
		$manifest = [
			'source_hostname'   => $source_hostname,
			'live_table_prefix' => $live_table_prefix,
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
			'counts'            => [
				'new_ids'      => count( $new_live_ids ),
				'modified_ids' => count( $modified_live_ids ),
			],
		];
		$this->run_state->write_manifest( $manifest );
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-migrate-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 * 
	 * @throws \RuntimeException If file not found or empty.
	 */
	public function cmd_migrate_live_content( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		global $wpdb;

		$data_dir          = $assoc_args['data-dir'] ?? false;
		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;
		$source_hostname   = $assoc_args['source-hostname'] ?? false;
		
		// Init logger.
		Logger::instance()->init( __FUNCTION__ );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, 'Starting command content-diff-migrate-live-content...' );

		// Default taxonomies which are migrated are defined here.
		$taxonomies_to_migrate = isset( $assoc_args['custom-taxonomies-csv'] ) ? explode( ',', $assoc_args['custom-taxonomies-csv'] ) : [ 'category', 'post_tag', 'author' ];

		// Set instance properties.
		$run_state_dir           = rtrim( $data_dir, '/' ) . '/' . $source_hostname . '/run-state';
		$this->run_state         = new RunState( $run_state_dir );
		$this->live_table_prefix = $live_table_prefix;

		// Get new IDs which will be migrated.
		$new_live_ids = $this->run_state->read_new_ids();
		if ( null === $new_live_ids ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'Run-state file %s not found or empty.', RunState::FILE_NEW_IDS ) );
			exit;
		} elseif ( empty( $new_live_ids ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'No new posts to migrate.' );
			exit;
		}

		// In case some custom taxonomies were provided, but category,post_tag,author were not among those, warn the user that they won't be migrated and ask for confirmation to continue.
		if ( ! empty( $assoc_args['custom-taxonomies-csv'] ) ) {
			if ( ! in_array( 'category', $taxonomies_to_migrate ) ) {
				WP_CLI::confirm( 'Warning, category was not given in --custom-taxonomies-csv argument and so categories will not be migrated. Continue?' );
			}
			if ( ! in_array( 'post_tag', $taxonomies_to_migrate ) ) {
				WP_CLI::confirm( 'Warning, post_tag was not given in --custom-taxonomies-csv argument and so tags will not be migrated. Continue?' );
			}
			if ( ! in_array( 'author', $taxonomies_to_migrate ) ) {
				WP_CLI::confirm( 'Warning, author was not given in --custom-taxonomies-csv argument and so co-authors will not be migrated. Continue?' );
			}
		}

		// Validate DBs.
		try {
			$this->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $e->getMessage() );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, "Now running command `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --mode=regular --skip-tables=options` ..." );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'mode'              => 'regular',
					'skip-tables'       => 'options',
				]
			);
		}

		// Timestamp the debug log with source hostname for identification.
		$ts            = gmdate( 'Y-m-d h:i:s a', time() );
		$log_start_msg = sprintf( 'Starting %s | source hostname: %s', $ts, $source_hostname );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, $log_start_msg );

		// List all the custom taxonomies which exist in Live DB for user's overview.
		// phpcs:ignore -- table prefix string value was escaped.
		$live_table_prefix_escaped = esc_sql( $live_table_prefix );
		$live_taxonomies = $wpdb->get_col( "SELECT DISTINCT( taxonomy ) FROM {$live_table_prefix_escaped}term_taxonomy ;" ); // phpcs:ignore -- table prefix string value was escaped.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Here are all the taxonomies which exist in the live DB: %s', "\n- " . implode( "\n- ", $live_taxonomies ) ) );

		// Before we create hierarchical taxonomies, let's make sure all hierarchical taxonomies have valid parents. If they don't they should be fixed first.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Validating all the taxonomies which will be migrated: %s', "\n- " . implode( "\n- ", $taxonomies_to_migrate ) ) );
		$taxonomies_to_migrate = $this->validate_hierarchical_taxonomies( $taxonomies_to_migrate, $live_taxonomies );

		// Recreate taxonomies but leave out (unused) tags.
		$taxonomies_to_recreate = array_diff( $taxonomies_to_migrate, [ 'post_tag' ] );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Recreating taxonomies: %s ...', "\n- " . implode( "\n- ", $taxonomies_to_recreate ) ) );
		$hierarchical_taxonomy_term_id_updates = $this->recreate_hierarchical_taxonomies( $taxonomies_to_recreate );
		MemoryCleanupHook::cleanup( 1 );

		// Migrate all WP_Users (for WooComm data).
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Migrating all WP_Users...' );
		$this->migrate_all_users( $live_table_prefix, $source_hostname );
		MemoryCleanupHook::cleanup( 1 );

		// Process modified IDs.
		// Get map (old => new) modified IDs.
		$modified_ids_map = $this->run_state->get_modified_ids_map();
		if ( null !== $modified_ids_map && ! empty( $modified_ids_map ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Deleting %s modified posts before they are reimported...', count( $modified_ids_map ) ) );
			
			// Get list of (already) deleted modified IDs.
			$deleted_modified_ids_map = $this->run_state->get_deleted_modified_ids_map();

			// Get local IDs which still need to be deleted (subtract modified local IDs from already deleted local IDs), and delete them.
			$local_ids_to_delete = array_diff( array_values( $modified_ids_map ), array_values( $deleted_modified_ids_map ) );
			$this->delete_local_posts( $local_ids_to_delete );

			// Append to run-state that these modified IDs were deleted.
			foreach ( $local_ids_to_delete as $id ) {
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

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Importing %d objects, hold tight...', count( $new_live_ids ) ) );
		$imported_posts_data = $this->import_posts( $new_live_ids, $hierarchical_taxonomy_term_id_updates, $source_hostname );
		MemoryCleanupHook::cleanup( 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating Post parent IDs...' );
		$this->update_post_parent_ids( $new_live_ids, $imported_posts_data, $source_hostname );
		MemoryCleanupHook::cleanup( 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating Featured images IDs...' );
		$this->update_featured_image_ids( $imported_posts_data, $source_hostname );
		MemoryCleanupHook::cleanup( 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating attachment IDs in block content...' );
		$this->update_attachment_ids_in_blocks( $imported_posts_data );
		MemoryCleanupHook::cleanup( 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'All done migrating content! 🙌 ' );

		// Display info about available logs.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'Check the logs for more details:' );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '- debug/action log: %s', Logger::instance()->get_log_file_name() ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '- manifest: %s', $this->run_state->get_file_path( 'manifest.json' ) ) );

		wp_cache_flush();
	}

	/**
	 * Validates local DB and live DB taxonomies. Checks if the taxonomies's parent term_ids are correct in the live DB, and sets those to zero if they are not correct.
	 *
	 * @param array $taxonomies_to_migrate Hierarchical taxonomies to migrate.
	 * @param array $live_taxonomies       List of all taxonomies found in the Live DB.
	 *
	 * @return array $taxonomies_to_migrate Validated and filtered hierarchical taxonomies to migrate.
	 */
	public function validate_hierarchical_taxonomies( array $taxonomies_to_migrate, array $live_taxonomies ): array {
		global $wpdb;

		// Check if any of the taxonomies does not exist in the live DB.
		foreach ( $taxonomies_to_migrate as $key_taxonomy_to_migrate => $taxonomy_to_migrate ) {
			if ( ! in_array( $taxonomy_to_migrate, $live_taxonomies ) ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Taxonomy %s not found in live DB and will not be migrated.', $taxonomy_to_migrate ) );
				unset( $taxonomies_to_migrate[ $key_taxonomy_to_migrate ] );
			}
		}
		$taxonomies_to_migrate = array_values( $taxonomies_to_migrate );

		// Check if any of the local taxonomies have nonexistent wp_term_taxonomy.parent, and fix those before continuing by setting their parents to 0.
		$hierarchical_taxonomies = $this->logic->get_taxonomies_with_nonexistent_parents( $wpdb->prefix, $taxonomies_to_migrate );
		if ( ! empty( $hierarchical_taxonomies ) ) {
			$list              = '';
			$term_taxonomy_ids = [];
			foreach ( $hierarchical_taxonomies as $hierarchical_taxonomy ) {
				$list               .= ( empty( $list ) ? '' : "\n" ) . '  ' . wp_json_encode( $hierarchical_taxonomy );
				$term_taxonomy_ids[] = $hierarchical_taxonomy['term_taxonomy_id'];
			}

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, 'The following local DB hierarchical taxonomies have invalid parent IDs which will be fixed first (their parents set to 0).' );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, $list );
			$this->logic->reset_hierarchical_taxonomies_parents( $wpdb->prefix, $term_taxonomy_ids );
		}

		// Check the same for Live DB's hierarchical taxonomies, and fix those before continuing by setting their parents to 0.
		$hierarchical_taxonomies = $this->logic->get_taxonomies_with_nonexistent_parents( $this->live_table_prefix, $taxonomies_to_migrate );
		if ( ! empty( $hierarchical_taxonomies ) ) {
			$list              = '';
			$term_taxonomy_ids = [];
			foreach ( $hierarchical_taxonomies as $hierarchical_taxonomy ) {
				$list               .= ( empty( $list ) ? '' : "\n" ) . '  ' . wp_json_encode( $hierarchical_taxonomy );
				$term_taxonomy_ids[] = $hierarchical_taxonomy['term_taxonomy_id'];
			}

			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, 'The following live DB hierarchical taxonomies have invalid parent IDs which must be fixed first (their parents set to 0 in live tables).' );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, $list );
			$this->logic->reset_hierarchical_taxonomies_parents( $this->live_table_prefix, $term_taxonomy_ids );
		}

		return $taxonomies_to_migrate;
	}

	/**
	 * Recreates all hierarchical taxonomies from Live to local.
	 *
	 * If hierarchical cats are used, their whole structure should be in place when they get assigned to posts.
	 *
	 * @param array $taxonomies_to_migrate Hierarchical taxonomies to migrate.
	 *
	 * @return array Map of taxonomy term_id udpdates. Keys are hierarchical taxonomies' term_ids on Live and values are corresponding
	 *               hierarchical taxonomies' term_ids on local (staging).
	 */
	public function recreate_hierarchical_taxonomies( $taxonomies_to_migrate ) {
		$hierarchical_taxonomy_term_id_updates = $this->logic->recreate_hierarchical_taxonomies( $this->live_table_prefix, $taxonomies_to_migrate );

		// Log taxonomy term_id updates to debug log.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'Recreated hierarchical taxonomies: ' . wp_json_encode( [ 'hierarchical_taxonomy_term_id_updates' => $hierarchical_taxonomy_term_id_updates ] ) );

		return $hierarchical_taxonomy_term_id_updates;
	}

	/**
	 * Migrates all WP_Users from Live to local.
	 *
	 * @param string $live_table_prefix Live table prefix.
	 * @param string $source_hostname   Source hostname.
	 * @return array Map of newly inserted WP_Users, keys are old Live IDs and values are new local IDs.
	 */
	public function migrate_all_users( string $live_table_prefix, string $source_hostname ): array {
		$inserted_wp_users_updates = $this->logic->migrate_all_users( $live_table_prefix, $source_hostname );

		// Log inserted users to debug log.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'Inserted WP_Users: ' . wp_json_encode( [ 'inserted_wp_users_updates' => $inserted_wp_users_updates ] ) );

		return $inserted_wp_users_updates;
	}

	/**
	 * Permanently deletes local posts.
	 *
	 * @param array $ids Post IDs.
	 *
	 * @return void
	 */
	public function delete_local_posts( array $ids ): void {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Creates and imports posts and all related post data. Skips previously imported IDs found in $this->log_imported_post_ids.
	 *
	 * @param array  $all_live_posts_ids       Live IDs to be imported to local.
	 * @param array  $hierarchical_taxonomy_term_id_updates Map of updated hierarchical taxonomy term_ids. Keys are Taxonomies' term_ids on live, and values
	 *                                         are corresponding Taxonomies' term_ids on local (staging).
	 * @param string $source_hostname          Source hostname.
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
	public function import_posts( array $all_live_posts_ids, array $hierarchical_taxonomy_term_id_updates, string $source_hostname ): array {

		$post_ids_for_import = $all_live_posts_ids;

		// Skip previously imported posts.
		$imported_posts_data = $this->run_state->read_imported_posts();
		$imported_ids_lookup = [];
		foreach ( $imported_posts_data as $imported_post_data ) {
			$id_old = $imported_post_data['id_old'] ?? null;
			if ( ! is_null( $id_old ) ) {
				$imported_ids_lookup[ $id_old ] = $imported_post_data;
			}
		}

		foreach ( $imported_ids_lookup as $id_old => $imported_post_data ) {
			$key_id_old = array_search( $id_old, $post_ids_for_import );
			if ( false !== $key_id_old ) {
				unset( $post_ids_for_import[ $key_id_old ] );
			}
		}

		if ( empty( $post_ids_for_import ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts were already imported, moving on.' );
			return $imported_posts_data;
		}
		if ( $post_ids_for_import !== $all_live_posts_ids ) {
			$post_ids_for_import = array_values( $post_ids_for_import );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%s of total %d IDs were already imported, continuing from there. Hold tight..', count( $all_live_posts_ids ) - count( $post_ids_for_import ), count( $all_live_posts_ids ) ) );
		}

		// Import Posts.
		$percent_progress = null;
		foreach ( $post_ids_for_import as $key_post_id => $post_id_live ) {

			// Get and output progress meter by 10%.
			$last_percent_progress = $percent_progress;
			$this->logic->get_progress_percentage( count( $post_ids_for_import ), $key_post_id + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			// Get all Post data from DB.
			$post_data = $this->logic->get_post_data( (int) $post_id_live, $this->live_table_prefix );
			$post_type = $post_data[ $this->logic::DATAKEY_POST ]['post_type'];

			// First just insert a new blank `wp_posts` record to get the new ID.
			try {
				$post_id_new           = $this->logic->insert_post( $post_data[ $this->logic::DATAKEY_POST ] );
				$imported_posts_data[] = [
					'post_type' => $post_type,
					'id_old'    => (int) $post_id_live,
					'id_new'    => (int) $post_id_new,
				];
			} catch ( \Exception $e ) {
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'import_posts error while inserting post_type %s id_old=%d : %s', $post_type, $post_id_live, $e->getMessage() ) );
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Error inserting %s Live ID %d (details in log file)', $post_type, $post_id_live ) );

				// Error is logged. Continue importing other posts.
				continue;
			}

			// Now import all related Post data.
			$import_errors = $this->logic->import_post_data( $post_id_new, $post_data, $hierarchical_taxonomy_term_id_updates, $source_hostname );
			if ( ! empty( $import_errors ) ) {
				$msg = sprintf( 'Errors during import post_type=%s, id_old=%d, id_new=%d :', $post_type, $post_id_live, $post_id_new );
				foreach ( $import_errors as $import_error ) {
					$msg .= PHP_EOL . '- ' . $import_error;
				}
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $msg );
			}

			// Log imported post to JSONL.
			$this->run_state->append_imported_post(
				[
					'post_type' => $post_type,
					'id_old'    => (int) $post_id_live,
					'id_new'    => (int) $post_id_new,
				]
			);

			// Save source-specific old ID meta.
			$meta_key = $this->logic->get_old_id_meta_key( $source_hostname );
			update_post_meta( $post_id_new, $meta_key, $post_id_live );
		}

		// Flush the cache for `$wpdb::update`s to sink in.
		wp_cache_flush();

		return $imported_posts_data;
	}

	/**
	 * Updates all Posts' post_parent IDs.
	 *
	 * @param array  $all_live_posts_ids Old (Live) IDs to have their post_parent updated.
	 * @param array  $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 * @param string $source_hostname Source hostname.
	 */
	public function update_post_parent_ids( array $all_live_posts_ids, array $imported_posts_data, string $source_hostname ): void {

		$parent_ids_for_update = $all_live_posts_ids;

		// Skip previously updated IDs.
		$previously_updated_parent_ids_data = $this->run_state->read_updated_parents();
		$updated_ids_lookup                 = [];
		foreach ( $previously_updated_parent_ids_data as $entry ) {
			$id_old = $entry['id_old'] ?? null;
			if ( ! is_null( $id_old ) ) {
				$updated_ids_lookup[ $id_old ] = true;
			}
		}

		foreach ( $updated_ids_lookup as $id_old => $_ ) {
			$key_id_old = array_search( $id_old, $parent_ids_for_update );
			if ( false !== $key_id_old ) {
				unset( $parent_ids_for_update[ $key_id_old ] );
			}
		}

		if ( empty( $parent_ids_for_update ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts already had their post_parent updated, moving on.' );
			return;
		}
		if ( $parent_ids_for_update !== $all_live_posts_ids ) {
			$parent_ids_for_update = array_values( $parent_ids_for_update );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%s post_parent IDs of total %d were already updated, continuing from there..', count( $all_live_posts_ids ) - count( $parent_ids_for_update ), count( $all_live_posts_ids ) ) );
		}

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->get_attachments_from_imported_posts_log( $imported_posts_data );

		// Try and free some memory.
		$all_live_posts_ids  = null;
		$imported_posts_data = null;
		usleep( 100000 );

		// Update parent IDs.
		global $wpdb;
		$percent_progress = null;
		foreach ( $parent_ids_for_update as $key_id_old => $id_old ) {

			// Get and output progress meter by 10%.
			$last_percent_progress = $percent_progress;
			$this->logic->get_progress_percentage( count( $parent_ids_for_update ), $key_id_old + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			// Get new local Post ID.
			$id_new = $imported_post_ids_map[ $id_old ] ?? null;
			$id_new = is_null( $id_new ) ? $imported_attachment_ids_map[ $id_old ] : $id_new;

			// Get Post's post_parent which uses the live DB ID.
			$parent_id_old = $wpdb->get_var( $wpdb->prepare( "SELECT post_parent FROM $wpdb->posts WHERE ID = %d;", $id_new ) ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching.

			// No update to do.
			if ( ( '0' == $parent_id_old ) || empty( $parent_id_old ) ) {
				continue;
			}

			// Get new post_parent.
			$parent_id_new = $imported_post_ids_map[ $parent_id_old ] ?? null;
			// Check if it's perhaps an attachment.
			$parent_id_new = is_null( $parent_id_new ) && array_key_exists( $parent_id_old, $imported_attachment_ids_map ) ? $imported_attachment_ids_map[ $parent_id_old ] : $parent_id_new;

			// It's possible that this $post's post_parent already existed in local DB before the Content Diff import was run, so
			// it won't be present in the list of the posts we imported. Let's try and search for the new ID directly in DB.
			// First try searching by source-specific postmeta -- in case a previous content diff imported it.
			if ( is_null( $parent_id_new ) ) {
				$meta_key      = $this->logic->get_old_id_meta_key( $source_hostname );
				$parent_id_new = $this->logic->get_current_post_id_by_custom_meta( $parent_id_old, $meta_key );
			}
			// Next try searching for the new parent_id by joining local and live DB tables.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = $this->logic->get_current_post_id_by_comparing_with_live_db( $parent_id_old, $this->live_table_prefix );
			}

			// Warn if this post_parent object was not found/imported. It might be legit, like the parent object being a
			// post_type different than the supported post type, or an error like the post_parent object missing in Live DB.
			if ( is_null( $parent_id_new ) ) {
				// If all attempts failed (possibly this parent does not exist in the live DB, or if this parent is of a post_type which was not imported), set that post_parent to 0.
				$parent_id_new = 0;

				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'update_post_parent_ids error, $id_old=%s, $id_new=%s, $parent_id_old=%s, $parent_id_new is 0.', $id_old, $id_new, $parent_id_old ) );
			}

			// Update.
			if ( $parent_id_old != $parent_id_new ) {
				$this->logic->update_post_parent( $id_new, $parent_id_new );
			}

			// Log IDs of the Post to JSONL.
			$log_entry = [
				'id_old' => $id_old,
				'id_new' => $id_new,
			];
			if ( 0 != $parent_id_old && ! is_null( $parent_id_new ) ) {
				// Log, add IDs of post_parent.
				$log_entry['parent_id_old'] = $parent_id_old;
				$log_entry['parent_id_new'] = $parent_id_new;
			}
			$this->run_state->append_updated_parent( $log_entry );
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
	 * @param string $source_hostname Source hostname.
	 */
	public function update_featured_image_ids( array $imported_posts_data, string $source_hostname ): void {

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->logic->get_imported_attachment_id_mapping_from_db( $source_hostname );

		// Get new Post IDs from DB.
		$new_post_ids = array_values( $imported_post_ids_map );

		// Read previously updated IDs for resume.
		$updated_featured = $this->run_state->read_updated_featured();
		$updated_ids      = [];
		foreach ( $updated_featured as $entry ) {
			$post_id = $entry['post_id'] ?? null;
			if ( ! is_null( $post_id ) ) {
				$updated_ids[ $post_id ] = true;
			}
		}
		$new_post_ids = array_filter(
			$new_post_ids,
			function( $id ) use ( $updated_ids ) {
				return ! isset( $updated_ids[ $id ] );
			}
		);

		if ( ! empty( $new_post_ids ) ) {
			$this->logic->update_featured_images( array_values( $new_post_ids ), $imported_attachment_ids_map );
		}
	}

	/**
	 * Updates Attachment IDs in Post contents.
	 *
	 * Some Gutenberg Blocks contain `id` or `ids` of Attachments attributes in their headers, and image elements contain those
	 * IDs too.
	 *
	 * @param array $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function update_attachment_ids_in_blocks( array $imported_posts_data ): void {

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->get_attachments_from_imported_posts_log( $imported_posts_data );

		// Skip previously updated Posts.
		$updated_blocks = $this->run_state->read_updated_blocks();
		$updated_ids    = [];
		foreach ( $updated_blocks as $entry ) {
			$id_new = $entry['id_new'] ?? null;
			if ( ! is_null( $id_new ) ) {
				$updated_ids[ $id_new ] = true;
			}
		}

		$new_post_ids_for_blocks_update = array_filter(
			array_values( $imported_post_ids_map ),
			function( $id ) use ( $updated_ids ) {
				return ! isset( $updated_ids[ $id ] );
			}
		);

		if ( empty( $new_post_ids_for_blocks_update ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts already had their blocks\' attachment IDs updated, moving on.' );
			return;
		}
		if ( count( $new_post_ids_for_blocks_update ) < count( $imported_post_ids_map ) ) {
			$new_post_ids_for_blocks_update = array_values( $new_post_ids_for_blocks_update );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%s of total %d posts already had their blocks\' IDs updated, continuing from there..', count( $imported_post_ids_map ) - count( $new_post_ids_for_blocks_update ), count( $imported_post_ids_map ) ) );
		}

		$this->logic->update_blocks_ids( array_values( $new_post_ids_for_blocks_update ), $imported_attachment_ids_map );
	}

	/**
	 * This function will display a table comparing the collations of Live and Core WP tables.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Optional arguments.
	 */
	public function cmd_compare_collations_of_live_and_core_wp_tables( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		$live_table_prefix     = $assoc_args['live-table-prefix'];
		$skip_tables           = [];
		$different_tables_only = $assoc_args['different-collations-only'] ?? false;

		Logger::instance()->init( __FUNCTION__ );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, 'Starting command compare-collations-of-live-and-core-wp-tables...' );

		if ( ! empty( $assoc_args['skip-tables'] ) ) {
			$skip_tables = explode( ',', $assoc_args['skip-tables'] );
		}

		$tables = [];

		if ( $different_tables_only ) {
			$tables = $this->logic->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );
		} else {
			$tables = $this->logic->get_collation_comparison_of_live_and_core_wp_tables( $live_table_prefix, $skip_tables );
		}

		if ( ! empty( $tables ) ) {
			WP_CLI\Utils\format_items( 'table', $tables, array_keys( $tables[0] ) );
		} else {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'Live and Core WP DB table collations match.' );
		}
	}

	/**
	 * This function will execute the necessary steps to get Live WP
	 * tables to match the collation of Core WP tables.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Optional arguments.
	 */
	public function cmd_correct_collations_for_live_wp_tables( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		$live_table_prefix = $assoc_args['live-table-prefix'];
		$mode              = $assoc_args['mode'];
		$backup_prefix     = isset( $assoc_args['backup-table-prefix'] ) ? $assoc_args['backup-table-prefix'] : 'collationbak_';
		$skip_tables       = isset( $assoc_args['skip-tables'] ) ? explode( ',', $assoc_args['skip-tables'] ) : [];
		
		Logger::instance()->init( __FUNCTION__ );
		
		$tables_with_differing_collations = $this->logic->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );

		if ( ! empty( $tables_with_differing_collations ) ) {
			WP_CLI\Utils\format_items( 'table', $tables_with_differing_collations, array_keys( $tables_with_differing_collations[0] ) );
		}

		switch ( $mode ) {
			case 'aggressive':
				$records_per_transaction = 50000;
				$sleep_in_seconds        = 1;
				break;
			case 'regular':
				$records_per_transaction = 10000;
				$sleep_in_seconds        = 2;
				break;
			case 'slow':
				$records_per_transaction = 1000;
				$sleep_in_seconds        = 3;
				break;
			default:
				$records_per_transaction = 10000;
				$sleep_in_seconds        = 2;
				break;
		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, "Now fixing $live_table_prefix tables collations..." );
		foreach ( $tables_with_differing_collations as $result ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Addressing ' . $result['table'] . ' table...' );
			$this->logic->copy_table_data_using_proper_collation( $live_table_prefix, $result['table'], $records_per_transaction, $sleep_in_seconds, $backup_prefix );
		}
	}

	/**
	 * Filters the log data array by where conditions.
	 *
	 * @param array  $imported_posts_log_data Log data array, consists of subarrays with one or more multiple key=>values.
	 * @param string $where_key               Search key.
	 * @param array  $where_values            Search value.
	 * @param string $where_operand           Search operand, can be '==' or '!='.
	 * @param bool   $return_first            If true, return just the first matched entry, otherwise returns all matched entries.
	 *
	 * @throws \RuntimeException In case an unsupported $where_operand was given.
	 *
	 * @return array Found results. Mind that if $return_first is true, it will return a one-dimensional array,
	 *               and if $return_first is false, it will return two-dimensional array with all matched elements as subarrays.
	 */
	private function filter_imported_posts_log( array $imported_posts_log_data, string $where_key, array $where_values, string $where_operand, bool $return_first = true ): array {
		$return                   = [];
		$supported_where_operands = [ '==', '!=' ];

		// Validate $where_operand.
		if ( ! in_array( $where_operand, $supported_where_operands ) ) {
			throw new \RuntimeException( sprintf( 'Where operand %s is not supported.', esc_textarea( $where_operand ) ) );
		}

		foreach ( $imported_posts_log_data as $entry ) {

			// Check $where conditions.
			foreach ( $where_values as $where_value ) {

				$matched = false;
				if ( '==' === $where_operand ) {
					$matched = isset( $entry[ $where_key ] ) && $where_value == $entry[ $where_key ];
				} elseif ( '!=' === $where_operand ) {
					$matched = isset( $entry[ $where_key ] ) && $where_value != $entry[ $where_key ];
				}

				if ( true === $matched ) {
					$return[] = $entry;

					// Return the very first element matching $where.
					if ( true === $return_first ) {
						return $entry;
					}
				}
			}
		}

		return $return;
	}

	/**
	 * Gets IDs from the log for Posts, Pages and other post types which are not Attachments.
	 *
	 * @param array $imported_posts_data Imported posts log data.
	 *
	 * @return array IDs.
	 */
	private function get_non_attachments_from_imported_posts_log( array $imported_posts_data ): array {
		$imported_post_ids_map    = [];
		$imported_posts_data_post = $this->filter_imported_posts_log( $imported_posts_data, 'post_type', [ 'attachment' ], '!=', false );
		foreach ( $imported_posts_data_post as $entry ) {
			$imported_post_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		return $imported_post_ids_map;
	}

	/**
	 * Gets IDs from the log for Attachments.
	 *
	 * @param array $imported_posts_data Imported posts log data.
	 *
	 * @return array IDs, keys are old/live IDs, values are new/local IDs.
	 */
	private function get_attachments_from_imported_posts_log( array $imported_posts_data ): array {
		$imported_attachment_ids_map   = [];
		$imported_post_data_attachment = $this->filter_imported_posts_log( $imported_posts_data, 'post_type', [ 'attachment' ], '==', false );
		foreach ( $imported_post_data_attachment as $entry ) {
			$imported_attachment_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		return $imported_attachment_ids_map;
	}

	/**
	 * Validates DB tables.
	 *
	 * @param string $live_table_prefix Live table prefix.
	 * @param array  $skip_tables       Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException In case that table collations do not match.
	 *
	 * @return void
	 */
	public function validate_db_tables( string $live_table_prefix, array $skip_tables ): void {
		$this->logic->validate_core_wp_db_tables_exist_in_db( $live_table_prefix, $skip_tables );
		if ( ! $this->logic->are_table_collations_matching( $live_table_prefix, $skip_tables ) ) {
			throw new \RuntimeException( 'Table collations do not match for some (or all) WP tables.' );
		}
	}
}
