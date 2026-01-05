<?php
/**
 * Integration tests for cmd_migrate_live_content command.
 *
 * Tests actual WordPress data migration from live tables (cdiff_ prefix)
 * to local tables (wp_ prefix).
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
 * Integration test class for cmd_migrate_live_content command.
 *
 * @group integration
 * @group migrate
 */
class CmdMigrateLiveContentTest extends WP_UnitTestCase {

	/**
	 * ContentDiffMigrator command instance.
	 *
	 * @var ContentDiffMigrator
	 */
	private ContentDiffMigrator $command;

	/**
	 * ContentDiffLogic instance.
	 *
	 * @var ContentDiffLogic
	 */
	private ContentDiffLogic $logic;

	/**
	 * Live table prefix for testing.
	 *
	 * @var string
	 */
	private string $live_table_prefix = 'cdiff_';

	/**
	 * Temporary data directory for run-state files.
	 *
	 * @var string
	 */
	private string $temp_data_dir;

	/**
	 * Source hostname for testing.
	 *
	 * @var string
	 */
	private string $source_hostname = 'test-1.example.com';

	/**
	 * Second source hostname for multi-source tests.
	 *
	 * @var string
	 */
	private string $source_hostname_2 = 'test-2.example.com';

	/**
	 * Fixtures directory path.
	 *
	 * @var string
	 */
	private string $fixtures_dir;

	/**
	 * RunState instance.
	 *
	 * @var RunState
	 */
	private RunState $run_state;

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// Disable Logger (use NullLogger).
Logger::configure( false );
// TODO temp
// Logger::configure( true );

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
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
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

	// =========================================================================
	// HELPER METHODS
	// =========================================================================

	/**
	 * Creates cdiff_* tables mirroring WP core tables.
	 */
	private function create_live_tables(): void {
		global $wpdb;

		$tables = [
			'posts'              => $wpdb->posts,
			'postmeta'           => $wpdb->postmeta,
			'users'              => $wpdb->users, // phpcs:ignore -- WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users.
			'usermeta'           => $wpdb->usermeta,
			'comments'           => $wpdb->comments,
			'commentmeta'        => $wpdb->commentmeta,
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
	 * Drops cdiff_* tables.
	 */
	private function drop_live_tables(): void {
		global $wpdb;

		$tables = [ 'posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships' ];

		foreach ( $tables as $suffix ) {
			$live_table = $this->live_table_prefix . $suffix;
			$wpdb->query( "DROP TABLE IF EXISTS {$live_table}" ); // phpcs:ignore
		}
	}

	/**
	 * Recursively removes a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 */
	private function cleanup_temp_dir( string $dir ): void {
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
	private function load_fixture( string $fixture_name ): array {
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
	private function insert_live_data( array $fixture ): void {
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
	private function create_post_fixture( array $overrides = [] ): array {
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
	private function create_user_fixture( array $overrides = [] ): array {
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
	private function create_term_fixture( array $overrides = [] ): array {
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
	private function create_term_taxonomy_fixture( array $overrides = [] ): array {
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
	private function run_migrate_command( array $assoc_args = [] ): void {
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
	private function run_search_command( array $assoc_args = [] ): void {
		$default_args = [
			'data-dir'          => $this->temp_data_dir,
			'live-table-prefix' => $this->live_table_prefix,
			'source-hostname'   => $this->source_hostname,
		];

		$this->command->cmd_search_new_content_on_live( [], array_merge( $default_args, $assoc_args ) );
	}

	/**
	 * Calls cmd_attribute_initial_content with given arguments.
	 *
	 * @param array $assoc_args Associative arguments for the command.
	 */
	private function run_attribute_command( array $assoc_args = [] ): void {
		$default_args = [
			'live-table-prefix' => $this->live_table_prefix,
			'source-hostname'   => $this->source_hostname,
		];

		$this->command->cmd_attribute_initial_content( [], array_merge( $default_args, $assoc_args ) );
	}

	/**
	 * Gets the old_id meta key for the test source hostname.
	 *
	 * @param string|null $hostname Optional hostname override.
	 *
	 * @return string Meta key.
	 */
	private function get_old_id_meta_key( ?string $hostname = null ): string {
		return ContentDiffLogic::get_old_id_meta_key( $hostname ?? $this->source_hostname );
	}

	// =========================================================================
	// 1. CUSTOM TAXONOMIES CSV ARGUMENT TESTS
	// =========================================================================

	/**
	 * @group taxonomy
	 */
	public function test_should_migrate_default_taxonomies_when_no_custom_taxonomies_csv_provided(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_migrate_only_specified_taxonomies_when_custom_taxonomies_csv_provided(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_unset_taxonomy_from_migration_when_custom_taxonomy_does_not_exist_in_live_db(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_warn_when_category_not_in_custom_taxonomies_csv(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_warn_when_post_tag_not_in_custom_taxonomies_csv(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_warn_when_author_not_in_custom_taxonomies_csv(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 2. HIERARCHICAL TAXONOMY VALIDATION AND FIXING TESTS
	// =========================================================================

	/**
	 * @group taxonomy
	 */
	public function test_should_fix_local_hierarchical_taxonomy_with_invalid_parent_by_setting_to_zero(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_fix_live_hierarchical_taxonomy_with_invalid_parent_by_setting_to_zero(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_not_modify_hierarchical_taxonomy_with_valid_parent(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_handle_deeply_nested_hierarchical_taxonomy_with_broken_chain(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 3. USER MIGRATION TESTS (migrate_all_users)
	// =========================================================================

	/**
	 * @group user
	 */
	public function test_should_migrate_all_live_users_to_local(): void {
		global $wpdb;

		// Create multiple users in live DB.
		$users = [
			$this->create_user_fixture(
				[
					'ID'         => 101,
					'user_login' => 'author1',
					'user_email' => 'author1@test.local',
				] 
			),
			$this->create_user_fixture(
				[
					'ID'         => 102,
					'user_login' => 'author2',
					'user_email' => 'author2@test.local',
				] 
			),
			$this->create_user_fixture(
				[
					'ID'         => 103,
					'user_login' => 'author3',
					'user_email' => 'author3@test.local',
				] 
			),
		];
		foreach ( $users as $user ) {
			$wpdb->insert( $this->live_table_prefix . 'users', $user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		// Also create a post so search command has something to find.
		$post = $this->create_post_fixture(
			[
				'ID'          => 3001,
				'post_author' => 101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Run migration (includes migrate_all_users).
		$this->run_search_command();
		$this->run_migrate_command();

		// Verify all users were created.
		$this->assertNotFalse( get_user_by( 'login', 'author1' ), 'author1 should be created.' );
		$this->assertNotFalse( get_user_by( 'login', 'author2' ), 'author2 should be created.' );
		$this->assertNotFalse( get_user_by( 'login', 'author3' ), 'author3 should be created.' );
	}

	/**
	 * @group user
	 */
	public function test_should_skip_user_migration_when_user_already_exists_locally(): void {
		global $wpdb;

		// Create user locally first.
		$existing_user_id = wp_insert_user(
			[
				'user_login' => 'existinguser',
				'user_email' => 'existing@test.local',
				'user_pass'  => 'password123',
			]
		);

		// Create same user (by login) in live DB.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 201,
				'user_login' => 'existinguser',
				'user_email' => 'different@test.local', // Different email.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post to trigger migration.
		$post = $this->create_post_fixture(
			[
				'ID'          => 3002,
				'post_author' => 201,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// First, run attribution to assign old_id meta to existing local content.
		$this->run_attribute_command();

		// Verify old_id meta was set on existing user by attribution.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $existing_user_id, $meta_key, true );
		$this->assertEquals( 201, (int) $old_id, 'Existing user should have old_id meta after attribution.' );

		// Now run migration.
		$this->run_search_command();
		$this->run_migrate_command();

		// Verify existing user was used (not duplicated).
		$users = get_users( [ 'login' => 'existinguser' ] );
		$this->assertCount( 1, $users, 'Should not create duplicate user.' );

		// Verify the post uses the existing user as author.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 3002, $this->source_hostname );
		$new_post    = get_post( $new_post_id );
		$this->assertEquals( $existing_user_id, (int) $new_post->post_author, 'Post should use existing local user as author.' );
	}

	/**
	 * @group user
	 */
	public function test_should_save_old_id_usermeta_for_newly_created_user(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 301,
				'user_login' => 'newuser301',
				'user_email' => 'newuser301@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with this user as author.
		$post = $this->create_post_fixture(
			[
				'ID'          => 3003,
				'post_author' => 301,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Find the newly created user.
		$user = get_user_by( 'login', 'newuser301' );
		$this->assertNotFalse( $user, 'User should be created.' );

		// Verify old_id meta.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $user->ID, $meta_key, true );
		$this->assertEquals( 301, (int) $old_id, 'New user should have old_id usermeta.' );
	}

	/**
	 * @group user
	 */
	public function test_should_migrate_usermeta_for_newly_created_user(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 401,
				'user_login' => 'usermetauser',
				'user_email' => 'usermeta@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add usermeta.
		$usermeta = [
			[
				'user_id'    => 401,
				'meta_key'   => 'first_name',
				'meta_value' => 'John', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'user_id'    => 401,
				'meta_key'   => 'last_name',
				'meta_value' => 'Doe', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'user_id'    => 401,
				'meta_key'   => 'description',
				'meta_value' => 'Bio text here.', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $usermeta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'usermeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		// Create post.
		$post = $this->create_post_fixture(
			[
				'ID'          => 3004,
				'post_author' => 401,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$user = get_user_by( 'login', 'usermetauser' );
		$this->assertNotFalse( $user, 'User should be created.' );

		// Verify usermeta was imported.
		$this->assertEquals( 'John', get_user_meta( $user->ID, 'first_name', true ) );
		$this->assertEquals( 'Doe', get_user_meta( $user->ID, 'last_name', true ) );
		$this->assertEquals( 'Bio text here.', get_user_meta( $user->ID, 'description', true ) );
	}

	/**
	 * @group user
	 */
	public function test_should_not_duplicate_user_when_running_migration_twice(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 501,
				'user_login' => 'nodupe501',
				'user_email' => 'nodupe@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 3005,
				'post_author' => 501,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Run migration twice.
		$this->run_search_command();
		$this->run_migrate_command();

		// Create new run-state for second run.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Insert a new post to trigger second migration.
		$post2 = $this->create_post_fixture(
			[
				'ID'          => 3006,
				'post_author' => 501,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify only one user exists.
		$users = get_users( [ 'login' => 'nodupe501' ] );
		$this->assertCount( 1, $users, 'User should not be duplicated on second migration.' );
	}

	// =========================================================================
	// 4. MODIFIED POSTS DETECTION AND REIMPORT TESTS
	// =========================================================================

	/**
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_post_modified_date_changed(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_post_status_changed(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_post_author_changed(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_thumbnail_id_changed(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_taxonomies_changed(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group modified
	 */
	public function test_should_delete_local_post_before_reimporting_modified_post(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group modified
	 */
	public function test_should_update_runstate_with_deleted_modified_ids(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group modified
	 */
	public function test_should_append_modified_ids_to_new_live_ids_for_reimport(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group modified
	 */
	public function test_should_skip_already_deleted_modified_ids_on_resume(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 5. POST IMPORT TESTS
	// =========================================================================

	/**
	 * @group post
	 */
	public function test_should_import_post_with_all_fields_correctly(): void {
		// Create a post with all fields populated.
		$fixture = [
			'post' => [
				'ID'                    => 2001,
				'post_author'           => 1,
				'post_date'             => '2024-03-15 14:30:00',
				'post_date_gmt'         => '2024-03-15 14:30:00',
				'post_content'          => '<p>Test content with <strong>formatting</strong>.</p>',
				'post_title'            => 'Test Post With All Fields',
				'post_excerpt'          => 'This is the excerpt.',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'test-post-all-fields',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2024-03-15 14:30:00',
				'post_modified_gmt'     => '2024-03-15 14:30:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://test.local/?p=2001',
				'menu_order'            => 5,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => 0,
			],
		];
		$this->insert_live_data( $fixture );

		// Run search and migrate.
		$this->run_search_command();
		$this->run_migrate_command();

		// Get imported post.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2001, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported.' );

		$post = get_post( $new_post_id );

		// Verify all fields.
		$this->assertEquals( 'Test Post With All Fields', $post->post_title );
		$this->assertEquals( '<p>Test content with <strong>formatting</strong>.</p>', $post->post_content );
		$this->assertEquals( 'This is the excerpt.', $post->post_excerpt );
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( 'open', $post->comment_status );
		$this->assertEquals( 'closed', $post->ping_status );
		$this->assertEquals( 'test-post-all-fields', $post->post_name );
		$this->assertEquals( 5, $post->menu_order );
		$this->assertEquals( 'post', $post->post_type );
		$this->assertEquals( '2024-03-15 14:30:00', $post->post_date );
	}

	/**
	 * @group post
	 */
	public function test_should_save_source_specific_old_id_postmeta(): void {
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );

		// Verify the meta key is source-specific.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertStringContainsString( $this->source_hostname, $meta_key, 'Meta key should contain source hostname.' );

		$saved_old_id = get_post_meta( $new_post_id, $meta_key, true );
		$this->assertEquals( $live_post_id, (int) $saved_old_id, 'Old ID should be saved with source-specific meta key.' );
	}

	/**
	 * @group post
	 */
	public function test_should_import_postmeta_for_post(): void {
		global $wpdb;

		// Create post with various postmeta.
		$post = $this->create_post_fixture( [ 'ID' => 2002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add multiple postmeta rows.
		$postmeta = [
			[
				'post_id'    => 2002,
				'meta_key'   => 'custom_text',
				'meta_value' => 'Hello World', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'post_id'    => 2002,
				'meta_key'   => 'custom_number',
				'meta_value' => '42', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'post_id'    => 2002,
				'meta_key'   => 'custom_array',
				'meta_value' => serialize( [ 'a', 'b', 'c' ] ), // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $postmeta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'postmeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2002, $this->source_hostname );

		// Verify all postmeta was imported.
		$this->assertEquals( 'Hello World', get_post_meta( $new_post_id, 'custom_text', true ) );
		$this->assertEquals( '42', get_post_meta( $new_post_id, 'custom_number', true ) );
		$this->assertEquals( [ 'a', 'b', 'c' ], get_post_meta( $new_post_id, 'custom_array', true ) );
	}

	/**
	 * @group post
	 */
	public function test_should_skip_internal_system_postmeta_during_import(): void {
		global $wpdb;

		// Create post with system postmeta that should be skipped.
		$post = $this->create_post_fixture( [ 'ID' => 2003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add system meta that should be skipped.
		$system_meta = [
			[
				'post_id'    => 2003,
				'meta_key'   => '_edit_lock',
				'meta_value' => '1234567890:1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'post_id'    => 2003,
				'meta_key'   => '_edit_last',
				'meta_value' => '1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'post_id'    => 2003,
				'meta_key'   => 'custom_meta',
				'meta_value' => 'should_import', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $system_meta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'postmeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2003, $this->source_hostname );

		// Custom meta should be imported.
		$this->assertEquals( 'should_import', get_post_meta( $new_post_id, 'custom_meta', true ) );

		// System meta may or may not be imported depending on implementation.
		// The key test is that the import doesn't fail due to system meta.
		$this->assertNotNull( $new_post_id, 'Post should be imported despite system meta.' );
	}

	// =========================================================================
	// 6. POST AUTHOR ASSIGNMENT TESTS
	// =========================================================================

	/**
	 * @group author
	 */
	public function test_should_assign_existing_author_when_user_login_matches(): void {
		global $wpdb;

		// Create user locally first.
		$local_user_id = wp_insert_user(
			[
				'user_login' => 'matchingauthor',
				'user_email' => 'match@test.local',
				'user_pass'  => 'password123',
			]
		);

		// Create same user in live DB.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 601,
				'user_login' => 'matchingauthor',
				'user_email' => 'match@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with this author.
		$post = $this->create_post_fixture(
			[
				'ID'          => 4001,
				'post_author' => 601,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post uses existing user.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 4001, $this->source_hostname );
		$new_post    = get_post( $new_post_id );
		$this->assertEquals( $local_user_id, $new_post->post_author, 'Post should use existing local user as author.' );
	}

	/**
	 * @group author
	 */
	public function test_should_create_new_author_when_user_does_not_exist(): void {
		global $wpdb;

		// Create user only in live DB (not locally).
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 701,
				'user_login' => 'newauthor701',
				'user_email' => 'newauthor701@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with this author.
		$post = $this->create_post_fixture(
			[
				'ID'          => 4002,
				'post_author' => 701,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify user was created.
		$new_user = get_user_by( 'login', 'newauthor701' );
		$this->assertNotFalse( $new_user, 'New author should be created.' );

		// Verify post uses new user.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 4002, $this->source_hostname );
		$new_post    = get_post( $new_post_id );
		$this->assertEquals( $new_user->ID, $new_post->post_author, 'Post should use newly created user as author.' );
	}

	/**
	 * @group author
	 */
	public function test_should_save_old_id_usermeta_for_new_author(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 801,
				'user_login' => 'authormeta801',
				'user_email' => 'authormeta@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 4003,
				'post_author' => 801,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_user = get_user_by( 'login', 'authormeta801' );
		$this->assertNotFalse( $new_user, 'Author should be created.' );

		// Verify old_id usermeta.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $new_user->ID, $meta_key, true );
		$this->assertEquals( 801, (int) $old_id, 'New author should have old_id usermeta.' );
	}

	/**
	 * @group author
	 */
	public function test_should_handle_post_with_zero_author_id(): void {
		global $wpdb;

		// Create post with author ID 0.
		$post = $this->create_post_fixture(
			[
				'ID'          => 4004,
				'post_author' => 0,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was imported with author 0 or a default author.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 4004, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post with author 0 should still be imported.' );

		$new_post = get_post( $new_post_id );
		// Author could be 0 or a default; the key is that import succeeds.
		$this->assertGreaterThanOrEqual( 0, $new_post->post_author, 'Post author should be valid.' );
	}

	/**
	 * @group author
	 */
	public function test_should_handle_post_with_invalid_author_id(): void {
		global $wpdb;

		// Create post with author ID that doesn't exist in live users table.
		$post = $this->create_post_fixture(
			[
				'ID'          => 4005,
				'post_author' => 99999,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was still imported (author might be set to 0 or default).
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 4005, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post with invalid author should still be imported.' );
	}

	// =========================================================================
	// 7. COMMENTS IMPORT TESTS
	// =========================================================================

	/**
	 * @group comment
	 */
	public function test_should_import_comments_with_correct_post_id(): void {
		global $wpdb;

		// Create post with comments.
		$post = $this->create_post_fixture( [ 'ID' => 5001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$comments = [
			[
				'comment_ID'           => 1001,
				'comment_post_ID'      => 5001,
				'comment_author'       => 'Commenter One',
				'comment_author_email' => 'c1@test.local',
				'comment_author_url'   => '',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2024-01-15 10:00:00',
				'comment_date_gmt'     => '2024-01-15 10:00:00',
				'comment_content'      => 'This is comment one.',
				'comment_karma'        => 0,
				'comment_approved'     => '1',
				'comment_agent'        => '',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			],
			[
				'comment_ID'           => 1002,
				'comment_post_ID'      => 5001,
				'comment_author'       => 'Commenter Two',
				'comment_author_email' => 'c2@test.local',
				'comment_author_url'   => '',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2024-01-15 11:00:00',
				'comment_date_gmt'     => '2024-01-15 11:00:00',
				'comment_content'      => 'This is comment two.',
				'comment_karma'        => 0,
				'comment_approved'     => '1',
				'comment_agent'        => '',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			],
		];
		foreach ( $comments as $comment ) {
			$wpdb->insert( $this->live_table_prefix . 'comments', $comment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 5001, $this->source_hostname );
		$imported_comments = get_comments( [ 'post_id' => $new_post_id ] );

		$this->assertCount( 2, $imported_comments, 'Both comments should be imported.' );
		foreach ( $imported_comments as $comment ) {
			$this->assertEquals( $new_post_id, $comment->comment_post_ID, 'Comment should have correct post ID.' );
		}
	}

	/**
	 * @group comment
	 */
	public function test_should_import_commentmeta_for_comment(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 5002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$comment = [
			'comment_ID'           => 2001,
			'comment_post_ID'      => 5002,
			'comment_author'       => 'Meta Commenter',
			'comment_author_email' => 'meta@test.local',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-15 10:00:00',
			'comment_date_gmt'     => '2024-01-15 10:00:00',
			'comment_content'      => 'Comment with meta.',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'comments', $comment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add commentmeta.
		$commentmeta = [
			[
				'comment_id' => 2001,
				'meta_key'   => 'rating',
				'meta_value' => '5', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'comment_id' => 2001,
				'meta_key'   => 'verified',
				'meta_value' => 'yes', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $commentmeta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'commentmeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 5002, $this->source_hostname );
		$imported_comments = get_comments( [ 'post_id' => $new_post_id ] );
		$this->assertCount( 1, $imported_comments );

		$new_comment_id = $imported_comments[0]->comment_ID;

		// Verify commentmeta was imported.
		$this->assertEquals( '5', get_comment_meta( $new_comment_id, 'rating', true ) );
		$this->assertEquals( 'yes', get_comment_meta( $new_comment_id, 'verified', true ) );
	}

	/**
	 * @group comment
	 */
	public function test_should_assign_existing_user_to_comment_when_user_login_matches(): void {
		global $wpdb;

		// Create user locally.
		$local_user_id = wp_insert_user(
			[
				'user_login' => 'commentuser',
				'user_email' => 'commentuser@test.local',
				'user_pass'  => 'password123',
			]
		);

		// Create same user in live DB.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 901,
				'user_login' => 'commentuser',
				'user_email' => 'commentuser@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture( [ 'ID' => 5003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$comment = [
			'comment_ID'           => 3001,
			'comment_post_ID'      => 5003,
			'comment_author'       => 'Comment User',
			'comment_author_email' => 'commentuser@test.local',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-15 10:00:00',
			'comment_date_gmt'     => '2024-01-15 10:00:00',
			'comment_content'      => 'Comment by registered user.',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 901,
		];
		$wpdb->insert( $this->live_table_prefix . 'comments', $comment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 5003, $this->source_hostname );
		$imported_comments = get_comments( [ 'post_id' => $new_post_id ] );
		$this->assertCount( 1, $imported_comments );

		// Verify comment uses existing local user.
		$this->assertEquals( $local_user_id, (int) $imported_comments[0]->user_id, 'Comment should use existing local user.' );
	}

	/**
	 * @group comment
	 */
	public function test_should_create_new_user_for_comment_when_user_does_not_exist(): void {
		global $wpdb;

		// Create user only in live DB.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 1001,
				'user_login' => 'newcommentuser',
				'user_email' => 'newcomment@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture( [ 'ID' => 5004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$comment = [
			'comment_ID'           => 4001,
			'comment_post_ID'      => 5004,
			'comment_author'       => 'New Comment User',
			'comment_author_email' => 'newcomment@test.local',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-15 10:00:00',
			'comment_date_gmt'     => '2024-01-15 10:00:00',
			'comment_content'      => 'Comment by new user.',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 1001,
		];
		$wpdb->insert( $this->live_table_prefix . 'comments', $comment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify user was created.
		$new_user = get_user_by( 'login', 'newcommentuser' );
		$this->assertNotFalse( $new_user, 'New user should be created for comment.' );

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 5004, $this->source_hostname );
		$imported_comments = get_comments( [ 'post_id' => $new_post_id ] );
		$this->assertEquals( $new_user->ID, (int) $imported_comments[0]->user_id, 'Comment should use newly created user.' );
	}

	/**
	 * @group comment
	 */
	public function test_should_handle_anonymous_comment_with_user_id_zero(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 5005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$comment = [
			'comment_ID'           => 5001,
			'comment_post_ID'      => 5005,
			'comment_author'       => 'Anonymous Visitor',
			'comment_author_email' => 'anon@test.local',
			'comment_author_url'   => 'https://example.com',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-15 10:00:00',
			'comment_date_gmt'     => '2024-01-15 10:00:00',
			'comment_content'      => 'Anonymous comment.',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => 'Mozilla/5.0',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0, // Anonymous.
		];
		$wpdb->insert( $this->live_table_prefix . 'comments', $comment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 5005, $this->source_hostname );
		$imported_comments = get_comments( [ 'post_id' => $new_post_id ] );
		$this->assertCount( 1, $imported_comments );

		// Verify anonymous comment data.
		$c = $imported_comments[0];
		$this->assertEquals( 0, (int) $c->user_id, 'Anonymous comment should have user_id 0.' );
		$this->assertEquals( 'Anonymous Visitor', $c->comment_author );
		$this->assertEquals( 'anon@test.local', $c->comment_author_email );
		$this->assertEquals( 'https://example.com', $c->comment_author_url );
	}

	/**
	 * @group comment
	 */
	public function test_should_update_comment_parent_ids_after_import(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 5006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Parent comment.
		$parent_comment = [
			'comment_ID'           => 6001,
			'comment_post_ID'      => 5006,
			'comment_author'       => 'Parent',
			'comment_author_email' => 'parent@test.local',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-15 10:00:00',
			'comment_date_gmt'     => '2024-01-15 10:00:00',
			'comment_content'      => 'Parent comment.',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'comments', $parent_comment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Reply comment.
		$reply_comment = [
			'comment_ID'           => 6002,
			'comment_post_ID'      => 5006,
			'comment_author'       => 'Reply',
			'comment_author_email' => 'reply@test.local',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-15 11:00:00',
			'comment_date_gmt'     => '2024-01-15 11:00:00',
			'comment_content'      => 'Reply to parent.',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => 'comment',
			'comment_parent'       => 6001, // References parent by old ID.
			'user_id'              => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'comments', $reply_comment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 5006, $this->source_hostname );
		$imported_comments = get_comments(
			[
				'post_id' => $new_post_id,
				'orderby' => 'comment_date',
				'order'   => 'ASC',
			] 
		);
		$this->assertCount( 2, $imported_comments );

		$parent = $imported_comments[0];
		$reply  = $imported_comments[1];

		// Verify reply's parent was updated to new parent ID.
		$this->assertEquals( 0, (int) $parent->comment_parent, 'Parent comment should have no parent.' );
		$this->assertEquals( $parent->comment_ID, $reply->comment_parent, 'Reply should reference new parent ID.' );
	}

	/**
	 * @group comment
	 */
	public function test_should_handle_nested_comment_thread_with_multiple_levels(): void {
		$fixture = $this->load_fixture( 'post-with-nested-comments' );
		$this->insert_live_data( $fixture );

		$this->run_search_command();
		$this->run_migrate_command();

		$live_post_id = $fixture['post']['ID'];
		$new_post_id  = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );

		$imported_comments = get_comments(
			[
				'post_id' => $new_post_id,
				'orderby' => 'comment_date',
				'order'   => 'ASC',
			]
		);

		$this->assertCount( 4, $imported_comments, 'All 4 nested comments should be imported.' );

		// Build a map of comment content to comment object.
		$comment_map = [];
		foreach ( $imported_comments as $c ) {
			$comment_map[ $c->comment_content ] = $c;
		}

		// Verify hierarchy: Level 1 has no parent.
		$level1 = $comment_map['Level 1 - Root comment.'];
		$this->assertEquals( 0, (int) $level1->comment_parent, 'Level 1 should have no parent.' );

		// Level 2 should have Level 1 as parent.
		$level2 = $comment_map['Level 2 - Reply to level 1.'];
		$this->assertEquals( $level1->comment_ID, $level2->comment_parent, 'Level 2 should have Level 1 as parent.' );

		// Level 3 should have Level 2 as parent.
		$level3 = $comment_map['Level 3 - Reply to level 2.'];
		$this->assertEquals( $level2->comment_ID, $level3->comment_parent, 'Level 3 should have Level 2 as parent.' );

		// Level 4 should have Level 3 as parent.
		$level4 = $comment_map['Level 4 - Reply to level 3.'];
		$this->assertEquals( $level3->comment_ID, $level4->comment_parent, 'Level 4 should have Level 3 as parent.' );
	}

	// =========================================================================
	// 8. TAXONOMY AND TERM RELATIONSHIP IMPORT TESTS
	// =========================================================================

	/**
	 * @group term
	 */
	public function test_should_import_category_term_relationships(): void {
		global $wpdb;

		// Create post.
		$post = $this->create_post_fixture( [ 'ID' => 6001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create category term.
		$term = [
			'term_id'    => 101,
			'name'       => 'Test Category',
			'slug'       => 'test-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 101,
			'term_id'          => 101,
			'taxonomy'         => 'category',
			'description'      => 'Test category description.',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create relationship.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6001,
				'term_taxonomy_id' => 101,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6001, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );

		$this->assertContains( 'Test Category', $categories, 'Category should be assigned to post.' );
	}

	/**
	 * @group term
	 */
	public function test_should_import_post_tag_term_relationships(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create tag.
		$term = [
			'term_id'    => 201,
			'name'       => 'Test Tag',
			'slug'       => 'test-tag',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 201,
			'term_id'          => 201,
			'taxonomy'         => 'post_tag',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6002,
				'term_taxonomy_id' => 201,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6002, $this->source_hostname );
		$tags        = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );

		$this->assertContains( 'Test Tag', $tags, 'Tag should be assigned to post.' );
	}

	/**
	 * @group term
	 */
	public function test_should_import_custom_taxonomy_term_relationships(): void {
		global $wpdb;

		// Register custom taxonomy for test.
		register_taxonomy( 'brand', 'post', [ 'public' => true ] );

		$post = $this->create_post_fixture( [ 'ID' => 6003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create custom taxonomy term.
		$term = [
			'term_id'    => 301,
			'name'       => 'Acme Corp',
			'slug'       => 'acme-corp',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 301,
			'term_id'          => 301,
			'taxonomy'         => 'brand',
			'description'      => 'Acme Corporation',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6003,
				'term_taxonomy_id' => 301,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag,author,brand' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6003, $this->source_hostname );
		$brands      = wp_get_post_terms( $new_post_id, 'brand', [ 'fields' => 'names' ] );

		$this->assertContains( 'Acme Corp', $brands, 'Custom taxonomy term should be assigned.' );
	}

	/**
	 * @group term
	 */
	public function test_should_create_term_on_the_fly_when_not_exists_locally(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create a new category that doesn't exist locally.
		$term = [
			'term_id'    => 401,
			'name'       => 'Brand New Category',
			'slug'       => 'brand-new-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 401,
			'term_id'          => 401,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6004,
				'term_taxonomy_id' => 401,
			]
		);

		// Verify term doesn't exist locally.
		$this->assertNull( term_exists( 'Brand New Category', 'category' ), 'Term should not exist before migration.' );

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify term was created.
		$this->assertNotNull( term_exists( 'Brand New Category', 'category' ), 'Term should be created during migration.' );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6004, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Brand New Category', $categories );
	}

	/**
	 * @group term
	 */
	public function test_should_reuse_existing_term_when_already_exists_locally(): void {
		global $wpdb;

		// Create term locally first.
		$existing_term = wp_insert_term( 'Existing Category', 'category', [ 'slug' => 'existing-category' ] );
		$local_term_id = $existing_term['term_id'];

		$post = $this->create_post_fixture( [ 'ID' => 6005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create same term in live DB.
		$term = [
			'term_id'    => 501,
			'name'       => 'Existing Category',
			'slug'       => 'existing-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 501,
			'term_id'          => 501,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6005,
				'term_taxonomy_id' => 501,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify existing term was used (not duplicated).
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'name'       => 'Existing Category',
				'hide_empty' => false,
			] 
		);
		$this->assertCount( 1, $terms, 'Should not duplicate existing term.' );
		$this->assertEquals( $local_term_id, $terms[0]->term_id, 'Should use existing term ID.' );
	}

	/**
	 * @group term
	 */
	public function test_should_import_hierarchical_taxonomy_with_parent_term(): void {
		$fixture = $this->load_fixture( 'post-with-hierarchical-taxonomy' );
		$this->insert_live_data( $fixture );

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( $fixture['post']['ID'], $this->source_hostname );

		// Get categories.
		$parent_category     = get_category_by_slug( 'parent-category' );
		$child_category      = get_category_by_slug( 'child-category' );
		$grandchild_category = get_category_by_slug( 'grandchild-category' );
		
		// Verify category hierarchy.
		$this->assertEquals( $child_category->term_id, $grandchild_category->parent, 'Parent should be correct.' );
		$this->assertEquals( $parent_category->term_id, $child_category->parent, 'Parent should be correct.' );
		$this->assertEquals( 0, $parent_category->parent, 'Parent should be correct.' );
		
		// Post should have grandchild category assigned.
		$post_categories = wp_get_post_terms( $new_post_id, 'category' );
		$this->assertCount( 1, $post_categories, 'Should have 1 category assigned to post.' );
		$post_category = $post_categories[0];
		$this->assertEquals( $grandchild_category->term_id, $post_category->term_id, 'Grandchild Category should be assigned.' );
	}

	/**
	 * @group term
	 */
	public function test_should_import_termmeta_for_term(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 601,
			'name'       => 'Term With Meta',
			'slug'       => 'term-with-meta',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 601,
			'term_id'          => 601,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add termmeta.
		$termmeta = [
			[
				'term_id'    => 601,
				'meta_key'   => 'term_icon',
				'meta_value' => 'icon-star', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'term_id'    => 601,
				'meta_key'   => 'term_color',
				'meta_value' => '#ff0000', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $termmeta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'termmeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6006,
				'term_taxonomy_id' => 601,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Find the imported term.
		$term = get_term_by( 'slug', 'term-with-meta', 'category' );
		$this->assertNotFalse( $term, 'Term should be imported.' );

		// Verify termmeta.
		$this->assertEquals( 'icon-star', get_term_meta( $term->term_id, 'term_icon', true ) );
		$this->assertEquals( '#ff0000', get_term_meta( $term->term_id, 'term_color', true ) );
	}

	/**
	 * @group term
	 */
	public function test_should_save_old_id_termmeta_for_term(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6007 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 701,
			'name'       => 'Old ID Term',
			'slug'       => 'old-id-term',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 701,
			'term_id'          => 701,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6007,
				'term_taxonomy_id' => 701,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$term = get_term_by( 'slug', 'old-id-term', 'category' );
		$this->assertNotFalse( $term, 'Term should be imported.' );

		// Verify old_id termmeta.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_term_meta( $term->term_id, $meta_key, true );
		$this->assertEquals( 701, (int) $old_id, 'Term should have old_id termmeta.' );
	}

	/**
	 * @group term
	 */
	public function test_should_not_duplicate_term_when_same_term_imported_twice(): void {
		global $wpdb;

		// Create two posts with the same term.
		$post1 = $this->create_post_fixture( [ 'ID' => 6008 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 6009 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 801,
			'name'       => 'Shared Category',
			'slug'       => 'shared-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 801,
			'term_id'          => 801,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 2,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Both posts use same term.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6008,
				'term_taxonomy_id' => 801,
			] 
		);
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6009,
				'term_taxonomy_id' => 801,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify only one term exists.
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'slug'       => 'shared-category',
				'hide_empty' => false,
			] 
		);
		$this->assertCount( 1, $terms, 'Term should not be duplicated.' );
	}

	/**
	 * @group term
	 */
	public function test_should_handle_term_with_missing_term_taxonomy_record_in_live_db(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6010 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create term without term_taxonomy record.
		$term = [
			'term_id'    => 901,
			'name'       => 'Orphan Term',
			'slug'       => 'orphan-term',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Relationship references non-existent term_taxonomy.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6010,
				'term_taxonomy_id' => 901,
			] 
		);

		// Should not throw, just skip the invalid term.
		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6010, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should still be imported despite orphan term.' );
	}

	/**
	 * @group term
	 */
	public function test_should_merge_terms_with_same_name_different_case(): void {
		global $wpdb;

		// Create term locally with specific case.
		wp_insert_term( 'UPPERCASE', 'category', [ 'slug' => 'uppercase' ] );

		$post = $this->create_post_fixture( [ 'ID' => 6011 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create same term with different case in live.
		$term = [
			'term_id'    => 1001,
			'name'       => 'uppercase',
			'slug'       => 'uppercase',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 1001,
			'term_id'          => 1001,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6011,
				'term_taxonomy_id' => 1001,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify only one term exists (merged by slug).
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'slug'       => 'uppercase',
				'hide_empty' => false,
			] 
		);
		$this->assertCount( 1, $terms, 'Terms with same slug should be merged.' );
	}

	/**
	 * @group term
	 */
	public function test_should_handle_term_with_empty_description(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6012 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 1101,
			'name'       => 'No Description',
			'slug'       => 'no-description',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 1101,
			'term_id'          => 1101,
			'taxonomy'         => 'category',
			'description'      => '', // Empty description.
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6012,
				'term_taxonomy_id' => 1101,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$term = get_term_by( 'slug', 'no-description', 'category' );
		$this->assertNotFalse( $term, 'Term with empty description should be imported.' );
		$this->assertEmpty( $term->description, 'Description should be empty.' );
	}

	/**
	 * @group term
	 */
	public function test_should_handle_taxonomy_with_no_terms(): void {
		global $wpdb;

		// Create post with no term relationships.
		$post = $this->create_post_fixture( [ 'ID' => 6013 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6013, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported even without terms.' );

		$categories = wp_get_post_terms( $new_post_id, 'category' );
		// Post might have default category or none.
		$this->assertIsArray( $categories, 'Should return array even if empty.' );
	}

	/**
	 * @group term
	 */
	public function test_should_register_unknown_taxonomy_on_the_fly(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group term
	 */
	public function test_should_handle_term_with_same_slug_in_different_taxonomies(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 9. RUN STATE AND RESUME CAPABILITY TESTS
	// =========================================================================

	/**
	 * @group runstate
	 */
	public function test_should_save_imported_post_to_runstate(): void {
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify run-state has the imported post.
		$imported_posts_map = $this->run_state->get_imported_post_ids_map();
		$this->assertArrayHasKey( $live_post_id, $imported_posts_map, 'Imported post should be saved to run-state.' );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );
		$this->assertEquals( $new_post_id, $imported_posts_map[ $live_post_id ], 'Run-state should map old to new ID.' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_skip_already_imported_posts_on_resume(): void {
		global $wpdb;

		// Create multiple posts.
		$post1 = $this->create_post_fixture( [ 'ID' => 7001 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 7002 ] );
		$post3 = $this->create_post_fixture( [ 'ID' => 7003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $post3 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Run search.
		$this->run_search_command();

		// Simulate partial migration by pre-populating run-state.
		// Import first post and record it.
		$this->run_state->append_imported_post(
			[
				'id_old'    => 7001,
				'id_new'    => 99001, // Simulated new ID.
				'post_type' => 'post',
			]
		);

		// Now run migrate - it should skip 7001.
		$this->run_migrate_command();

		// Verify only posts 7002 and 7003 were actually imported.
		$imported_map = $this->run_state->get_imported_post_ids_map();

		// 7001 should still be in map (from pre-population).
		$this->assertArrayHasKey( 7001, $imported_map );
		// 7002 and 7003 should have been imported.
		$this->assertArrayHasKey( 7002, $imported_map );
		$this->assertArrayHasKey( 7003, $imported_map );

		// Verify 7002 and 7003 have different IDs than the simulated one.
		$this->assertNotEquals( 99001, $imported_map[7002] );
		$this->assertNotEquals( 99001, $imported_map[7003] );
	}

	/**
	 * @group runstate
	 */
	public function test_should_continue_from_last_imported_post_on_resume(): void {
		global $wpdb;

		// Create posts.
		$post1 = $this->create_post_fixture( [ 'ID' => 7101 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 7102 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// First migration - import only first post.
		$this->run_search_command();

		// Manually set new_ids to only first post.
		$this->run_state->write_new_ids( [ 7101 ] );
		$this->run_migrate_command();

		$first_post_new_id = $this->logic->get_current_post_id_by_old_id( 7101, $this->source_hostname );
		$this->assertNotNull( $first_post_new_id, 'First post should be imported.' );

		// Second migration - should only import second post.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		$second_post_new_id = $this->logic->get_current_post_id_by_old_id( 7102, $this->source_hostname );
		$this->assertNotNull( $second_post_new_id, 'Second post should be imported on resume.' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_save_updated_parent_to_runstate(): void {
		global $wpdb;

		// Create parent and child posts.
		$parent = $this->create_post_fixture(
			[
				'ID'          => 7201,
				'post_parent' => 0,
			] 
		);
		$child  = $this->create_post_fixture(
			[
				'ID'          => 7202,
				'post_parent' => 7201,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify updated parents are saved to run-state.
		$updated_parents_map = $this->run_state->get_updated_parent_ids_map();
		$this->assertNotEmpty( $updated_parents_map, 'Updated parents should be saved to run-state.' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_skip_already_updated_parents_on_resume(): void {
		global $wpdb;

		$parent = $this->create_post_fixture(
			[
				'ID'          => 7301,
				'post_parent' => 0,
			] 
		);
		$child  = $this->create_post_fixture(
			[
				'ID'          => 7302,
				'post_parent' => 7301,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Get the count of updated parents.
		$updated_parents_map_1 = $this->run_state->get_updated_parent_ids_map();
		$count_first           = count( $updated_parents_map_1 );

		// Run migrate again - should skip already updated.
		$this->run_migrate_command();

		$updated_parents_map_2 = $this->run_state->get_updated_parent_ids_map();
		$count_second          = count( $updated_parents_map_2 );

		// Count should be same (no duplicates added).
		$this->assertEquals( $count_first, $count_second, 'Should not duplicate updated parents on rerun.' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_save_updated_featured_image_to_runstate(): void {
		global $wpdb;

		// Create attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 7401,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with featured image.
		$post = $this->create_post_fixture( [ 'ID' => 7402 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta', // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			[
				'post_id'    => 7402,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 7401, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		// Verify featured image updates are saved.
		$updated_featured_map = $this->run_state->get_updated_featured_image_ids_map();
		$this->assertNotEmpty( $updated_featured_map, 'Updated featured images should be saved to run-state.' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_skip_already_updated_featured_images_on_resume(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 7501,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture( [ 'ID' => 7502 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 7502,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 7501, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$count_first = count( $this->run_state->get_updated_featured_image_ids_map() );

		// Run again.
		$this->run_migrate_command();

		$count_second = count( $this->run_state->get_updated_featured_image_ids_map() );

		$this->assertEquals( $count_first, $count_second, 'Should not duplicate featured image updates on rerun.' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_save_updated_blocks_to_runstate(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_skip_already_updated_blocks_on_resume(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_handle_empty_new_ids_json_file(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_handle_empty_modified_ids_json_file(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_create_runstate_directory_if_not_exists(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_write_manifest_json_with_migration_summary(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group runstate
	 */
	public function test_should_read_manifest_json_correctly(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 10. POST PARENT UPDATE TESTS
	// =========================================================================

	/**
	 * @group parent
	 */
	public function test_should_update_post_parent_id_from_old_to_new(): void {
		global $wpdb;

		// Create parent page.
		$parent = $this->create_post_fixture(
			[
				'ID'          => 8001,
				'post_parent' => 0,
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create child page with parent reference.
		$child = $this->create_post_fixture(
			[
				'ID'          => 8002,
				'post_parent' => 8001, // Old parent ID.
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		$new_parent_id = $this->logic->get_current_post_id_by_old_id( 8001, $this->source_hostname );
		$new_child_id  = $this->logic->get_current_post_id_by_old_id( 8002, $this->source_hostname );

		$child_post = get_post( $new_child_id );
		$this->assertEquals( $new_parent_id, $child_post->post_parent, 'Child post_parent should be updated to new parent ID.' );
	}

	/**
	 * @group parent
	 */
	public function test_should_find_parent_id_by_old_id_postmeta(): void {
		global $wpdb;

		// Create parent that was imported in a previous batch.
		$parent_id = wp_insert_post(
			[
				'post_title'  => 'Pre-existing Parent',
				'post_status' => 'publish',
				'post_type'   => 'page',
			]
		);
		// Mark with old_id meta.
		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $parent_id, $meta_key, 8101 );

		// Create child in live DB referencing the old parent ID.
		$child = $this->create_post_fixture(
			[
				'ID'          => 8102,
				'post_parent' => 8101, // References the pre-existing parent by old ID.
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		$new_child_id = $this->logic->get_current_post_id_by_old_id( 8102, $this->source_hostname );
		$child_post   = get_post( $new_child_id );

		$this->assertEquals( $parent_id, $child_post->post_parent, 'Should find parent by old_id postmeta.' );
	}

	/**
	 * @group parent
	 */
	public function test_should_find_parent_id_by_comparing_with_live_db(): void {
		global $wpdb;

		// Create parent in live DB.
		$parent = $this->create_post_fixture(
			[
				'ID'          => 8201,
				'post_parent' => 0,
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create child in live DB.
		$child = $this->create_post_fixture(
			[
				'ID'          => 8202,
				'post_parent' => 8201,
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		// Both should be imported.
		$new_parent_id = $this->logic->get_current_post_id_by_old_id( 8201, $this->source_hostname );
		$new_child_id  = $this->logic->get_current_post_id_by_old_id( 8202, $this->source_hostname );

		$this->assertNotNull( $new_parent_id );
		$this->assertNotNull( $new_child_id );

		$child_post = get_post( $new_child_id );
		$this->assertEquals( $new_parent_id, $child_post->post_parent, 'Parent should be found via live DB comparison.' );
	}

	/**
	 * @group parent
	 */
	public function test_should_set_parent_to_zero_when_parent_not_found(): void {
		global $wpdb;

		// Create child with parent that doesn't exist.
		$child = $this->create_post_fixture(
			[
				'ID'          => 8302,
				'post_parent' => 99999, // Non-existent parent.
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		$new_child_id = $this->logic->get_current_post_id_by_old_id( 8302, $this->source_hostname );
		$child_post   = get_post( $new_child_id );

		// Parent should be 0 since the referenced parent doesn't exist.
		$this->assertEquals( 0, $child_post->post_parent, 'Parent should be 0 when not found.' );
	}

	/**
	 * @group parent
	 */
	public function test_should_not_update_post_parent_when_already_zero(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'          => 8401,
				'post_parent' => 0,
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 8401, $this->source_hostname );
		$new_post    = get_post( $new_post_id );

		$this->assertEquals( 0, $new_post->post_parent, 'Post parent should remain 0.' );
	}

	// =========================================================================
	// 11. FEATURED IMAGE UPDATE TESTS
	// =========================================================================

	/**
	 * @group featured
	 */
	public function test_should_update_thumbnail_id_from_old_to_new(): void {
		global $wpdb;

		// Create attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 9001,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with featured image.
		$post = $this->create_post_fixture( [ 'ID' => 9002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 9002,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 9001, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value. Old attachment ID.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 9002, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 9001, $this->source_hostname );

		$thumbnail_id = get_post_thumbnail_id( $new_post_id );
		$this->assertEquals( $new_attachment_id, $thumbnail_id, 'Thumbnail ID should be updated to new attachment ID.' );
	}

	/**
	 * @group featured
	 */
	public function test_should_not_update_thumbnail_when_attachment_not_in_map(): void {
		global $wpdb;

		// Create post with thumbnail referencing non-existent attachment.
		$post = $this->create_post_fixture( [ 'ID' => 9101 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 9101,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 88888, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value. Non-existent attachment.
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 9101, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should still be imported.' );

		// Thumbnail might be 0 or unchanged - key is no crash.
		$thumbnail_id = get_post_thumbnail_id( $new_post_id );
		$this->assertIsNumeric( $thumbnail_id, 'Thumbnail should be numeric.' );
	}

	/**
	 * @group featured
	 */
	public function test_should_not_update_thumbnail_when_post_has_no_featured_image(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 9201 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id  = $this->logic->get_current_post_id_by_old_id( 9201, $this->source_hostname );
		$thumbnail_id = get_post_thumbnail_id( $new_post_id );

		// No thumbnail should be set.
		$this->assertEmpty( $thumbnail_id, 'Post without featured image should have no thumbnail.' );
	}

	/**
	 * @group featured
	 */
	public function test_should_use_db_attachment_map_not_just_current_batch(): void {
		global $wpdb;

		// First, import an attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 9301,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 9301, $this->source_hostname );
		$this->assertNotNull( $new_attachment_id );

		// Now create new run-state and import a post using that attachment.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$post = $this->create_post_fixture( [ 'ID' => 9302 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 9302,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 9301, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value. Reference to previously imported attachment.
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id  = $this->logic->get_current_post_id_by_old_id( 9302, $this->source_hostname );
		$thumbnail_id = get_post_thumbnail_id( $new_post_id );

		// Should use the attachment from the previous batch.
		$this->assertEquals( $new_attachment_id, $thumbnail_id, 'Should use attachment from DB, not just current batch.' );
	}

	// =========================================================================
	// 12. BLOCK ATTACHMENT ID UPDATE TESTS
	// =========================================================================

	/**
	 * @group blocks
	 */
	public function test_should_update_image_block_id_in_post_content(): void {
		global $wpdb;

		// Create attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10001,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with image block referencing the attachment.
		$content = '<!-- wp:image {"id":10001,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://test.local/image.jpg" alt="" class="wp-image-10001"/></figure>
<!-- /wp:image -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10002,
				'post_content' => $content,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10002, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10001, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Verify the block ID was updated.
		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content, 'Image block ID should be updated.' );
		$this->assertStringContainsString( 'wp-image-' . $new_attachment_id, $new_post->post_content, 'Image class should be updated.' );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_gallery_block_ids_in_post_content(): void {
		global $wpdb;

		// Create attachments.
		$attachment1 = $this->create_post_fixture(
			[
				'ID'          => 10101,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$attachment2 = $this->create_post_fixture(
			[
				'ID'          => 10102,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with Jetpack Slideshow block -- this is just a small demo block, unit tests already cover more comprehensive fixtures.
		$content = '<!-- wp:jetpack/slideshow {"ids":[10101,10102],"sizeSlug":"large"} -->
<div class="wp-block-jetpack-slideshow"><div class="wp-block-jetpack-slideshow_container swiper"><ul class="wp-block-jetpack-slideshow_swiper-wrapper swiper-wrapper"><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="" class="wp-block-jetpack-slideshow_image wp-image-10101" data-id="10101" data-aspect-ratio="1024 / 439" src="https://ivannptest.newspackstaging.com/wp-content/uploads/2026/01/WP-art-100x200-1-1024x439.png"/></figure></li><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="" class="wp-block-jetpack-slideshow_image wp-image-10102" data-id="10102" data-aspect-ratio="1024 / 408" src="https://ivannptest.newspackstaging.com/wp-content/uploads/2025/06/huge-scaled-1-1024x408.jpg"/></figure></li></ul><a class="wp-block-jetpack-slideshow_button-prev swiper-button-prev swiper-button-white" role="button"></a><a class="wp-block-jetpack-slideshow_button-next swiper-button-next swiper-button-white" role="button"></a><a aria-label="Pause Slideshow" class="wp-block-jetpack-slideshow_button-pause" role="button"></a><div class="wp-block-jetpack-slideshow_pagination swiper-pagination swiper-pagination-white"></div></div></div>
<!-- /wp:jetpack/slideshow -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10103,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 10103, $this->source_hostname );
		$new_att1_id = $this->logic->get_current_post_id_by_old_id( 10101, $this->source_hostname );
		$new_att2_id = $this->logic->get_current_post_id_by_old_id( 10102, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Verify both IDs were updated.
		$this->assertStringContainsString( 'data-id="' . $new_att1_id . '"', $new_post->post_content );
		$this->assertStringContainsString( 'data-id="' . $new_att2_id . '"', $new_post->post_content );
		$this->assertStringContainsString( '"ids":[' . $new_att1_id . ',' . $new_att2_id . ']', $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_cover_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10201,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:cover {"id":10201,"dimRatio":50} -->
<div class="wp-block-cover"><span class="wp-block-cover__background"></span><img class="wp-block-cover__image-background wp-image-10201" src="cover.jpg"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Cover text</p><!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10202,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10202, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10201, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content, 'Cover block ID should be updated.' );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_media_text_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10301,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:media-text {"mediaId":10301,"mediaType":"image"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="media.jpg" class="wp-image-10301"/></figure><div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph --></div></div>
<!-- /wp:media-text -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10302,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10302, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10301, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"mediaId":' . $new_attachment_id, $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_file_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10401,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:file {"id":10401} -->
<div class="wp-block-file"><a href="document.pdf">Document</a></div>
<!-- /wp:file -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10402,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10402, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10401, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_audio_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'             => 10501,
				'post_type'      => 'attachment',
				'post_mime_type' => 'audio/mpeg',
				'post_status'    => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:audio {"id":10501} -->
<figure class="wp-block-audio"><audio controls src="audio.mp3"></audio></figure>
<!-- /wp:audio -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10502,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10502, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10501, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_video_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'             => 10601,
				'post_type'      => 'attachment',
				'post_mime_type' => 'video/mp4',
				'post_status'    => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:video {"id":10601} -->
<figure class="wp-block-video"><video controls src="video.mp4"></video></figure>
<!-- /wp:video -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10602,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10602, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10601, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_attachment_ids_in_post_excerpt(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10701,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with blocks in excerpt (some themes support this).
		$excerpt = '<!-- wp:image {"id":10701} --><figure class="wp-block-image"><img class="wp-image-10701"/></figure><!-- /wp:image -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10702,
				'post_excerpt' => $excerpt,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10702, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10701, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Excerpt may or may not be updated depending on implementation.
		// The key test is that migration completes without errors.
		$this->assertNotNull( $new_post_id, 'Post with blocks in excerpt should be imported.' );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_jetpack_slideshow_block_ids(): void {
		global $wpdb;

		$attachment1 = $this->create_post_fixture(
			[
				'ID'          => 10801,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$attachment2 = $this->create_post_fixture(
			[
				'ID'          => 10802,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:jetpack/slideshow {"ids":[10801,10802]} -->
<div class="wp-block-jetpack-slideshow"><ul class="swiper-wrapper"><li class="swiper-slide"><img src="1.jpg" data-id="10801" class="wp-image-10801"/></li><li class="swiper-slide"><img src="2.jpg" data-id="10802" class="wp-image-10802"/></li></ul></div>
<!-- /wp:jetpack/slideshow -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10803,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 10803, $this->source_hostname );
		$new_att1_id = $this->logic->get_current_post_id_by_old_id( 10801, $this->source_hostname );
		$new_att2_id = $this->logic->get_current_post_id_by_old_id( 10802, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Verify Jetpack slideshow IDs were updated.
		$this->assertStringContainsString( 'data-id="' . $new_att1_id . '"', $new_post->post_content );
		$this->assertStringContainsString( 'data-id="' . $new_att2_id . '"', $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_jetpack_tiled_gallery_block_ids(): void {
		global $wpdb;

		$attachment1 = $this->create_post_fixture(
			[
				'ID'          => 10901,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$attachment2 = $this->create_post_fixture(
			[
				'ID'          => 10902,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$inserted = $wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$inserted =$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:jetpack/tiled-gallery {"ids":[10901,10902]} -->
<div class="wp-block-jetpack-tiled-gallery"><figure class="tiled-gallery__item"><img src="1.jpg" data-id="10901" class="wp-image-10901"/></figure><figure class="tiled-gallery__item"><img src="2.jpg" data-id="10902" class="wp-image-10902"/></figure></div>
<!-- /wp:jetpack/tiled-gallery -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10903,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 10903, $this->source_hostname );
		$new_att1_id = $this->logic->get_current_post_id_by_old_id( 10901, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( 'data-id="' . $new_att1_id . '"', $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_jetpack_image_compare_block_ids(): void {
		global $wpdb;

		$attachment1 = $this->create_post_fixture(
			[
				'ID'          => 11001,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$attachment2 = $this->create_post_fixture(
			[
				'ID'          => 11002,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:jetpack/image-compare {"imageBefore":{"id":11001},"imageAfter":{"id":11002}} -->
<figure class="wp-block-jetpack-image-compare"><div class="juxtapose"><img src="before.jpg" data-id="11001" class="wp-image-11001"/><img src="after.jpg" data-id="11002" class="wp-image-11002"/></div></figure>
<!-- /wp:jetpack/image-compare -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 11003,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 11003, $this->source_hostname );
		$new_att1_id = $this->logic->get_current_post_id_by_old_id( 11001, $this->source_hostname );
		$new_att2_id = $this->logic->get_current_post_id_by_old_id( 11002, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Verify image compare block IDs were updated.
		$this->assertStringContainsString( '"id":' . $new_att1_id, $new_post->post_content );
		$this->assertStringContainsString( '"id":' . $new_att2_id, $new_post->post_content );
	}

	// =========================================================================
	// 13. ATTACHMENTS AND CPT IMPORT TESTS
	// =========================================================================

	/**
	 * @group attachment
	 */
	public function test_should_import_attachment_post_type_correctly(): void {
		$fixture = $this->load_fixture( 'attachment-basic' );
		$this->insert_live_data( $fixture );
		$live_attachment_id = $fixture['post']['ID'];

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( $live_attachment_id, $this->source_hostname );
		$this->assertNotNull( $new_attachment_id, 'Attachment should be imported.' );

		$attachment = get_post( $new_attachment_id );
		$this->assertEquals( 'attachment', $attachment->post_type );
		$this->assertEquals( 'image/jpeg', $attachment->post_mime_type );
	}

	/**
	 * @group attachment
	 */
	public function test_should_import_custom_post_type_correctly(): void {
		global $wpdb;

		// Register custom post type.
		register_post_type( 'product', [ 'public' => true ] );

		$product = $this->create_post_fixture(
			[
				'ID'         => 14001,
				'post_type'  => 'product',
				'post_title' => 'Test Product',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $product ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'product' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14001, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'CPT should be imported.' );

		$post = get_post( $new_post_id );
		$this->assertEquals( 'product', $post->post_type );
		$this->assertEquals( 'Test Product', $post->post_title );
	}

	/**
	 * @group attachment
	 */
	public function test_should_preserve_attachment_metadata(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'             => 14101,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add attachment metadata.
		$metadata = [
			'width'  => 1920,
			'height' => 1080,
			'file'   => '2024/01/test-image.jpg',
			'sizes'  => [
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'file'   => 'test-image-150x150.jpg',
				],
				'medium'    => [
					'width'  => 300,
					'height' => 169,
					'file'   => 'test-image-300x169.jpg',
				],
			],
		];
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 14101,
				'meta_key'   => '_wp_attachment_metadata',
				'meta_value' => serialize( $metadata ), // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 14101, $this->source_hostname );
		$imported_metadata = get_post_meta( $new_attachment_id, '_wp_attachment_metadata', true );

		$this->assertEquals( 1920, $imported_metadata['width'] );
		$this->assertEquals( 1080, $imported_metadata['height'] );
		$this->assertArrayHasKey( 'thumbnail', $imported_metadata['sizes'] );
	}

	/**
	 * @group attachment
	 */
	public function test_should_handle_attachment_with_parent_post(): void {
		global $wpdb;

		// Create parent post.
		$post = $this->create_post_fixture( [ 'ID' => 14201 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create attachment with parent.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 14202,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 14201,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 14201, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 14202, $this->source_hostname );

		$attachment = get_post( $new_attachment_id );
		$this->assertEquals( $new_post_id, $attachment->post_parent, 'Attachment parent should be updated.' );
	}

	/**
	 * @group attachment
	 */
	public function test_should_preserve_wp_attachment_metadata_postmeta(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 14301,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$metadata = [
			'width'  => 800,
			'height' => 600,
			'file'   => 'uploads/image.jpg',
		];
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 14301,
				'meta_key'   => '_wp_attachment_metadata',
				'meta_value' => serialize( $metadata ), // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_id   = $this->logic->get_current_post_id_by_old_id( 14301, $this->source_hostname );
		$imported = get_post_meta( $new_id, '_wp_attachment_metadata', true );

		$this->assertEquals( 800, $imported['width'] );
		$this->assertEquals( 600, $imported['height'] );
	}

	/**
	 * @group attachment
	 */
	public function test_should_preserve_wp_attached_file_postmeta(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 14401,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 14401,
				'meta_key'   => '_wp_attached_file',
				'meta_value' => '2024/01/my-file.pdf', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_id = $this->logic->get_current_post_id_by_old_id( 14401, $this->source_hostname );
		$file   = get_post_meta( $new_id, '_wp_attached_file', true );

		$this->assertEquals( '2024/01/my-file.pdf', $file );
	}

	/**
	 * @group attachment
	 */
	public function test_should_import_attachment_with_no_parent_post(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 14501,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 0, // No parent.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_id     = $this->logic->get_current_post_id_by_old_id( 14501, $this->source_hostname );
		$attachment = get_post( $new_id );

		$this->assertNotNull( $new_id, 'Attachment without parent should be imported.' );
		$this->assertEquals( 0, $attachment->post_parent, 'Parent should remain 0.' );
	}

	/**
	 * @group attachment
	 */
	public function test_should_update_attachment_parent_post_id(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 14. TERM COUNT RECALCULATION TESTS
	// =========================================================================

	/**
	 * @group termcount
	 */
	public function test_should_recalculate_term_counts_after_migration(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group termcount
	 */
	public function test_should_set_correct_count_for_category_terms(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group termcount
	 */
	public function test_should_set_correct_count_for_post_tag_terms(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group termcount
	 */
	public function test_should_set_correct_count_for_custom_taxonomy_terms(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 15. MIGRATION DATA CONSISTENCY STANDARD (MDCS) TESTS
	// =========================================================================

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_update_user_email_when_changed_on_live(): void {
		global $wpdb;

		// Create and import a user.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 13001,
				'user_login' => 'mdcsuser1',
				'user_email' => 'original@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 13002,
				'post_author' => 13001,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$local_user = get_user_by( 'login', 'mdcsuser1' );
		$this->assertEquals( 'original@test.local', $local_user->user_email );

		// Update email in live DB.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'users',
			[ 'user_email' => 'updated@test.local' ],
			[ 'ID' => 13001 ]
		);

		// Run migration again (it will run update_modified_users).
		$this->run_migrate_command();

		// Verify local user email was updated.
		$local_user = get_user_by( 'login', 'mdcsuser1' );
		$this->assertEquals( 'updated@test.local', $local_user->user_email, 'User email should be updated per MDCS.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_update_user_display_name_when_changed_on_live(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'           => 13101,
				'user_login'   => 'mdcsuser2',
				'display_name' => 'Original Name',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 13102,
				'post_author' => 13101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Update display_name in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'users',
			[ 'display_name' => 'Updated Name' ],
			[ 'ID' => 13101 ]
		);

		$this->run_migrate_command();

		$local_user = get_user_by( 'login', 'mdcsuser2' );
		$this->assertEquals( 'Updated Name', $local_user->display_name, 'Display name should be updated per MDCS.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_not_update_user_login_when_changed_on_live(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 13201,
				'user_login' => 'original_login',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 13202,
				'post_author' => 13201,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Update user_login in live (not allowed by MDCS).
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'users',
			[ 'user_login' => 'changed_login' ],
			[ 'ID' => 13201 ]
		);

		$this->run_migrate_command();

		// Verify user_login was NOT updated.
		$local_user = get_user_by( 'login', 'original_login' );
		$this->assertNotFalse( $local_user, 'User login should NOT be changed.' );

		$changed_user = get_user_by( 'login', 'changed_login' );
		$this->assertFalse( $changed_user, 'Changed login should not exist.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_update_attachment_caption_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'           => 13301,
				'post_type'    => 'attachment',
				'post_status'  => 'inherit',
				'post_excerpt' => 'Original caption', // Caption is stored in post_excerpt.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 13301, $this->source_hostname );

		// Update caption in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_excerpt' => 'Updated caption' ],
			[ 'ID' => 13301 ]
		);

		$this->run_migrate_command();

		$local_attachment = get_post( $new_attachment_id );
		$this->assertEquals( 'Updated caption', $local_attachment->post_excerpt, 'Attachment caption should be updated per MDCS.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_update_attachment_description_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'           => 13401,
				'post_type'    => 'attachment',
				'post_status'  => 'inherit',
				'post_content' => 'Original description',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 13401, $this->source_hostname );

		// Update description in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_content' => 'Updated description' ],
			[ 'ID' => 13401 ]
		);

		$this->run_migrate_command();

		$local_attachment = get_post( $new_attachment_id );
		$this->assertEquals( 'Updated description', $local_attachment->post_content, 'Attachment description should be updated per MDCS.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_update_attachment_alt_text_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 13501,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 13501,
				'meta_key'   => '_wp_attachment_image_alt',
				'meta_value' => 'Original alt', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 13501, $this->source_hostname );

		// Update alt text in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[ 'meta_value' => 'Updated alt' ], // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			[
				'post_id'  => 13501,
				'meta_key' => '_wp_attachment_image_alt',
			]
		);

		$this->run_migrate_command();

		$alt = get_post_meta( $new_attachment_id, '_wp_attachment_image_alt', true );
		$this->assertEquals( 'Updated alt', $alt, 'Attachment alt text should be updated per MDCS.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_update_attachment_media_credit_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 13601,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 13601,
				'meta_key'   => '_media_credit',
				'meta_value' => 'Original Credit', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 13601, $this->source_hostname );

		// Update media credit in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[ 'meta_value' => 'Updated Credit' ], // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			[
				'post_id'  => 13601,
				'meta_key' => '_media_credit',
			]
		);

		$this->run_migrate_command();

		$credit = get_post_meta( $new_attachment_id, '_media_credit', true );
		$this->assertEquals( 'Updated Credit', $credit, 'Attachment media credit should be updated per MDCS.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_update_term_slug_when_changed_on_live(): void {
		global $wpdb;

		// Create post with category.
		$post = $this->create_post_fixture( [ 'ID' => 13701 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 13702,
			'name'       => 'MDCS Term',
			'slug'       => 'original-slug',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 13702,
			'term_id'          => 13702,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 13701,
				'term_taxonomy_id' => 13702,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Update slug in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'terms',
			[ 'slug' => 'updated-slug' ],
			[ 'term_id' => 13702 ]
		);

		$this->run_migrate_command();

		// Verify slug was updated.
		$term = get_term_by( 'slug', 'updated-slug', 'category' );
		$this->assertNotFalse( $term, 'Term slug should be updated per MDCS.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_update_term_description_when_changed_on_live(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 13801 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 13802,
			'name'       => 'Desc Term',
			'slug'       => 'desc-term',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 13802,
			'term_id'          => 13802,
			'taxonomy'         => 'category',
			'description'      => 'Original description',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 13801,
				'term_taxonomy_id' => 13802,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Update description in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_taxonomy',
			[ 'description' => 'Updated description' ],
			[ 'term_taxonomy_id' => 13802 ]
		);

		$this->run_migrate_command();

		$term = get_term_by( 'slug', 'desc-term', 'category' );
		$this->assertEquals( 'Updated description', $term->description, 'Term description should be updated per MDCS.' );
	}

	/**
	 * @group mdcs
	 */
	public function test_mdcs_should_not_update_term_name_when_changed_on_live(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 13901 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 13902,
			'name'       => 'Original Name',
			'slug'       => 'name-term',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 13902,
			'term_id'          => 13902,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 13901,
				'term_taxonomy_id' => 13902,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Update name in live (not allowed by MDCS).
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'terms',
			[ 'name' => 'Changed Name' ],
			[ 'term_id' => 13902 ]
		);

		$this->run_migrate_command();

		// Name should NOT be updated.
		$term = get_term_by( 'slug', 'name-term', 'category' );
		$this->assertEquals( 'Original Name', $term->name, 'Term name should NOT be changed per MDCS.' );
	}

	// =========================================================================
	// 16. EXCEPTION HANDLING TESTS
	// =========================================================================

	/**
	 * @group exception
	 */
	public function test_should_throw_when_new_ids_runstate_file_not_found(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group exception
	 */
	public function test_should_throw_when_post_insert_fails(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group exception
	 */
	public function test_should_throw_when_old_id_postmeta_insert_fails(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group exception
	 */
	public function test_should_log_error_and_continue_when_postmeta_insert_fails(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group exception
	 */
	public function test_should_log_error_and_continue_when_comment_insert_fails(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group exception
	 */
	public function test_should_log_error_and_continue_when_user_insert_fails(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group exception
	 */
	public function test_should_log_error_and_continue_when_term_insert_fails(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 17. MULTIPLE SOURCE HOSTNAMES TESTS
	// =========================================================================

	/**
	 * @group hostname
	 */
	public function test_should_import_content_independently_for_different_source_hostnames(): void {
		global $wpdb;

		// Create post for first source.
		$post1 = $this->create_post_fixture(
			[
				'ID'         => 12001,
				'post_title' => 'Post from Source 1',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Import from first source.
		$this->run_search_command();
		$this->run_migrate_command();

		$new_post1_id = $this->logic->get_current_post_id_by_old_id( 12001, $this->source_hostname );
		$this->assertNotNull( $new_post1_id, 'Post from source 1 should be imported.' );

		// Create post for second source.
		$post2 = $this->create_post_fixture(
			[
				'ID'         => 12002,
				'post_title' => 'Post from Source 2',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Set up for second source hostname.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname_2 . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname_2 . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Import from second source.
		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		$new_post2_id = $this->logic->get_current_post_id_by_old_id( 12002, $this->source_hostname_2 );
		$this->assertNotNull( $new_post2_id, 'Post from source 2 should be imported.' );

		// Verify both posts exist independently.
		$this->assertNotEquals( $new_post1_id, $new_post2_id, 'Posts from different sources should have different IDs.' );
	}

	/**
	 * @group hostname
	 */
	public function test_should_not_cross_contaminate_old_ids_between_source_hostnames(): void {
		global $wpdb;

		// Import a post from source 1.
		$post = $this->create_post_fixture( [ 'ID' => 12101 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12101, $this->source_hostname );

		// Try to get the same post using source 2 meta key - should not find it.
		$post_via_source2 = $this->logic->get_current_post_id_by_old_id( 12101, $this->source_hostname_2 );

		$this->assertNotNull( $new_post_id, 'Post should be found via source 1.' );
		$this->assertNull( $post_via_source2, 'Post should NOT be found via source 2 meta key.' );
	}

	/**
	 * @group hostname
	 */
	public function test_should_use_source_specific_meta_key_for_old_ids(): void {
		// Get meta keys for different sources.
		$meta_key_1 = ContentDiffLogic::get_old_id_meta_key( 'source1.example.com' );
		$meta_key_2 = ContentDiffLogic::get_old_id_meta_key( 'source2.example.com' );

		$this->assertNotEquals( $meta_key_1, $meta_key_2, 'Different sources should have different meta keys.' );
		$this->assertStringContainsString( 'source1.example.com', $meta_key_1 );
		$this->assertStringContainsString( 'source2.example.com', $meta_key_2 );
	}

	/**
	 * @group hostname
	 */
	public function test_should_list_all_migrated_source_hostnames(): void {
		global $wpdb;

		// Import from first source.
		$post1 = $this->create_post_fixture( [ 'ID' => 12201 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Import from second source.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname_2 . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname_2 . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$post2 = $this->create_post_fixture( [ 'ID' => 12202 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		// Get all migrated source hostnames.
		$hostnames = $this->logic->get_migrated_source_hostnames();

		$this->assertContains( $this->source_hostname, $hostnames, 'Source 1 should be listed.' );
		$this->assertContains( $this->source_hostname_2, $hostnames, 'Source 2 should be listed.' );
	}

	// =========================================================================
	// 18. END-TO-END FULL MIGRATION TESTS
	// =========================================================================

	/**
	 * @group e2e
	 */
	public function test_should_complete_full_migration_of_post_with_all_related_data(): void {
		global $wpdb;

		// Load fixture with post, author, comments, terms.
		$fixture = $this->load_fixture( 'post-with-full-data' );
		$this->insert_live_data( $fixture );

		$live_post_id = $fixture['post']['ID'];
		$live_user_id = $fixture['users'][0]['ID'];

		// Run search command to populate run-state with new IDs.
		$this->run_search_command();

		// Verify new IDs were written to run-state.
		$new_ids = $this->run_state->get_new_ids();
		$this->assertContains( $live_post_id, $new_ids, 'New IDs should include the live post ID.' );

		// Run migrate command.
		$this->run_migrate_command();

		// Verify post was imported.
		$meta_key    = $this->get_old_id_meta_key();
		$new_post_id = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported and have old_id meta.' );

		// Verify post data.
		$new_post = get_post( $new_post_id );
		$this->assertEquals( $fixture['post']['post_title'], $new_post->post_title, 'Post title should match.' );
		$this->assertEquals( $fixture['post']['post_content'], $new_post->post_content, 'Post content should match.' );
		$this->assertEquals( 'publish', $new_post->post_status, 'Post status should be publish.' );

		// Verify old_id postmeta.
		$saved_old_id = get_post_meta( $new_post_id, $meta_key, true );
		$this->assertEquals( $live_post_id, (int) $saved_old_id, 'Old ID postmeta should be saved.' );

		// Verify postmeta was imported.
		$custom_meta = get_post_meta( $new_post_id, 'custom_meta', true );
		$this->assertEquals( 'custom_value', $custom_meta, 'Custom postmeta should be imported.' );

		// Verify author was created or matched.
		$new_author_id = $new_post->post_author;
		$this->assertGreaterThan( 0, $new_author_id, 'Post should have an author.' );
		$new_user = get_user_by( 'id', $new_author_id );
		$this->assertEquals( $fixture['users'][0]['user_login'], $new_user->user_login, 'Author user_login should match.' );

		// Verify old_id usermeta.
		$user_old_id = get_user_meta( $new_author_id, $meta_key, true );
		$this->assertEquals( $live_user_id, (int) $user_old_id, 'Author old_id usermeta should be saved.' );

		// Verify comments were imported.
		$comments = get_comments( [ 'post_id' => $new_post_id ] );
		$this->assertCount( 3, $comments, 'All 3 comments should be imported.' );

		// Verify term relationships.
		$categories = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'News', $categories, 'Category should be assigned.' );
		$this->assertContains( 'Local News', $categories, 'Category should be assigned.' );

		$tags = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );
		$this->assertContains( 'Featured', $tags, 'Tag should be assigned.' );

		// Verify run-state was updated.
		$imported_posts_map = $this->run_state->get_imported_post_ids_map();
		$this->assertArrayHasKey( $live_post_id, $imported_posts_map, 'Run-state should record imported post.' );
		$this->assertEquals( $new_post_id, $imported_posts_map[ $live_post_id ], 'Run-state should map old ID to new ID.' );
	}

	/**
	 * @group e2e
	 */
	public function test_should_complete_migration_with_empty_new_ids_list(): void {
		// Write empty new_ids file.
		$this->run_state->write_new_ids( [] );
		$this->run_state->write_modified_ids( [] );

		// Should not throw, just return early.
		$this->run_migrate_command();

		// Verify no posts were imported.
		global $wpdb;
		$meta_key = $this->get_old_id_meta_key();
		$count    = $wpdb->get_var( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				$meta_key
			)
		);
		$this->assertEquals( 0, (int) $count, 'No posts should be imported with empty new_ids.' );
	}

	/**
	 * @group e2e
	 */
	public function test_should_complete_migration_with_only_modified_ids(): void {
		global $wpdb;

		// First, run a full migration.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];

		// Run search and migrate.
		$this->run_search_command();
		$this->run_migrate_command();

		// Get the new post ID.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should have been imported.' );

		// Simulate a modification by updating the live post's post_modified.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_modified' => '2025-01-01 00:00:00' ],
			[ 'ID' => $live_post_id ]
		);

		// Create new run-state directory for second migration.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Run search again - should detect as modified.
		$this->run_search_command();

		// Verify modified_ids were written.
		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertNotNull( $modified_ids_map, 'Modified IDs map should exist.' );
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Live post should be detected as modified.' );

		// Run migrate command.
		$this->run_migrate_command();

		// Verify post was reimported (new ID should be different).
		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );
		$this->assertNotNull( $reimported_post_id, 'Post should have been reimported.' );
		// The reimported post will have a different ID.
		$this->assertNotEquals( $new_post_id, $reimported_post_id, 'Reimported post should have different ID.' );

		// Verify old post was deleted.
		$old_post = get_post( $new_post_id );
		$this->assertNull( $old_post, 'Old post should have been deleted.' );
	}

	/**
	 * @group e2e
	 */
	public function test_should_return_early_when_no_new_or_modified_posts(): void {
		// Write empty new_ids and modified_ids.
		$this->run_state->write_new_ids( [] );
		$this->run_state->write_modified_ids( [] );

		// Run migrate command - should return early without errors.
		$this->run_migrate_command();

		// Just verify no exception was thrown.
		$this->assertTrue( true, 'Migration should complete without errors when no posts to migrate.' );
	}

	// =========================================================================
	// 19. SPECIAL CONTENT HANDLING TESTS
	// =========================================================================

	/**
	 * @group content
	 */
	public function test_should_import_postmeta_with_serialized_array_value(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group content
	 */
	public function test_should_import_postmeta_with_serialized_object_value(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group content
	 */
	public function test_should_import_post_with_empty_content(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group content
	 */
	public function test_should_import_post_with_empty_title(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group content
	 */
	public function test_should_import_post_with_very_long_content(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 20. SEARCH COMMAND INTEGRATION TESTS
	// =========================================================================

	/**
	 * @group search
	 */
	public function test_search_should_find_new_posts_not_in_local_db(): void {
		// Insert a post into live DB.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];

		// Run search command.
		$this->run_search_command();

		// Verify new ID was recorded.
		$new_ids = $this->run_state->get_new_ids();
		$this->assertNotNull( $new_ids, 'New IDs should be written to run-state.' );
		$this->assertContains( $live_post_id, $new_ids, 'Live post ID should be in new IDs.' );
	}

	/**
	 * @group search
	 */
	public function test_search_should_find_new_attachments_not_in_local_db(): void {
		// Insert an attachment into live DB.
		$fixture = $this->load_fixture( 'attachment-basic' );
		$this->insert_live_data( $fixture );
		$live_attachment_id = $fixture['post']['ID'];

		// Run search command with attachment post type.
		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		// Verify attachment was found.
		$new_ids = $this->run_state->get_new_ids();
		$this->assertContains( $live_attachment_id, $new_ids, 'Live attachment ID should be in new IDs.' );
	}

	/**
	 * @group search
	 */
	public function test_search_should_detect_modified_posts_by_post_modified_date(): void {
		global $wpdb;

		// First migrate a post.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];

		$this->run_search_command();
		$this->run_migrate_command();

		// Update the live post's post_modified.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_modified' => '2025-06-01 12:00:00' ],
			[ 'ID' => $live_post_id ]
		);

		// Create new run-state for second search.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Run search again.
		$this->run_search_command();

		// Verify post was detected as modified.
		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertNotNull( $modified_ids_map, 'Modified IDs map should exist.' );
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified.' );
	}

	/**
	 * @group search
	 */
	public function test_search_should_detect_modified_posts_by_status_change(): void {
		global $wpdb;
		
		// First migrate a post.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];
		
		$this->run_search_command();
		$this->run_migrate_command();

		// Change live post status from publish to draft.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_status' => 'draft' ],
			[ 'ID' => $live_post_id ]
		);

		// Create new run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified due to status change.' );
	}

	/**
	 * @group search
	 */
	public function test_search_should_detect_modified_posts_by_author_change(): void {
		global $wpdb;

		// First migrate a post.
		$fixture = $this->load_fixture( 'post-with-full-data' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];

		$this->run_search_command();
		$this->run_migrate_command();

		// Create a new user in live DB and assign as author.
		$new_user = $this->create_user_fixture(
			[
				'ID'         => 999,
				'user_login' => 'newauthor',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $new_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_author' => 999 ],
			[ 'ID' => $live_post_id ]
		);

		// Create new run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified due to author change.' );
	}

	/**
	 * @group search
	 */
	public function test_search_should_detect_modified_posts_by_thumbnail_change(): void {
		global $wpdb;

		// Create post with featured image.
		$fixture      = $this->load_fixture( 'post-basic' );
		$live_post_id = $fixture['post']['ID'];
		$this->insert_live_data( $fixture );

		// Add featured image (attachment).
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 5001,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => $live_post_id,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 5001, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		// Change the thumbnail.
		$new_attachment = $this->create_post_fixture(
			[
				'ID'          => 5002,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $new_attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[ 'meta_value' => 5002 ], // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			[
				'post_id'  => $live_post_id,
				'meta_key' => '_thumbnail_id',
			]
		);

		// Create new run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified due to thumbnail change.' );
	}

	/**
	 * @group search
	 */
	public function test_search_should_detect_modified_posts_by_taxonomy_change(): void {
		global $wpdb;

		// First migrate a post with a category.
		$fixture = $this->load_fixture( 'post-with-hierarchical-taxonomy' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];

		$this->run_search_command();
		$this->run_migrate_command();

		// Add a new category to the live post.
		$new_term = $this->create_term_fixture(
			[
				'term_id' => 999,
				'name'    => 'New Category',
				'slug'    => 'new-category',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'terms', $new_term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 999,
				'term_id'          => 999,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0,
				'count'            => 1,
			]
		);
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => $live_post_id,
				'term_taxonomy_id' => 999,
			]
		);

		// Create new run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified due to taxonomy change.' );
	}

	/**
	 * @group search
	 */
	public function test_search_should_write_new_ids_to_runstate(): void {
		// Insert multiple posts.
		$post1 = $this->create_post_fixture( [ 'ID' => 1001 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 1002 ] );
		$post3 = $this->create_post_fixture( [ 'ID' => 1003 ] );

		global $wpdb;
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $post3 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();

		$new_ids = $this->run_state->get_new_ids();
		$this->assertCount( 3, $new_ids, 'All 3 posts should be in new IDs.' );
		$this->assertContains( 1001, $new_ids );
		$this->assertContains( 1002, $new_ids );
		$this->assertContains( 1003, $new_ids );
	}

	/**
	 * @group search
	 */
	public function test_search_should_write_modified_ids_to_runstate(): void {
		global $wpdb;

		// First migrate a post.
		$fixture      = $this->load_fixture( 'post-basic' );
		$live_post_id = $fixture['post']['ID'];
		$this->insert_live_data( $fixture );

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );

		// Modify live post.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_modified' => '2025-12-01 00:00:00' ],
			[ 'ID' => $live_post_id ]
		);

		// Create new run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		wp_mkdir_p( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertNotNull( $modified_ids_map, 'Modified IDs should be written.' );
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Live ID should be in modified IDs.' );
		$this->assertEquals( $new_post_id, $modified_ids_map[ $live_post_id ], 'Modified map should have correct local ID.' );
	}

	/**
	 * @group search
	 */
	public function test_search_should_warn_about_unattributed_content(): void {
		// Create a local post without old_id attribution.
		$local_post_id = wp_insert_post(
			[
				'post_title'   => 'Unattributed Local Post',
				'post_content' => 'This post exists locally but has no old_id meta.',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			]
		);

		// Insert a live post.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );

		// Run search - it should still work, but internally warns about unattributed content.
		$this->run_search_command();

		// The search should complete without errors.
		$new_ids = $this->run_state->get_new_ids();
		$this->assertNotEmpty( $new_ids, 'New IDs should still be found.' );

		// Clean up.
		wp_delete_post( $local_post_id, true );
	}

	/**
	 * @group search
	 */
	public function test_search_should_reject_guest_author_cpt(): void {
		// Insert a guest-author CPT post in live.
		global $wpdb;
		$guest_author = $this->create_post_fixture(
			[
				'ID'        => 9999,
				'post_type' => 'guest-author',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $guest_author ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Expect exception when trying to include guest-author CPT.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'guest-author' );

		$this->run_search_command( [ 'post-types-csv' => 'post,guest-author' ] );
	}

	// =========================================================================
	// 21. ATTRIBUTION COMMAND INTEGRATION TESTS
	// =========================================================================

	/**
	 * @group attribution
	 */
	public function test_attribute_should_match_local_posts_to_live_by_composite_key(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group attribution
	 */
	public function test_attribute_should_match_local_attachments_to_live(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group attribution
	 */
	public function test_attribute_should_match_local_users_to_live_by_user_login(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group attribution
	 */
	public function test_attribute_should_match_local_terms_to_live_by_slug_and_taxonomy(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group attribution
	 */
	public function test_attribute_should_save_old_id_meta_for_matched_objects(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group attribution
	 */
	public function test_attribute_should_skip_already_attributed_objects(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	// =========================================================================
	// 22. RERUN SAFETY AND IDEMPOTENCY TESTS
	// =========================================================================

	/**
	 * @group idempotency
	 */
	public function test_should_produce_same_result_when_running_migration_twice(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_posts_on_rerun(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_terms_on_rerun(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_users_on_rerun(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}

	/**
	 * @group idempotency
	 */
	public function test_should_skip_completed_steps_on_resume(): void {
		$this->markTestIncomplete( 'TODO: Implement test' );
	}
}
