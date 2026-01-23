<?php
/**
 * Parent class for integration tests with common functionality.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Command\ContentDiffMigrator;
use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\Logger;
use WP_UnitTestCase;

/**
 * Parent integration test class.
 *
 * @group integration
 */
class IntegrationTestCase extends WP_UnitTestCase {

	/**
	 * ContentDiffMigrator command instance.
	 *
	 * @var ContentDiffMigrator
	 */
	protected ContentDiffMigrator $command;

	/**
	 * ContentDiffLogic instance.
	 *
	 * @var ContentDiffLogic
	 */
	protected ContentDiffLogic $logic;

	/**
	 * Live table prefix for testing.
	 *
	 * @var string
	 */
	protected string $live_table_prefix = 'cdiff_';

	/**
	 * Temporary data directory for run-state files.
	 *
	 * @var string
	 */
	protected string $temp_data_dir;

	/**
	 * Source hostname for testing.
	 *
	 * @var string
	 */
	protected string $source_hostname = 'test-1.example.com';

	/**
	 * Second source hostname for multi-source tests.
	 *
	 * @var string
	 */
	protected string $source_hostname_2 = 'test-2.example.com';

	/**
	 * Fixtures directory path.
	 *
	 * @var string
	 */
	protected string $fixtures_dir;

	/**
	 * RunState instance.
	 *
	 * @var RunState
	 */
	protected RunState $run_state;

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// Disable Logger (use NullLogger).
		Logger::configure( false );

		// Create cdiff_* tables mirroring WP core tables.
		$this->create_live_tables();

		// Set up fixtures directory.
		$this->fixtures_dir = dirname( __DIR__ ) . '/fixtures';

		// Create temp run-state directory.
		$this->temp_data_dir = sys_get_temp_dir() . '/cdiff-test-' . uniqid();

		// Initialize logic.
		$this->logic = new ContentDiffLogic( $wpdb );

		// Initialize command with test_env=true.
		$this->command = new ContentDiffMigrator( true );

		// Create and inject RunState.
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down(): void {
		// Drop live tables.
		$this->drop_live_tables();

		// Cleanup temp directory.
		$this->cleanup_temp_dir( $this->temp_data_dir );

		parent::tear_down();
	}

	/**
	 * Creates cdiff_* tables mirroring WP core tables.
	 */
	protected function create_live_tables(): void {
		global $wpdb;

		$tables = [
			'comments'           => $wpdb->comments,
			'commentmeta'        => $wpdb->commentmeta,
			'links'              => $wpdb->links,
			'options'            => $wpdb->options,
			'posts'              => $wpdb->posts,
			'postmeta'           => $wpdb->postmeta,
			'users'              => $wpdb->users, // phpcs:ignore -- WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users.
			'usermeta'           => $wpdb->usermeta,
			'terms'              => $wpdb->terms,
			'termmeta'           => $wpdb->termmeta,
			'term_taxonomy'      => $wpdb->term_taxonomy,
			'term_relationships' => $wpdb->term_relationships,
		];

		foreach ( $tables as $suffix => $source_table ) {
			$live_table = $this->live_table_prefix . $suffix;
			$wpdb->query( "DROP TABLE IF EXISTS {$live_table}" ); // phpcs:ignore
			$wpdb->query( "CREATE TABLE {$live_table} LIKE {$source_table}" ); // phpcs:ignore
		}
	}

	/**
	 * Drops live site tables.
	 */
	protected function drop_live_tables(): void {
		global $wpdb;

		$tables = [ 'commentmeta', 'comments', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users' ];

		foreach ( $tables as $suffix ) {
			$live_table = $this->live_table_prefix . $suffix;
			$wpdb->query( "DROP TABLE IF EXISTS {$live_table}" ); // phpcs:ignore
		}
	}

	/**
	 * Recursively removes a directory and its contents.
	 * Made for RunState directory cleanup.
	 *
	 * @param string $dir Directory path.
	 */
	protected function cleanup_temp_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->cleanup_temp_dir( $path ) : unlink( $path );
		}
		rmdir( $dir ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_rmdir.
	}

	/**
	 * Loads a JSON fixture file.
	 *
	 * @param string $fixture_name Fixture name (without .json extension).
	 *
	 * @return array Decoded JSON data.
	 */
	protected function load_fixture( string $fixture_name ): array {
		$path = $this->fixtures_dir . '/live-tables/' . $fixture_name . '.json';
		if ( ! file_exists( $path ) ) {
			$this->fail( "Fixture not found: {$path}" );
		}
		$json = file_get_contents( $path ); // phpcs:ignore

		return json_decode( $json, true );
	}

	/**
	 * Inserts fixture data into live tables.
	 *
	 * @param array $fixture Fixture data from load_fixture().
	 */
	protected function insert_live_data( array $fixture ): void {
		global $wpdb;

		$table_map = [
			'post'               => 'posts',
			'posts'              => 'posts',
			'postmeta'           => 'postmeta',
			'users'              => 'users',
			'usermeta'           => 'usermeta',
			'comments'           => 'comments',
			'commentmeta'        => 'commentmeta',
			'terms'              => 'terms',
			'termmeta'           => 'termmeta',
			'term_taxonomy'      => 'term_taxonomy',
			'term_relationships' => 'term_relationships',
		];

		foreach ( $table_map as $fixture_key => $table_suffix ) {
			if ( empty( $fixture[ $fixture_key ] ) ) {
				continue;
			}

			$data = $fixture[ $fixture_key ];
			// Handle single row (post) vs array of rows.
			if ( 'post' === $fixture_key || 'posts' === $fixture_key ) {
				$rows = isset( $data['ID'] ) ? [ $data ] : $data;
			} else {
				$rows = isset( $data[0] ) ? $data : [ $data ];
			}

			foreach ( $rows as $row ) {
				unset( $row['_description'] );
				$wpdb->insert( $this->live_table_prefix . $table_suffix, $row ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			}
		}
	}

	/**
	 * Creates a basic post fixture array.
	 *
	 * @param array $overrides Optional field overrides.
	 *
	 * @return array Post row data.
	 */
	protected function create_post_fixture( array $overrides = [] ): array {
		$unique = uniqid();
		return array_merge(
			[
				'ID'                    => 100 + rand( 1, 9999 ), // phpcs:ignore -- WordPress.WP.AlternativeFunctions.rand_rand.
				'post_author'           => 1,
				'post_date'             => '2024-01-15 10:00:00',
				'post_date_gmt'         => '2024-01-15 10:00:00',
				'post_content'          => '<p>Test content ' . $unique . '</p>',
				'post_title'            => 'Test Post ' . $unique,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'open',
				'post_password'         => '',
				'post_name'             => 'test-post-' . $unique,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2024-01-15 10:00:00',
				'post_modified_gmt'     => '2024-01-15 10:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://test.local/?p=' . $unique,
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => 0,
			],
			$overrides
		);
	}

	/**
	 * Creates a basic user fixture array.
	 *
	 * @param array $overrides Optional field overrides.
	 *
	 * @return array User row data.
	 */
	protected function create_user_fixture( array $overrides = [] ): array {
		$unique = uniqid();
		return array_merge(
			[
				'ID'                  => 100 + rand( 1, 9999 ), // phpcs:ignore -- WordPress.WP.AlternativeFunctions.rand_rand.
				'user_login'          => 'testuser_' . $unique,
				'user_pass'           => '$P$BJTe8iBJUuOui0O.A4JDRkLMfqqraF.',
				'user_nicename'       => 'testuser-' . $unique,
				'user_email'          => 'testuser_' . $unique . '@test.local',
				'user_url'            => '',
				'user_registered'     => '2024-01-01 00:00:00',
				'user_activation_key' => '',
				'user_status'         => 0,
				'display_name'        => 'Test User ' . $unique,
			],
			$overrides
		);
	}

	/**
	 * Creates a basic term fixture array.
	 *
	 * @param array $overrides Optional field overrides.
	 *
	 * @return array Term row data.
	 */
	protected function create_term_fixture( array $overrides = [] ): array {
		$unique = uniqid();
		return array_merge(
			[
				'term_id'    => 100 + rand( 1, 9999 ), // phpcs:ignore -- WordPress.WP.AlternativeFunctions.rand_rand.
				'name'       => 'Test Term ' . $unique,
				'slug'       => 'test-term-' . $unique,
				'term_group' => 0,
			],
			$overrides
		);
	}

	/**
	 * Creates a basic term_taxonomy fixture array.
	 *
	 * @param array $overrides Optional field overrides.
	 *
	 * @return array Term taxonomy row data.
	 */
	protected function create_term_taxonomy_fixture( array $overrides = [] ): array {
		return array_merge(
			[
				'term_taxonomy_id' => 100 + rand( 1, 9999 ), // phpcs:ignore -- WordPress.WP.AlternativeFunctions.rand_rand.
				'term_id'          => 1,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			],
			$overrides
		);
	}

	/**
	 * Calls cmd_migrate_live_content with given arguments.
	 *
	 * @param array $assoc_args Associative arguments for the command.
	 */
	protected function run_migrate_command( array $assoc_args = [] ): void {
		$default_args = [
			'data-dir'          => $this->temp_data_dir,
			'live-table-prefix' => $this->live_table_prefix,
			'source-hostname'   => $this->source_hostname,
		];

		$this->command->cmd_migrate_live_content( [], array_merge( $default_args, $assoc_args ) );
	}

	/**
	 * Calls cmd_search_new_content_on_live with given arguments.
	 *
	 * @param array $assoc_args Associative arguments for the command.
	 */
	protected function run_search_command( array $assoc_args = [] ): void {
		$default_args = [
			'data-dir'          => $this->temp_data_dir,
			'live-table-prefix' => $this->live_table_prefix,
			'source-hostname'   => $this->source_hostname,
		];

		$this->command->cmd_search_new_content_on_live( [], array_merge( $default_args, $assoc_args ) );
	}

	/**
	 * Calls cmd_attribute_match_local_to_live_tables with given arguments.
	 *
	 * @param array $assoc_args Associative arguments for the command.
	 */
	protected function run_attribute_match_local_to_live_tables( array $assoc_args = [] ): void {
		$default_args = [
			'live-table-prefix' => $this->live_table_prefix,
			'source-hostname'   => $this->source_hostname,
			'data-dir'          => $this->temp_data_dir,
		];

		$this->command->cmd_attribute_match_local_to_live_tables( [], array_merge( $default_args, $assoc_args ) );
	}

	/**
	 * Gets the old_id meta key for the test source hostname.
	 *
	 * @param string|null $hostname Optional hostname override.
	 *
	 * @return string Meta key.
	 */
	protected function get_old_id_meta_key( ?string $hostname = null ): string {
		return ContentDiffLogic::get_old_id_meta_key( $hostname ?? $this->source_hostname );
	}
}
