<?php
/**
 * Content Diff migrator exports and imports the content differential from one site to the local site
 * while keeping the existing local content.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Command;

use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;
use Newspack\ContentDiffMigrator\Logic\DB;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\Logger;
use Newspack\ContentDiffMigrator\Utils\Progress;
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
		// This class is presently just integration-tested, so no need to enable injection of any dependencies.
		global $wpdb;
		$this->logic    = new ContentDiffLogic( $wpdb );
		$this->db       = new DB( $wpdb );
		$this->test_env = $test_env;
	}

	/**
	 * Set RunState instance (for testing environment).
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
			'newspack-content-diff-migrator list-source-hostnames',
			[ __CLASS__, 'cmd_list_source_hostnames' ],
			[
				'shortdesc' => 'Lists all source hostnames from which content has been imported.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-diff-migrator attribute-initial-content',
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
						'description' => 'CSV of all the post types to scan, no extra spaces. E.g. --post-types-csv=post,page,attachment,some_cpt. Default value is post,attachment.',
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
						'description' => 'CSV of all the taxonomies to import, no extra spaces. NOTE, if you are modifying this list, make sure to include category,post_tag,author or else these will not be migrated. E.g. --custom-taxonomies-csv=post_tag,category,author,brand,custom_taxonomy.',
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
	 * Callable for `newspack-content-diff-migrator list-source-hostnames`.
	 *
	 * Lists all source hostnames from which content has been imported.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_list_source_hostnames( array $args, array $assoc_args ): void { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		Logger::instance()->init( __FUNCTION__ );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::INFO, sprintf( 'Starting %s', __FUNCTION__ ) );

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
	 * Callable for `newspack-content-diff-migrator attribute-initial-content`.
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
		
		// Init logger.
		Logger::instance()->init( __FUNCTION__ );
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::INFO, sprintf( 'Starting %s | source hostname: %s', __FUNCTION__, $source_hostname ) );

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
					'mode'              => 'generous',
					'skip-tables'       => 'options',
				]
			);
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

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Matching local content (CPTs %s and suers) to live DB, and attributing matches to source hostname %s ...', implode( ',', $post_types ), $source_hostname ) );
		
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

			// Save metas for matched posts.
			foreach ( $matched_posts as $match ) {
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

			// Attribute matched attachments to source hostname.
			foreach ( $matched_attachments as $match ) {
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

		// Attribute matched users to source hostname.
		foreach ( $matched_users as $match ) {
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

		// Attribute matched terms to source hostname.
		foreach ( $matched_terms as $match ) {
			update_term_meta( $match['local_id'], $meta_key, $match['live_id'] );
			// Detailed log to file only.
			$context = [
				'local_term_id' => $match['local_id'],
				'live_term_id'  => $match['live_id'],
			];
			Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, sprintf( 'Term attributed to source_hostname %s', $source_hostname ), $context );
		}
		Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, sprintf( '%d local terms attributed out of %d total.', count( $matched_terms ), count( $results_local_terms ) ) );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'Done!' );
	}

	/**
	 * Callable for `newspack-content-diff-migrator search-new-content-on-live`.
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

		// Set instance properties.
		global $wpdb;
		if ( null === $this->run_state ) {
			$this->run_state = new RunState( rtrim( $data_dir, '/' ) . '/' . $source_hostname . '/run-state' );
		}

		// Disable CAP's "guest-author" CPT.
		if ( in_array( 'guest-author', $post_types ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, "CAP's 'guest-author' CPT is not supported at this point as CAP data requires a dedicated migrator for its complexity and special cases. Please remove 'guest-author' from the list of CPTs to migrate and re-run the command." );
			throw new \RuntimeException( "CAP's 'guest-author' CPT is not supported at this point as CAP data requires a dedicated migrator for its complexity and special cases. Please remove 'guest-author' from the list of CPTs to migrate and re-run the command." );
		}

		try {
			$this->db->validate_db_tables( $live_table_prefix, [ 'options' ] );
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
		$cpts_live = $wpdb->get_col( "SELECT DISTINCT( post_type ) FROM {$live_table_prefix_escaped}posts ;" ); // phpcs:ignore -- table prefix string value was escaped.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Following Post types found in live DB: %s', "\n- " . implode( "\n- ", $cpts_live ) ) );

		// Validate selected CPTs.
		array_walk(
			$post_types,
			function ( &$v, $k ) use ( $cpts_live ) { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
				if ( ! in_array( $v, $cpts_live ) ) {
					Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'Selected post type %s is not found in live DB. It will not be migrated.', $v ) );
				}
			}
		);

		// Check is there is any unattributed content on local site (posts and CPTs, attachments, users, terms which already exist on local site, and have not been attributed to source hostname, i.e. no "old_id meta", so they will not be considered/compared during migration), and warn if found.
		$unattributed_posts       = $this->logic->count_unattributed_posts( $source_hostname, $post_types );
		$unattributed_attachments = in_array( 'attachment', $post_types, true ) ? $this->logic->count_unattributed_attachments( $source_hostname ) : 0;
		$unattributed_users       = $this->logic->count_unattributed_users( $source_hostname );
		$unattributed_terms       = $this->logic->count_unattributed_terms( $source_hostname );
		$unattributed_total       = $unattributed_posts + $unattributed_attachments + $unattributed_users + $unattributed_terms;
		if ( $unattributed_total > 0 ) {
			Logger::instance()->log(
				Logger::OUTPUT_BOTH,
				LogLevel::WARNING,
				sprintf(
					'Found %d objects without old_id meta for source %s (posts and CPTs: %d, attachments: %d, users: %d, terms: %d). Consider running `attribute-initial-content` if you wish to match these objects during migration.',
					$unattributed_total,
					$source_hostname,
					$unattributed_posts,
					$unattributed_attachments,
					$unattributed_users,
					$unattributed_terms
				)
			);
		}

		// Get post types other than attachments.
		$post_types_non_attachments = $post_types;
		$key                        = array_search( 'attachment', $post_types_non_attachments );
		if ( false !== $key ) {
			unset( $post_types_non_attachments[ $key ] );
			$post_types_non_attachments = array_values( $post_types_non_attachments );
		}

		// Get already migrated old_id=>new_id mappings for posts and CPTs, attachments, users, and terms (more memory efficient).
		$post_old_id_map       = $this->logic->get_imported_post_id_mapping_from_db( $source_hostname, $post_types_non_attachments );
		$attachment_old_id_map = $this->logic->get_imported_attachment_id_map_from_db( $source_hostname );
		$user_old_id_map       = $this->logic->get_imported_user_id_mapping_from_db( $source_hostname );
		$term_old_id_map       = $this->logic->get_imported_term_id_mapping_from_db( $source_hostname );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		try {
			// Query live DB for posts and CPTs.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Searching live DB for CPTs: %s ...', implode( ',', $post_types_non_attachments ) ) );
			$results_live_posts  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );
			$results_local_posts = $this->logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Check new objects.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %s total from live site, checking for new ones...', count( $results_live_posts ) ) );
			$new_live_ids = $this->logic->filter_new_live_ids( $results_live_posts, $post_old_id_map );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d new IDs found.', count( $new_live_ids ) ) );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Check modified objects -- according to the Migration Data Consistency Standard -- these will get reimported fully.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for content which was modified on live (including status, author, thumbnail, taxonomies)...' );
			$modified_live_ids = $this->logic->filter_modified_live_ids(
				$results_live_posts,
				$results_local_posts,
				$post_old_id_map,
				$live_table_prefix,
				$user_old_id_map,
				$attachment_old_id_map,
				$term_old_id_map
			);
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d modified IDs found.', count( $modified_live_ids ) ) );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Query live DB for attachments.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Searching live DB for attachments ...' );
			$results_live_attachments  = $this->logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );
			MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

			// Check new attachments.
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Fetched %s total from live site, checking for new ones...', count( $results_live_attachments ) ) );
			$new_live_attachment_ids = $this->logic->filter_new_live_ids( $results_live_attachments, $attachment_old_id_map );
			$new_live_ids            = array_merge( $new_live_ids, $new_live_attachment_ids );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d new IDs found.', count( $new_live_attachment_ids ) ) );

		} catch ( \Exception $e ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $e->getMessage() );
			throw $e;
		}

		// Write new IDs to run-state file.
		if ( count( $new_live_ids ) > 0 ) {
			$this->run_state->write_new_ids( $new_live_ids );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'List of new IDs to migrate stored to run-state file %s', RunState::FILE_NEW_IDS ) );
		}

		// Write modified IDs to run-state file.
		if ( count( $modified_live_ids ) > 0 ) {
			$this->run_state->write_modified_ids( $modified_live_ids );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, sprintf( 'List of modified IDs to reimport stored to run-state file %s', RunState::FILE_MODIFIED_IDS ) );
		}

		// Save manifest.json with migration TOC.
		$manifest = [
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'source_hostname'   => $source_hostname,
			'live_table_prefix' => $live_table_prefix,
			'counts'            => [
				'new_ids'      => count( $new_live_ids ),
				'modified_ids' => count( $modified_live_ids ),
			],
		];
		$this->run_state->write_manifest( $manifest );
	}

	/**
	 * Callable for `newspack-content-diff-migrator migrate-live-content`.
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
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::INFO, sprintf( 'Starting %s | source hostname: %s', __FUNCTION__, $source_hostname ) );

		// Set instance properties.
		$this->live_table_prefix = $live_table_prefix;
		if ( null === $this->run_state ) {
			$this->run_state = new RunState( rtrim( $data_dir, '/' ) . '/' . $source_hostname . '/run-state' );
		}

		// Default taxonomies which are migrated are defined here.
		$taxonomies_to_migrate = isset( $assoc_args['custom-taxonomies-csv'] ) ? explode( ',', $assoc_args['custom-taxonomies-csv'] ) : [ 'category', 'post_tag', 'author' ];
		// In case some custom taxonomies were provided, but category,post_tag,author were not among those, warn the user that they won't be migrated and ask for confirmation to continue.
		if ( ! empty( $assoc_args['custom-taxonomies-csv'] ) ) {
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

		// List all the custom taxonomies which exist in Live DB for user's overview.
		// phpcs:ignore -- table prefix string value was escaped.
		$live_table_prefix_escaped = esc_sql( $live_table_prefix );
		$live_taxonomies = $wpdb->get_col( "SELECT DISTINCT( taxonomy ) FROM {$live_table_prefix_escaped}term_taxonomy ;" ); // phpcs:ignore -- table prefix string value was escaped.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Here are all the taxonomies which exist in the live DB: %s', "\n- " . implode( "\n- ", $live_taxonomies ) ) );

		// Validate hierarchical taxonomies have valid parents. If they don't they should be fixed first.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Validating all the taxonomies which will be migrated: %s', "\n- " . implode( "\n- ", $taxonomies_to_migrate ) ) );
		$taxonomies_to_migrate = $this->validate_and_fix_hierarchical_taxonomies( $taxonomies_to_migrate, $live_taxonomies );
		if ( empty( $taxonomies_to_migrate ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, 'No taxonomies to migrate found. Proceeding with migration to allow for edge cases, but please do check whether this was an actual issue when providing categories.' );
		}

		// Migrate all WP_Users (for WooComm data).
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Migrating all WP_Users...' );
		$inserted_wp_users_updates = $this->logic->migrate_all_users( $live_table_prefix, $source_hostname );
		Logger::instance()->log_brief_and_verbose( LogLevel::INFO, sprintf( 'Inserted %d WP_Users.', count( $inserted_wp_users_updates ) ), $inserted_wp_users_updates );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Get new IDs which will be migrated.
		$new_live_ids = $this->run_state->get_new_ids();
		if ( null === $new_live_ids ) {
			$message = sprintf( 'Run-state file %s not found or empty.', RunState::FILE_NEW_IDS );
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, $message );
			throw new \RuntimeException( esc_html( $message ) );
		} elseif ( empty( $new_live_ids ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'No new posts to migrate.' );
			// Continue to allow modified IDs to be processed.
		}

		// Process modified IDs -- delete them, then reimport.
		// Get map (old => new) modified IDs.
		$modified_ids_map = $this->run_state->get_modified_ids_map();
		if ( null !== $modified_ids_map && ! empty( $modified_ids_map ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Deleting %s modified posts before they are reimported...', count( $modified_ids_map ) ) );
			
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
					Logger::instance()->log_brief_and_verbose( LogLevel::ERROR, sprintf( 'Failed to delete modified post local ID %d during reimport, this post will not be reimported/refreshed', $id ), $context );
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

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating Post parent IDs...' );
		$this->update_post_parent_ids( $new_live_ids, $imported_posts_data, $source_hostname );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating Featured images IDs...' );
		$this->update_featured_image_ids( $imported_posts_data, $source_hostname );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Updating attachment IDs in block content...' );
		$this->update_attachment_ids_in_blocks( $imported_posts_data );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Recalculate counts for all migrated taxonomies.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Recalculating term counts for migrated taxonomies...' );
		$this->recalculate_term_counts( $taxonomies_to_migrate );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		// Migration Data Consistency Standard: Update modified properties of migrated objects.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for modified user properties...' );
		$user_updates = $this->logic->update_modified_users( $live_table_prefix, $source_hostname );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Checked %d users, updated %d.', $user_updates['checked'], $user_updates['updated'] ) );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for modified attachment properties...' );
		$attachment_updates = $this->logic->update_modified_attachments( $live_table_prefix, $source_hostname );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Checked %d attachments, updated %d.', $attachment_updates['checked'], $attachment_updates['updated'] ) );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Checking for modified term properties (category/post_tag)...' );
		$term_updates = $this->logic->update_modified_terms( $live_table_prefix, $source_hostname, [ 'category', 'post_tag' ] );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( 'Checked %d terms, updated %d.', $term_updates['checked'], $term_updates['updated'] ) );
		MemoryCleanupHook::cleanup( $this->test_env ? 0 : 1 );

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'All done migrating content! 🙌 ' );

		// Display info about available logs.
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::INFO, 'Check the logs for more details:' );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '- debug/action log: %s', Logger::instance()->get_log_file_name() ) );
		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '- manifest: %s', RunState::FILE_MANIFEST ) );

		wp_cache_flush();
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

		Logger::instance()->init( __FUNCTION__ );
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

		$tables_with_differing_collations = $this->db->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );

		if ( ! empty( $tables_with_differing_collations ) ) {
			ob_start();
			\WP_CLI\Utils\format_items( 'table', $tables_with_differing_collations, array_keys( $tables_with_differing_collations[0] ) );
			$output = ob_get_clean();
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, $output );

		}

		Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, "Now fixing $live_table_prefix tables collations..." );
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
		foreach ( $tables_with_differing_collations as $result ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'Addressing ' . $result['table'] . ' table...' );
			$this->db->copy_table_data_using_proper_collation( $live_table_prefix, $result['table'], $records_per_transaction, $sleep_in_seconds, $backup_prefix );
		}
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
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::WARNING, sprintf( 'Taxonomy %s not found in live DB and will not be migrated.', $taxonomy_to_migrate ) );
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
		$live_ids_to_import       = array_values( array_diff( $new_live_ids, array_keys( $already_imported_ids_map ) ) );
		if ( empty( $live_ids_to_import ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts were already imported, moving on.' );
			return $imported_posts_data;
		}
		if ( count( $already_imported_ids_map ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d of %d IDs were already imported, continuing from there...', count( $already_imported_ids_map ), count( $new_live_ids ) ) );
		}

		// Import posts.
		$progress = new Progress( count( $live_ids_to_import ) );
		foreach ( $live_ids_to_import as $key_live_id => $id_live ) {
			// Output progress by 10%.
			$progress_milestone = $progress->tick( $key_live_id + 1 );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( $progress_milestone ) );
			}

			// Import single post via Logic.
			try {
				$result                = $this->logic->import_single_post(
					(int) $id_live,
					$this->live_table_prefix,
					$taxonomies_to_migrate,
					$source_hostname
				);
				$imported_posts_data[] = $result;

				// Log imported post to run-state for resume capability.
				$this->run_state->append_imported_post( $result );
			} catch ( \Exception $e ) {
				Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::ERROR, sprintf( 'import_posts error id_old=%d : %s', $id_live, $e->getMessage() ) );
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::WARNING, sprintf( 'Error importing Live ID %d (details in log file)', $id_live ) );
				// Continue importing other posts.
			}
		}
		if ( $progress->finish() ) {
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( 100 ) );
		}

		// Flush the cache for DB updates to take effect.
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
	private function update_post_parent_ids( array $all_live_posts_ids, array $imported_posts_data, string $source_hostname ): void {
		global $wpdb;

		// Get IDs which already had their post_parent updated, and skip them.
		$already_updated_ids_map     = $this->run_state->get_updated_parents_post_ids_map();
		$live_ids_for_parents_update = array_values( array_diff( $all_live_posts_ids, array_keys( $already_updated_ids_map ) ) );
		if ( empty( $live_ids_for_parents_update ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts already had their post_parent updated, moving on.' );
			return;
		}
		if ( count( $already_updated_ids_map ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d of %d post_parent IDs were already updated, continuing from there...', count( $already_updated_ids_map ), count( $all_live_posts_ids ) ) );
		}

		// Build map of all imported IDs (old => new) from imported_posts_data.
		$imported_ids_map = [];
		foreach ( $imported_posts_data as $entry ) {
			$imported_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		// Update parent IDs.
		$progress = new Progress( count( $live_ids_for_parents_update ) );
		foreach ( $live_ids_for_parents_update as $key_id_old => $id_old ) {
			// Output progress by 10%.
			$progress_milestone = $progress->tick( $key_id_old + 1 );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( $progress_milestone ) );
			}

			// Get new local Post ID.
			$id_new = $imported_ids_map[ $id_old ] ?? null;

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
			// 3/3 - If it can't be found, set parent to 0 and log error. This might be legit, e.g. the parent object being a
			// post_type different than the supported post type, or an invalid relationship in live DB if post_parent object is actually missing.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = 0;
				Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::ERROR, sprintf( 'update_post_parent_ids error, $id_old=%s, $id_new=%s, $parent_id_old=%s, $parent_id_new is 0.', $id_old, $id_new, $parent_id_old ) );
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
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( 100 ) );
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
	private function update_featured_image_ids( array $imported_posts_data, string $source_hostname ): void {

		// Get ID map of all imported post types other than Attachments (Posts, Pages, etc). Keys are old IDs, values are new IDs.
		$imported_nonattachment_ids_map = $this->filter_post_type_from_imported_posts_data( $imported_posts_data, 'attachment', true );

		// Get IDs which already had featured images updated, and skip them.
		$already_updated_ids_map     = $this->run_state->get_updated_featured_image_post_ids_map();
		$ids_map_for_featured_update = array_diff_key( $imported_nonattachment_ids_map, $already_updated_ids_map );
		if ( empty( $ids_map_for_featured_update ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts already had their featured images updated, moving on.' );
			return;
		}
		if ( count( $already_updated_ids_map ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d of %d featured image IDs were already updated, continuing from there...', count( $already_updated_ids_map ), count( $imported_nonattachment_ids_map ) ) );
		}

		/**
		 * Get a map of all migrated Attachments from the DB, not from the run-state.
		 * That's necessary because a newly imported post might use an old featured image attachment which existed in DB before this migration.
		 * The RunState only contains the current batch of imported objects/attachments. Fetching from DB will get us all the migrated ones.
		 * Note that it is also due performanc reasons to fetch all imported attachments in a single DB query, rather than fetching them one by one in logic's update_featured_image().
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->logic->get_imported_attachment_id_map_from_db( $source_hostname );

		// Update featured images.
		$progress = new Progress( count( $ids_map_for_featured_update ) );
		$step     = 0;
		foreach ( $ids_map_for_featured_update as $id_old => $id_new ) {
			// Output progress by 10%.
			++$step;
			$progress_milestone = $progress->tick( $step );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( $progress_milestone ) );
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
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( 100 ) );
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
	private function update_attachment_ids_in_blocks( array $imported_posts_data ): void {

		// Get ID maps of imported Attachments, and non-attachments (Posts, Pages, etc).
		$imported_attachment_ids_map    = $this->filter_post_type_from_imported_posts_data( $imported_posts_data, 'attachment' );
		$imported_nonattachment_ids_map = $this->filter_post_type_from_imported_posts_data( $imported_posts_data, 'attachment', true );

		// Get IDs which already had block attachment IDs updated, and skip them.
		$already_updated_ids_map   = $this->run_state->get_updated_block_post_ids_map();
		$ids_map_for_blocks_update = array_diff_key( $imported_nonattachment_ids_map, $already_updated_ids_map );
		if ( empty( $ids_map_for_blocks_update ) ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, 'All posts already had their blocks\' attachment IDs updated, moving on.' );
			return;
		}
		if ( count( $already_updated_ids_map ) > 0 ) {
			Logger::instance()->log( Logger::OUTPUT_BOTH, LogLevel::DEBUG, sprintf( '%d of %d posts already had their blocks\' IDs updated, continuing from there...', count( $already_updated_ids_map ), count( $imported_nonattachment_ids_map ) ) );
		}

		// Update block attachment IDs.
		$progress = new Progress( count( $ids_map_for_blocks_update ) );
		$step     = 0;
		foreach ( $ids_map_for_blocks_update as $id_old => $id_new ) {
			// Output progress by 10%.
			++$step;
			$progress_milestone = $progress->tick( $step );
			if ( $progress_milestone ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( $progress_milestone ) );
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
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( 100 ) );
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
	 * Util method to filter IDs of certain $post_type from the $imported_posts_data array.
	 *
	 * @param array  $imported_posts_data Imported posts log data.
	 * @param string $post_type           Post type to filter by (e.g., 'attachment').
	 * @param bool   $exclude             If true, excludes the specified post type instead of including only it.
	 *
	 * @return array IDs map, keys are old/live IDs, values are new/local IDs.
	 */
	private function filter_post_type_from_imported_posts_data( array $imported_posts_data, string $post_type, bool $exclude = false ): array {
		$map = [];
		foreach ( $imported_posts_data as $entry ) {
			$type_matches = ( $entry['post_type'] ?? '' ) === $post_type;
			if ( $exclude ? ! $type_matches : $type_matches ) {
				$map[ $entry['id_old'] ] = $entry['id_new'];
			}
		}
		return $map;
	}
}
