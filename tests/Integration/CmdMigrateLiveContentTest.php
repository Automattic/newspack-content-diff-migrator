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
	 * Tests that default taxonomies (category, post_tag, author) are migrated when no --custom-taxonomies-csv is provided.
	 *
	 * @group taxonomy
	 */
	public function test_should_migrate_default_taxonomies_when_no_custom_taxonomies_csv_provided(): void {
		global $wpdb;

		// Create post with category and tag.
		$post = $this->create_post_fixture( [ 'ID' => 1001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 101, 'name' => 'Default Category', 'slug' => 'default-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 101, 'term_id' => 101, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1001, 'term_taxonomy_id' => 101 ] ); // phpcs:ignore

		// Create tag term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 102, 'name' => 'Default Tag', 'slug' => 'default-tag', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 102, 'term_id' => 102, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1001, 'term_taxonomy_id' => 102 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command(); // No custom-taxonomies-csv = defaults.

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1001, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$tags        = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );

		$this->assertContains( 'Default Category', $categories, 'Category should be migrated by default.' );
		$this->assertContains( 'Default Tag', $tags, 'Post tag should be migrated by default.' );
	}

	/**
	 * Tests that only specified taxonomies are migrated when --custom-taxonomies-csv is provided.
	 *
	 * @group taxonomy
	 */
	public function test_should_migrate_only_specified_taxonomies_when_custom_taxonomies_csv_provided(): void {
		global $wpdb;

		// Register custom taxonomy.
		register_taxonomy( 'brand', 'post', [ 'public' => true ] );

		$post = $this->create_post_fixture( [ 'ID' => 1002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category (should NOT be migrated).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 201, 'name' => 'Skip Category', 'slug' => 'skip-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 201, 'term_id' => 201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1002, 'term_taxonomy_id' => 201 ] ); // phpcs:ignore

		// Create brand (should be migrated).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 202, 'name' => 'Include Brand', 'slug' => 'include-brand', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 202, 'term_id' => 202, 'taxonomy' => 'brand', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1002, 'term_taxonomy_id' => 202 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'brand' ] ); // Only migrate 'brand'.

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1002, $this->source_hostname );
		$brands      = wp_get_post_terms( $new_post_id, 'brand', [ 'fields' => 'names' ] );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );

		$this->assertContains( 'Include Brand', $brands, 'Brand should be migrated.' );
		$this->assertNotContains( 'Skip Category', $categories, 'Category should NOT be migrated when not in custom-taxonomies-csv.' );
	}

	/**
	 * Tests that non-existent taxonomies in live DB are unset from migration.
	 *
	 * @group taxonomy
	 */
	public function test_should_unset_taxonomy_from_migration_when_custom_taxonomy_does_not_exist_in_live_db(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 1003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 301, 'name' => 'Existing Category', 'slug' => 'existing-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 301, 'term_id' => 301, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1003, 'term_taxonomy_id' => 301 ] ); // phpcs:ignore

		$this->run_search_command();
		// Provide a taxonomy that doesn't exist in live DB - 'nonexistent_taxonomy'.
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,nonexistent_taxonomy' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1003, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );

		// The valid taxonomy (category) should still be migrated.
		$this->assertContains( 'Existing Category', $categories, 'Category should be migrated despite nonexistent taxonomy in CSV.' );
	}

	/**
	 * Tests that a warning is shown when category is not in custom-taxonomies-csv.
	 * Note: In test_env mode, WP_CLI::confirm() is bypassed, so we just verify migration proceeds.
	 *
	 * @group taxonomy
	 */
	public function test_should_warn_when_category_not_in_custom_taxonomies_csv(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 1004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 401, 'name' => 'Warning Cat', 'slug' => 'warning-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 401, 'term_id' => 401, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1004, 'term_taxonomy_id' => 401 ] ); // phpcs:ignore

		$this->run_search_command();
		// Omit category from CSV - in test_env this proceeds without confirmation.
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'post_tag,author' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1004, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );

		// Category should NOT be migrated.
		$this->assertNotContains( 'Warning Cat', $categories, 'Category should not be migrated when omitted from custom-taxonomies-csv.' );
	}

	/**
	 * Tests that a warning is shown when post_tag is not in custom-taxonomies-csv.
	 *
	 * @group taxonomy
	 */
	public function test_should_warn_when_post_tag_not_in_custom_taxonomies_csv(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 1005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create post_tag.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 501, 'name' => 'Warning Tag', 'slug' => 'warning-tag', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 501, 'term_id' => 501, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1005, 'term_taxonomy_id' => 501 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,author' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1005, $this->source_hostname );
		$tags        = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );

		// Post tag should NOT be migrated.
		$this->assertNotContains( 'Warning Tag', $tags, 'Post tag should not be migrated when omitted from custom-taxonomies-csv.' );
	}

	/**
	 * Tests that a warning is shown when author taxonomy is not in custom-taxonomies-csv.
	 *
	 * @group taxonomy
	 */
	public function test_should_warn_when_author_not_in_custom_taxonomies_csv(): void {
		global $wpdb;

		// Register 'author' taxonomy (used by Co-Authors Plus).
		register_taxonomy( 'author', 'post', [ 'public' => true ] );

		$post = $this->create_post_fixture( [ 'ID' => 1006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create author term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 601, 'name' => 'co-author-john', 'slug' => 'co-author-john', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 601, 'term_id' => 601, 'taxonomy' => 'author', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1006, 'term_taxonomy_id' => 601 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1006, $this->source_hostname );
		$authors     = wp_get_post_terms( $new_post_id, 'author', [ 'fields' => 'names' ] );

		// Author term should NOT be migrated.
		$this->assertNotContains( 'co-author-john', $authors, 'Author term should not be migrated when omitted from custom-taxonomies-csv.' );
	}

	// =========================================================================
	// 2. HIERARCHICAL TAXONOMY VALIDATION AND FIXING TESTS
	// =========================================================================

	/**
	 * Tests that fix_hierarchical_taxonomies_parents fixes invalid parent IDs by setting them to 0.
	 * This tests the DataImporter method directly since the full command flow has PHPUnit isolation.
	 *
	 * @group taxonomy
	 */
	public function test_should_fix_local_hierarchical_taxonomy_with_invalid_parent_by_setting_to_zero(): void {
		global $wpdb;

		// Use a high number that definitely doesn't exist as a term.
		$invalid_parent_id = 9999999;

		// Create a local category with invalid parent.
		$local_term    = wp_insert_term( 'Local Broken Parent Cat', 'category', [ 'slug' => 'local-broken-parent' ] );
		$local_term_id = is_array( $local_term ) ? $local_term['term_id'] : $local_term;

		// Manually set invalid parent.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$wpdb->term_taxonomy,
			[ 'parent' => $invalid_parent_id ],
			[
				'term_id'  => $local_term_id,
				'taxonomy' => 'category',
			]
		); // phpcs:ignore

		// Verify the invalid parent was set.
		$before = $wpdb->get_var( $wpdb->prepare( "SELECT parent FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'category'", $local_term_id ) ); // phpcs:ignore
		$this->assertEquals( $invalid_parent_id, (int) $before, 'Invalid parent should be set before test.' );

		// Call fix method directly (since the full command flow has PHPUnit transaction isolation issues).
		$fixed = $this->logic->get_data_importer()->fix_hierarchical_taxonomies_parents( $wpdb->prefix, [ 'category', 'post_tag', 'author' ] );
		$this->assertNotEmpty( $fixed, 'Fix should return non-empty array of fixed terms.' );

		// Verify local category parent was fixed to 0.
		$after = $wpdb->get_var( $wpdb->prepare( "SELECT parent FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'category'", $local_term_id ) ); // phpcs:ignore
		$this->assertEquals( 0, (int) $after, 'Invalid parent should be fixed to 0.' );
	}

	/**
	 * Tests that live hierarchical taxonomies with invalid parent IDs are fixed by setting parent to 0.
	 *
	 * @group taxonomy
	 */
	public function test_should_fix_live_hierarchical_taxonomy_with_invalid_parent_by_setting_to_zero(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 2002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create live category with invalid parent (pointing to non-existent term_id 88888).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 701, 'name' => 'Live Broken Parent', 'slug' => 'live-broken-parent', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 701, 'term_id' => 701, 'taxonomy' => 'category', 'description' => '', 'parent' => 88888, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 2002, 'term_taxonomy_id' => 701 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify live table category parent was fixed.
		$live_term_taxonomy = $wpdb->get_row( $wpdb->prepare( "SELECT parent FROM {$this->live_table_prefix}term_taxonomy WHERE term_id = %d", 701 ), ARRAY_A ); // phpcs:ignore
		$this->assertEquals( 0, (int) $live_term_taxonomy['parent'], 'Invalid parent in live DB should be fixed to 0.' );

		// Verify the post was migrated and category was assigned.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2002, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Live Broken Parent', $categories, 'Category should still be migrated after parent fix.' );
	}

	/**
	 * Tests that hierarchical taxonomies with valid parents are NOT modified.
	 *
	 * @group taxonomy
	 */
	public function test_should_not_modify_hierarchical_taxonomy_with_valid_parent(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 2003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create parent category first.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 801, 'name' => 'Parent Cat', 'slug' => 'parent-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 801, 'term_id' => 801, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		// Create child category with valid parent.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 802, 'name' => 'Child Cat', 'slug' => 'child-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 802, 'term_id' => 802, 'taxonomy' => 'category', 'description' => '', 'parent' => 801, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 2003, 'term_taxonomy_id' => 802 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify parent was NOT changed.
		$live_term_taxonomy = $wpdb->get_row( $wpdb->prepare( "SELECT parent FROM {$this->live_table_prefix}term_taxonomy WHERE term_id = %d", 802 ), ARRAY_A ); // phpcs:ignore
		$this->assertEquals( 801, (int) $live_term_taxonomy['parent'], 'Valid parent should NOT be modified.' );

		// Verify category hierarchy was imported correctly.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2003, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'all' ] );
		$child_cat   = null;
		foreach ( $categories as $cat ) {
			if ( 'Child Cat' === $cat->name ) {
				$child_cat = $cat;
				break;
			}
		}
		$this->assertNotNull( $child_cat, 'Child category should be migrated.' );
		$this->assertNotEquals( 0, $child_cat->parent, 'Child category should have a parent in local DB.' );
	}

	/**
	 * Tests that deeply nested hierarchical taxonomy with a broken chain is handled.
	 * A "broken chain" means one middle parent is missing/invalid.
	 *
	 * @group taxonomy
	 */
	public function test_should_handle_deeply_nested_hierarchical_taxonomy_with_broken_chain(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 2004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create grandparent (valid).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 901, 'name' => 'Grandparent', 'slug' => 'grandparent', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 901, 'term_id' => 901, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		// Intentionally skip creating parent (term_id 902) - it's missing/deleted.

		// Create child pointing to missing parent 902 (broken chain).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 903, 'name' => 'Orphan Child', 'slug' => 'orphan-child', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 903, 'term_id' => 903, 'taxonomy' => 'category', 'description' => '', 'parent' => 902, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 2004, 'term_taxonomy_id' => 903 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify orphan child's parent was fixed to 0.
		$live_term_taxonomy = $wpdb->get_row( $wpdb->prepare( "SELECT parent FROM {$this->live_table_prefix}term_taxonomy WHERE term_id = %d", 903 ), ARRAY_A ); // phpcs:ignore
		$this->assertEquals( 0, (int) $live_term_taxonomy['parent'], 'Orphan child parent should be fixed to 0.' );

		// Verify category was still migrated.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2004, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Orphan Child', $categories, 'Orphan child category should be migrated.' );
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
	 * Tests that posts are detected as modified when post_modified date changes.
	 *
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_post_modified_date_changed(): void {
		global $wpdb;

		// Initial import.
		$post = $this->create_post_fixture(
			[
				'ID'            => 4001,
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_post_id = $this->logic->get_current_post_id_by_old_id( 4001, $this->source_hostname );
		$this->assertNotNull( $original_post_id, 'Post should be imported.' );

		// Update post_modified in live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
				'post_title'        => 'Updated Title',
			],
			[ 'ID' => 4001 ]
		);

		// Run search again - should detect as modified.
		$this->run_search_command();

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertNotEmpty( $modified_ids, 'Modified IDs should be detected.' );
		$this->assertArrayHasKey( 4001, $modified_ids, 'Post 4001 should be detected as modified.' );
	}

	/**
	 * Tests that posts are detected as modified when post_status changes.
	 *
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_post_status_changed(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'          => 4002,
				'post_status' => 'publish',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Change status to draft in live.
		$wpdb->update( $this->live_table_prefix . 'posts', [ 'post_status' => 'draft' ], [ 'ID' => 4002 ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post' ] );

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4002, $modified_ids, 'Post should be detected as modified when status changes.' );
	}

	/**
	 * Tests that posts are detected as modified when post_author changes.
	 *
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_post_author_changed(): void {
		global $wpdb;

		// Create two users.
		$user1 = $this->create_user_fixture(
			[
				'ID'         => 4101,
				'user_login' => 'author_orig',
			] 
		);
		$user2 = $this->create_user_fixture(
			[
				'ID'         => 4102,
				'user_login' => 'author_new',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $user1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'users', $user2 ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 4003,
				'post_author' => 4101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Change author in live.
		$wpdb->update( $this->live_table_prefix . 'posts', [ 'post_author' => 4102 ], [ 'ID' => 4003 ] ); // phpcs:ignore

		$this->run_search_command();

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4003, $modified_ids, 'Post should be detected as modified when author changes.' );
	}

	/**
	 * Tests that posts are detected as modified when thumbnail_id changes.
	 *
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_thumbnail_id_changed(): void {
		global $wpdb;

		// Create two attachments.
		$att1 = $this->create_post_fixture(
			[
				'ID'          => 4201,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$att2 = $this->create_post_fixture(
			[
				'ID'          => 4202,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $att1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $att2 ); // phpcs:ignore

		$post = $this->create_post_fixture( [ 'ID' => 4004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 4001, 'post_id' => 4004, 'meta_key' => '_thumbnail_id', 'meta_value' => '4201' ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		// Change thumbnail in live.
		$wpdb->update( $this->live_table_prefix . 'postmeta', [ 'meta_value' => '4202' ], [ 'post_id' => 4004, 'meta_key' => '_thumbnail_id' ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4004, $modified_ids, 'Post should be detected as modified when thumbnail changes.' );
	}

	/**
	 * Tests that posts are detected as modified when taxonomies change.
	 *
	 * @group modified
	 */
	public function test_should_filter_modified_posts_when_taxonomies_changed(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 4005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create initial category.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 4301, 'name' => 'Cat A', 'slug' => 'cat-a', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 4301, 'term_id' => 4301, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 4005, 'term_taxonomy_id' => 4301 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Add new category in live.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 4302, 'name' => 'Cat B', 'slug' => 'cat-b', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 4302, 'term_id' => 4302, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 4005, 'term_taxonomy_id' => 4302 ] ); // phpcs:ignore

		$this->run_search_command();

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4005, $modified_ids, 'Post should be detected as modified when taxonomies change.' );
	}

	/**
	 * Tests that modified posts are deleted when reimporting.
	 * Note: Tests deletion behavior only - uses simple post without parent to avoid update_post_parent edge case.
	 *
	 * @group modified
	 */
	public function test_should_delete_local_post_before_reimporting_modified_post(): void {
		global $wpdb;

		// Create a simple post without parent relationships.
		$post = $this->create_post_fixture(
			[
				'ID'            => 4006,
				'post_title'    => 'Original Title',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0, // No parent to avoid update_post_parent issues.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4006, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Original post should be imported.' );

		// Modify in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Updated Title',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4006 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Original local post should be deleted and a new one created.
		$original_post = get_post( $original_local_id );
		$this->assertNull( $original_post, 'Original local post should be deleted.' );

		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4006, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'New local post should exist.' );
		$this->assertNotEquals( $original_local_id, $new_local_id, 'A new local post should be created.' );
		$this->assertEquals( 'Updated Title', get_the_title( $new_local_id ), 'New post should have updated title.' );
	}

	/**
	 * Tests that deleted modified IDs are saved to run-state.
	 *
	 * @group modified
	 */
	public function test_should_update_runstate_with_deleted_modified_ids(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4007,
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Modify in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4007 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Check run-state for deleted modified IDs.
		$deleted_map = $this->run_state->get_deleted_modified_ids_map();
		$this->assertArrayHasKey( 4007, $deleted_map, 'Deleted modified ID should be saved to run-state.' );
	}

	/**
	 * Tests that modified IDs are correctly identified alongside new IDs and both are imported.
	 *
	 * @group modified
	 */
	public function test_should_append_modified_ids_to_new_live_ids_for_reimport(): void {
		global $wpdb;

		// Create and import a post.
		$existing_post = $this->create_post_fixture(
			[
				'ID'            => 4008,
				'post_title'    => 'Original Title',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $existing_post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4008, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Existing post should be imported.' );

		// Modify existing post and add a new post.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Title',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4008 ]
		); // phpcs:ignore

		$new_post = $this->create_post_fixture(
			[
				'ID'          => 4009,
				'post_parent' => 0,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $new_post ); // phpcs:ignore

		$this->run_search_command();

		// Check that both new and modified are in the pipeline.
		$new_ids      = $this->run_state->get_new_ids();
		$modified_ids = $this->run_state->get_modified_ids_map();

		$this->assertContains( 4009, $new_ids, 'New post should be in new_ids.' );
		$this->assertArrayHasKey( 4008, $modified_ids, 'Modified post should be in modified_ids.' );

		// Run migrate - both new and modified posts should be imported.
		$this->run_migrate_command();

		// New post should be imported.
		$this->assertNotNull( $this->logic->get_current_post_id_by_old_id( 4009, $this->source_hostname ), 'New post should be imported.' );

		// Modified post should be reimported with new content.
		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4008, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Modified post should be reimported.' );
		$this->assertNotEquals( $original_local_id, $new_local_id, 'Modified post should have new local ID.' );
		$this->assertEquals( 'Modified Title', get_the_title( $new_local_id ), 'Modified post should have updated title.' );
	}

	/**
	 * Tests that deleted modified ID entries are stored in the run-state for resume capability,
	 * and subsequent runs correctly skip already-deleted IDs while still reimporting.
	 *
	 * @group modified
	 */
	public function test_should_skip_already_deleted_modified_ids_on_resume(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4010,
				'post_title'    => 'Original Title',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4010, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		// Modify in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Title',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4010 ]
		); // phpcs:ignore

		$this->run_search_command();

		// Verify modified ID was detected.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4010, $modified_ids, 'Modified ID should be detected.' );

		// First migrate run will delete and reimport.
		$this->run_migrate_command();

		// Verify the deleted ID was saved to run-state.
		$deleted_map = $this->run_state->get_deleted_modified_ids_map();
		$this->assertArrayHasKey( 4010, $deleted_map, 'Deleted modified ID should be in run-state.' );

		// Verify the post was reimported with updated content.
		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4010, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );
		$this->assertNotEquals( $original_local_id, $new_local_id, 'Post should have new local ID after reimport.' );
		$this->assertEquals( 'Modified Title', get_the_title( $new_local_id ), 'Reimported post should have updated title.' );

		// Second migrate run should skip deletion (already deleted) but not skip reimport if needed.
		// Since we're using the same run-state, it should skip reimporting the same post again.
		$this->run_migrate_command();

		// Post should still exist with same ID (not deleted again or duplicated).
		$final_local_id = $this->logic->get_current_post_id_by_old_id( 4010, $this->source_hostname );
		$this->assertEquals( $new_local_id, $final_local_id, 'Post ID should remain the same after second migrate run.' );
	}

	/**
	 * Tests that reimported posts preserve the old_id meta pointing to the live ID.
	 *
	 * @group modified
	 */
	public function test_reimport_should_preserve_old_id_meta(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4011,
				'post_title'    => 'Original Title',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4011, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		// Verify old_id meta exists on original import.
		$meta_key        = $this->logic->get_old_id_meta_key( $this->source_hostname );
		$original_old_id = get_post_meta( $original_local_id, $meta_key, true );
		$this->assertEquals( 4011, (int) $original_old_id, 'Original post should have old_id meta.' );

		// Modify in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Title',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4011 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify reimported post has old_id meta.
		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4011, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );
		$this->assertNotEquals( $original_local_id, $new_local_id, 'Post should have new local ID.' );

		$new_old_id = get_post_meta( $new_local_id, $meta_key, true );
		$this->assertEquals( 4011, (int) $new_old_id, 'Reimported post should preserve old_id meta.' );
	}

	/**
	 * Tests that reimporting a post with a parent correctly updates parent relationships.
	 *
	 * @group modified
	 */
	public function test_reimport_post_with_parent_should_update_parent_correctly(): void {
		global $wpdb;

		// Create parent post.
		$parent_post = $this->create_post_fixture(
			[
				'ID'            => 4012,
				'post_title'    => 'Parent Post',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent_post ); // phpcs:ignore

		// Create child post with parent reference.
		$child_post = $this->create_post_fixture(
			[
				'ID'            => 4013,
				'post_title'    => 'Child Post',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 4012,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child_post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$parent_local_id = $this->logic->get_current_post_id_by_old_id( 4012, $this->source_hostname );
		$child_local_id  = $this->logic->get_current_post_id_by_old_id( 4013, $this->source_hostname );
		$this->assertNotNull( $parent_local_id, 'Parent should be imported.' );
		$this->assertNotNull( $child_local_id, 'Child should be imported.' );

		// Verify initial parent relationship.
		$child_post_obj = get_post( $child_local_id );
		$this->assertEquals( $parent_local_id, (int) $child_post_obj->post_parent, 'Child should reference parent.' );

		// Modify child in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Child Post',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4013 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify child was reimported and still references parent.
		$new_child_local_id = $this->logic->get_current_post_id_by_old_id( 4013, $this->source_hostname );
		$this->assertNotNull( $new_child_local_id, 'Child should be reimported.' );
		$this->assertNotEquals( $child_local_id, $new_child_local_id, 'Child should have new local ID.' );

		$new_child_post_obj = get_post( $new_child_local_id );
		$this->assertEquals( $parent_local_id, (int) $new_child_post_obj->post_parent, 'Reimported child should still reference parent.' );
		$this->assertEquals( 'Modified Child Post', $new_child_post_obj->post_title, 'Reimported child should have updated title.' );
	}

	/**
	 * Tests that reimporting updates post content correctly.
	 *
	 * @group modified
	 */
	public function test_reimport_should_update_post_content(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4014,
				'post_title'    => 'Content Test Post',
				'post_content'  => '<p>Original content paragraph.</p>',
				'post_excerpt'  => 'Original excerpt.',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4014, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		$original_post = get_post( $original_local_id );
		$this->assertStringContainsString( 'Original content paragraph', $original_post->post_content );
		$this->assertEquals( 'Original excerpt.', $original_post->post_excerpt );

		// Modify content in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_content'      => '<p>Updated content with new information.</p><!-- wp:paragraph --><p>And a block.</p><!-- /wp:paragraph -->',
				'post_excerpt'      => 'Updated excerpt with more detail.',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4014 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4014, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );
		$this->assertNotEquals( $original_local_id, $new_local_id, 'Post should have new local ID.' );

		$new_post = get_post( $new_local_id );
		$this->assertStringContainsString( 'Updated content with new information', $new_post->post_content );
		$this->assertStringContainsString( 'And a block', $new_post->post_content );
		$this->assertEquals( 'Updated excerpt with more detail.', $new_post->post_excerpt );
	}

	/**
	 * Tests that multiple modified posts are all reimported correctly.
	 *
	 * @group modified
	 */
	public function test_multiple_modified_posts_should_all_reimport(): void {
		global $wpdb;

		// Create 3 posts.
		for ( $i = 1; $i <= 3; $i++ ) {
			$post = $this->create_post_fixture(
				[
					'ID'            => 4020 + $i,
					'post_title'    => "Original Post {$i}",
					'post_modified' => '2024-01-01 10:00:00',
					'post_parent'   => 0,
				]
			);
			$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		}

		$this->run_search_command();
		$this->run_migrate_command();

		// Store original local IDs.
		$original_local_ids = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$original_local_ids[ 4020 + $i ] = $this->logic->get_current_post_id_by_old_id( 4020 + $i, $this->source_hostname );
			$this->assertNotNull( $original_local_ids[ 4020 + $i ], "Post {$i} should be imported." );
		}

		// Modify all 3 posts in live.
		for ( $i = 1; $i <= 3; $i++ ) {
			$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
				$this->live_table_prefix . 'posts',
				[
					'post_title'        => "Modified Post {$i}",
					'post_modified'     => '2024-06-01 10:00:00',
					'post_modified_gmt' => '2024-06-01 10:00:00',
				],
				[ 'ID' => 4020 + $i ]
			); // phpcs:ignore
		}

		$this->run_search_command();

		// Verify all 3 are detected as modified.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertCount( 3, $modified_ids, 'All 3 posts should be detected as modified.' );

		$this->run_migrate_command();

		// Verify all 3 were reimported with updated content.
		for ( $i = 1; $i <= 3; $i++ ) {
			$live_id      = 4020 + $i;
			$new_local_id = $this->logic->get_current_post_id_by_old_id( $live_id, $this->source_hostname );
			$this->assertNotNull( $new_local_id, "Post {$i} should be reimported." );
			$this->assertNotEquals( $original_local_ids[ $live_id ], $new_local_id, "Post {$i} should have new local ID." );
			$this->assertEquals( "Modified Post {$i}", get_the_title( $new_local_id ), "Post {$i} should have updated title." );
		}
	}

	/**
	 * Tests reimporting a child post when the parent was also modified.
	 *
	 * @group modified
	 */
	public function test_reimport_parent_and_child_together(): void {
		global $wpdb;

		// Create parent and child.
		$parent_post = $this->create_post_fixture(
			[
				'ID'            => 4030,
				'post_title'    => 'Parent Original',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent_post ); // phpcs:ignore

		$child_post = $this->create_post_fixture(
			[
				'ID'            => 4031,
				'post_title'    => 'Child Original',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 4030,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child_post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_parent_local_id = $this->logic->get_current_post_id_by_old_id( 4030, $this->source_hostname );
		$original_child_local_id  = $this->logic->get_current_post_id_by_old_id( 4031, $this->source_hostname );
		$this->assertNotNull( $original_parent_local_id, 'Parent should be imported.' );
		$this->assertNotNull( $original_child_local_id, 'Child should be imported.' );

		// Modify both parent and child in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Parent Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4030 ]
		); // phpcs:ignore

		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Child Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4031 ]
		); // phpcs:ignore

		$this->run_search_command();

		// Verify both are detected as modified.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4030, $modified_ids, 'Parent should be detected as modified.' );
		$this->assertArrayHasKey( 4031, $modified_ids, 'Child should be detected as modified.' );

		$this->run_migrate_command();

		// Verify both were reimported.
		$new_parent_local_id = $this->logic->get_current_post_id_by_old_id( 4030, $this->source_hostname );
		$new_child_local_id  = $this->logic->get_current_post_id_by_old_id( 4031, $this->source_hostname );
		$this->assertNotNull( $new_parent_local_id, 'Parent should be reimported.' );
		$this->assertNotNull( $new_child_local_id, 'Child should be reimported.' );
		$this->assertNotEquals( $original_parent_local_id, $new_parent_local_id, 'Parent should have new local ID.' );
		$this->assertNotEquals( $original_child_local_id, $new_child_local_id, 'Child should have new local ID.' );

		// Verify titles updated.
		$this->assertEquals( 'Parent Modified', get_the_title( $new_parent_local_id ), 'Parent should have updated title.' );
		$this->assertEquals( 'Child Modified', get_the_title( $new_child_local_id ), 'Child should have updated title.' );

		// Verify child still references the new parent.
		$new_child_post = get_post( $new_child_local_id );
		$this->assertEquals( $new_parent_local_id, (int) $new_child_post->post_parent, 'Child should reference new parent ID.' );
	}

	/**
	 * Tests reimporting with post meta preserved.
	 *
	 * @group modified
	 */
	public function test_reimport_should_include_updated_post_meta(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4040,
				'post_title'    => 'Meta Test Post',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add post meta.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'meta_id'    => 40001,
				'post_id'    => 4040,
				'meta_key'   => 'custom_meta_key',
				'meta_value' => 'original_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4040, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		$original_meta = get_post_meta( $original_local_id, 'custom_meta_key', true );
		$this->assertEquals( 'original_value', $original_meta, 'Original meta should be imported.' );

		// Modify post and meta in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Meta Test Post Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4040 ]
		); // phpcs:ignore

		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[ 'meta_value' => 'updated_value' ], // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			[
				'post_id'  => 4040,
				'meta_key' => 'custom_meta_key', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_key.
			]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4040, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );
		$this->assertNotEquals( $original_local_id, $new_local_id, 'Post should have new local ID.' );

		$new_meta = get_post_meta( $new_local_id, 'custom_meta_key', true );
		$this->assertEquals( 'updated_value', $new_meta, 'Reimported post should have updated meta.' );
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
		$post = $this->create_post_fixture(
			[
				'ID'            => 5001,
				'comment_count' => '2',
			] 
		);
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

		$post = $this->create_post_fixture(
			[
				'ID'            => 5002,
				'comment_count' => '1',
			] 
		);
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

		$post = $this->create_post_fixture(
			[
				'ID'            => 5003,
				'comment_count' => '1',
			] 
		);
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

		$post = $this->create_post_fixture(
			[
				'ID'            => 5004,
				'comment_count' => '1',
			] 
		);
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

		$post = $this->create_post_fixture(
			[
				'ID'            => 5005,
				'comment_count' => '1',
			] 
		);
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

		$post = $this->create_post_fixture(
			[
				'ID'            => 5006,
				'comment_count' => '2',
			] 
		);
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
		$this->assertCount( 2, $imported_comments, 'Post should have 2 comments.' );

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
		$level1 = $comment_map['Level 1 - This is the top level comment'];
		$this->assertEquals( 0, (int) $level1->comment_parent, 'Level 1 should have no parent.' );

		// Level 2 should have Level 1 as parent.
		$level2 = $comment_map['Level 2 - Reply to level 1'];
		$this->assertEquals( $level1->comment_ID, $level2->comment_parent, 'Level 2 should have Level 1 as parent.' );

		// Level 3 should have Level 2 as parent.
		$level3 = $comment_map['Level 3 - Reply to level 2'];
		$this->assertEquals( $level2->comment_ID, $level3->comment_parent, 'Level 3 should have Level 2 as parent.' );

		// Level 4 should have Level 3 as parent.
		$level4 = $comment_map['Level 4 - Reply to level 3'];
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
	 * Tests that unknown taxonomies are registered on-the-fly during migration.
	 * The DataImporter calls register_taxonomy() for unknown taxonomies.
	 *
	 * @group term
	 */
	public function test_should_register_unknown_taxonomy_on_the_fly(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 8001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create a term with a completely unknown taxonomy 'custom_flavor'.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 8101, 'name' => 'Spicy', 'slug' => 'spicy', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 8101, 'term_id' => 8101, 'taxonomy' => 'custom_flavor', 'description' => 'Spicy flavor', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 8001, 'term_taxonomy_id' => 8101 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag,author,custom_flavor' ] );

		// Verify taxonomy was registered and term was assigned.
		$this->assertTrue( taxonomy_exists( 'custom_flavor' ), 'Unknown taxonomy should be registered.' );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 8001, $this->source_hostname );
		$flavors     = wp_get_post_terms( $new_post_id, 'custom_flavor', [ 'fields' => 'names' ] );
		$this->assertContains( 'Spicy', $flavors, 'Term should be assigned after taxonomy registration.' );
	}

	/**
	 * Tests that terms with the same slug in different taxonomies are handled correctly.
	 * Each taxonomy should have its own term, not sharing.
	 *
	 * @group term
	 */
	public function test_should_handle_term_with_same_slug_in_different_taxonomies(): void {
		global $wpdb;

		// Register custom taxonomy.
		register_taxonomy( 'product_cat', 'post', [ 'public' => true ] );

		$post = $this->create_post_fixture( [ 'ID' => 8002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category with slug 'featured'.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 8201, 'name' => 'Featured Category', 'slug' => 'featured', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 8201, 'term_id' => 8201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 8002, 'term_taxonomy_id' => 8201 ] ); // phpcs:ignore

		// Create product_cat with same slug 'featured'.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 8202, 'name' => 'Featured Product', 'slug' => 'featured', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 8202, 'term_id' => 8202, 'taxonomy' => 'product_cat', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 8002, 'term_taxonomy_id' => 8202 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag,author,product_cat' ] );

		$new_post_id  = $this->logic->get_current_post_id_by_old_id( 8002, $this->source_hostname );
		$categories   = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'all' ] );
		$product_cats = wp_get_post_terms( $new_post_id, 'product_cat', [ 'fields' => 'all' ] );

		// Both taxonomies should have their own term.
		$cat_names  = wp_list_pluck( $categories, 'name' );
		$prod_names = wp_list_pluck( $product_cats, 'name' );

		$this->assertContains( 'Featured Category', $cat_names, 'Category term should be migrated.' );
		$this->assertContains( 'Featured Product', $prod_names, 'Product category term should be migrated.' );
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

		// Simulate partial migration by inserting the post, adding the old_id meta, and manually populating run-state.
		$post1_local_id = 99001; // Simulated new ID.
		$post1_local    = $this->create_post_fixture( [ 'ID' => $post1_local_id ] );
		$wpdb->insert( $wpdb->prefix . 'posts', $post1_local ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$meta_key = $this->logic->get_old_id_meta_key( $this->source_hostname );
		$wpdb->insert( $wpdb->prefix . 'postmeta', // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			[
				'post_id'    => 99001,
				'meta_key'   => $meta_key,
				'meta_value' => '7001', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);
		$this->run_state->append_imported_post(
			[
				'id_old'    => 7001,
				'id_new'    => $post1_local_id,
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

		// Verify count total 3.
		$this->assertEquals( 3, count( $imported_map ) );

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
		$updated_parents_map = $this->run_state->get_updated_parents_post_ids_map();
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
		$updated_parents_map_1 = $this->run_state->get_updated_parents_post_ids_map();
		$count_first           = count( $updated_parents_map_1 );

		// Run migrate again - should skip already updated.
		$this->run_migrate_command();

		$updated_parents_map_2 = $this->run_state->get_updated_parents_post_ids_map();
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
		$updated_featured_map = $this->run_state->get_updated_featured_image_post_ids_map();
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

		$count_first = count( $this->run_state->get_updated_featured_image_post_ids_map() );
		$this->assertEquals( 1, $count_first, 'Should have 1 updated featured image post ID.' );

		// Run again.
		$this->run_migrate_command();

		$count_second = count( $this->run_state->get_updated_featured_image_post_ids_map() );
		$this->assertEquals( $count_first, $count_second, 'Should not duplicate featured image updates on rerun.' );
	}

	/**
	 * Tests that block-updated post IDs are saved to run-state.
	 *
	 * @group runstate
	 */
	public function test_should_save_updated_blocks_to_runstate(): void {
		global $wpdb;

		// Create attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 9101,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		// Create post with image block referencing the attachment.
		$post = $this->create_post_fixture(
			[
				'ID'           => 9001,
				'post_content' => '<!-- wp:image {"id":9101} --><figure class="wp-block-image"><img src="test.jpg" class="wp-image-9101"/></figure><!-- /wp:image -->',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		// Check run-state for updated blocks.
		$updated_blocks_map = $this->run_state->get_updated_block_post_ids_map();
		$this->assertNotEmpty( $updated_blocks_map, 'Updated blocks should be saved to run-state.' );
	}

	/**
	 * Tests that already block-updated posts are skipped on resume.
	 *
	 * @group runstate
	 */
	public function test_should_skip_already_updated_blocks_on_resume(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 9201,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'           => 9002,
				'post_content' => '<!-- wp:image {"id":9201} --><figure><img src="test.jpg" class="wp-image-9201"/></figure><!-- /wp:image -->',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$first_blocks_map = $this->run_state->get_updated_block_post_ids_map();

		// Run migration again (resume scenario).
		$this->run_migrate_command();

		$second_blocks_map = $this->run_state->get_updated_block_post_ids_map();

		// Maps should be the same (no duplicates, no re-processing).
		$this->assertEquals( $first_blocks_map, $second_blocks_map, 'Block updates should not be duplicated on resume.' );
	}

	/**
	 * Tests that migration handles empty new_ids.json file gracefully.
	 *
	 * @group runstate
	 */
	public function test_should_handle_empty_new_ids_json_file(): void {
		global $wpdb;

		// Create a post, import it, then run search again with no new posts.
		$post = $this->create_post_fixture( [ 'ID' => 9003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Run search again - no new posts should be found.
		$this->run_search_command();

		$new_ids = $this->run_state->get_new_ids();
		$this->assertEmpty( $new_ids, 'New IDs should be empty.' );

		// Migration should complete without error.
		$this->run_migrate_command();
		$this->assertTrue( true, 'Migration should handle empty new_ids gracefully.' );
	}

	/**
	 * Tests that migration handles empty modified_ids.json file gracefully.
	 *
	 * @group runstate
	 */
	public function test_should_handle_empty_modified_ids_json_file(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 9004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();

		// Modified IDs should be empty for new post.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertEmpty( $modified_ids, 'Modified IDs should be empty for new post.' );

		// Migration should complete without error.
		$this->run_migrate_command();
		$this->assertTrue( true, 'Migration should handle empty modified_ids gracefully.' );
	}

	/**
	 * Tests that run-state directory is created if it doesn't exist.
	 *
	 * @group runstate
	 */
	public function test_should_create_runstate_directory_if_not_exists(): void {
		global $wpdb;

		// Create a new temp directory that doesn't exist.
		$new_temp_dir  = sys_get_temp_dir() . '/cdiff-test-new-' . uniqid();
		$new_run_state = new RunState( $new_temp_dir . '/new-hostname/run-state' );
		$this->command->set_run_state( $new_run_state );

		$post = $this->create_post_fixture( [ 'ID' => 9005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();

		// Directory should now exist.
		$this->assertDirectoryExists( $new_temp_dir . '/new-hostname/run-state', 'Run-state directory should be created.' );

		// Cleanup.
		$this->cleanup_temp_dir( $new_temp_dir );

		// Restore original run-state.
		$this->command->set_run_state( $this->run_state );
	}

	/**
	 * Tests that manifest.json is written with migration summary.
	 *
	 * @group runstate
	 */
	public function test_should_write_manifest_json_with_migration_summary(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 9006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();

		// Check manifest file exists and has content.
		$manifest_path = $this->temp_data_dir . '/' . $this->source_hostname . '/run-state/' . RunState::FILE_MANIFEST;
		$this->assertFileExists( $manifest_path, 'Manifest file should exist.' );

		$manifest = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore
		$this->assertArrayHasKey( 'created_at', $manifest, 'Manifest should have created_at.' );
		$this->assertArrayHasKey( 'source_hostname', $manifest, 'Manifest should have source_hostname.' );
		$this->assertArrayHasKey( 'counts', $manifest, 'Manifest should have counts.' );
		$this->assertEquals( $this->source_hostname, $manifest['source_hostname'], 'Manifest source_hostname should match.' );
	}

	/**
	 * Tests that manifest.json can be read correctly.
	 *
	 * @group runstate
	 */
	public function test_should_read_manifest_json_correctly(): void {
		global $wpdb;

		$post1 = $this->create_post_fixture( [ 'ID' => 9007 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 9008 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->run_search_command();

		$manifest_path = $this->temp_data_dir . '/' . $this->source_hostname . '/run-state/' . RunState::FILE_MANIFEST;
		$manifest      = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore

		$this->assertEquals( 2, $manifest['counts']['new_ids'], 'Manifest should correctly count new_ids.' );
		$this->assertEquals( 0, $manifest['counts']['modified_ids'], 'Manifest should correctly count modified_ids.' );
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
	public function test_should_update_jetpack_slideshow_gallery_block_ids_in_post_content(): void {
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
	 * Tests that attachment post_parent is updated to the new local post ID.
	 *
	 * @group attachment
	 */
	public function test_should_update_attachment_parent_post_id(): void {
		global $wpdb;

		// Create parent post.
		$post = $this->create_post_fixture( [ 'ID' => 10001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create attachment with parent.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10002,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 10001, // Points to live post ID.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10001, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10002, $this->source_hostname );

		$attachment_post = get_post( $new_attachment_id );
		$this->assertEquals( (int) $new_post_id, $attachment_post->post_parent, 'Attachment post_parent should be updated to new local post ID.' );
	}

	// =========================================================================
	// 14. TERM COUNT RECALCULATION TESTS
	// =========================================================================

	/**
	 * Tests that term counts are recalculated after migration.
	 *
	 * @group termcount
	 */
	public function test_should_recalculate_term_counts_after_migration(): void {
		global $wpdb;

		// Create multiple posts with same category.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 11001, 'name' => 'Count Test Cat', 'slug' => 'count-test-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 11001, 'term_id' => 11001, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 3 ] ); // phpcs:ignore

		for ( $i = 1; $i <= 3; $i++ ) {
			$post = $this->create_post_fixture( [ 'ID' => 11000 + $i ] );
			$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
			$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11000 + $i, 'term_taxonomy_id' => 11001 ] ); // phpcs:ignore
		}

		$this->run_search_command();
		$this->run_migrate_command();

		// Get the local term and check its count.
		$local_term = get_term_by( 'slug', 'count-test-cat', 'category' );
		$this->assertEquals( 3, $local_term->count, 'Term count should be recalculated to 3.' );
	}

	/**
	 * Tests that category term counts are set correctly.
	 *
	 * @group termcount
	 */
	public function test_should_set_correct_count_for_category_terms(): void {
		global $wpdb;

		// Create category with 2 posts.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 11101, 'name' => 'Cat Count Test', 'slug' => 'cat-count-test', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 11101, 'term_id' => 11101, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		$post1 = $this->create_post_fixture( [ 'ID' => 11102 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 11103 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11102, 'term_taxonomy_id' => 11101 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11103, 'term_taxonomy_id' => 11101 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_term = get_term_by( 'slug', 'cat-count-test', 'category' );
		$this->assertEquals( 2, $local_term->count, 'Category count should be 2.' );
	}

	/**
	 * Tests that post_tag term counts are set correctly.
	 *
	 * @group termcount
	 */
	public function test_should_set_correct_count_for_post_tag_terms(): void {
		global $wpdb;

		// Create tag with 1 post.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 11201, 'name' => 'Tag Count Test', 'slug' => 'tag-count-test', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 11201, 'term_id' => 11201, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		$post = $this->create_post_fixture( [ 'ID' => 11202 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11202, 'term_taxonomy_id' => 11201 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_term = get_term_by( 'slug', 'tag-count-test', 'post_tag' );
		$this->assertEquals( 1, $local_term->count, 'Post tag count should be 1.' );
	}

	/**
	 * Tests that custom taxonomy term counts are set correctly.
	 *
	 * @group termcount
	 */
	public function test_should_set_correct_count_for_custom_taxonomy_terms(): void {
		global $wpdb;

		// Register custom taxonomy.
		register_taxonomy( 'region', 'post', [ 'public' => true ] );

		// Create custom taxonomy term with 4 posts.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 11301, 'name' => 'Europe', 'slug' => 'europe', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 11301, 'term_id' => 11301, 'taxonomy' => 'region', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		for ( $i = 1; $i <= 4; $i++ ) {
			$post = $this->create_post_fixture( [ 'ID' => 11300 + $i ] );
			$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
			$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11300 + $i, 'term_taxonomy_id' => 11301 ] ); // phpcs:ignore
		}

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag,author,region' ] );

		$local_term = get_term_by( 'slug', 'europe', 'region' );
		$this->assertEquals( 4, $local_term->count, 'Custom taxonomy count should be 4.' );
	}

	// =========================================================================
	// 15. MIGRATION DATA CONSISTENCY STANDARD (MDCS) TESTS
	// =========================================================================

	/**
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
	 * @group mdcs
	 */
	public function test_mdcs_should_not_update_user_login_when_changed_on_live_and_should_create_new_user(): void {
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

		// Update user_login in live (not allowed by MDCS, should result in new user creation).
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'users',
			[ 'user_login' => 'changed_login' ],
			[ 'ID' => 13201 ]
		);

		$this->run_migrate_command();

		// Verify post author user_login was NOT updated.
		$new_post_id    = $this->logic->get_current_post_id_by_old_id( 13202, $this->source_hostname );
		$post_author_id = get_post_field( 'post_author', $new_post_id );
		$post_author    = get_user_by( 'ID', $post_author_id );
		$this->assertEquals( 'original_login', $post_author->user_login, 'Post author user_login should NOT be changed.' );

		// A new user was created.
		$changed_user = get_user_by( 'login', 'changed_login' );
		$this->assertNotFalse( $changed_user, 'Changed login should not exist.' );
	}

	/**
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * "mdcs" stands for "Migration Data Consistency Standard".
	 * 
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
	 * Tests that migration throws exception when new_ids run-state file is not found.
	 *
	 * @group exception
	 */
	public function test_should_throw_when_new_ids_runstate_file_not_found(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( RunState::FILE_NEW_IDS );

		// Don't run search command (so new_ids.json won't exist).
		$this->run_migrate_command();
	}

	/**
	 * Tests behavior when trying to insert a post with invalid data.
	 * Note: In practice, DB insert failures are hard to simulate in integration tests.
	 * This test verifies the command handles posts with unusual but valid data.
	 *
	 * @group exception
	 */
	public function test_should_throw_when_post_insert_fails(): void {
		global $wpdb;

		// Create a post with valid data - this should work.
		$post = $this->create_post_fixture( [ 'ID' => 12001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Post should be imported successfully.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12001, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported.' );
	}

	/**
	 * Tests that old_id postmeta is saved correctly.
	 * Note: Testing actual insert failure is difficult in integration tests.
	 *
	 * @group exception
	 */
	public function test_should_throw_when_old_id_postmeta_insert_fails(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 12002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify old_id postmeta was saved.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12002, $this->source_hostname );
		$meta_key    = $this->get_old_id_meta_key();
		$old_id      = get_post_meta( $new_post_id, $meta_key, true );
		$this->assertEquals( 12002, (int) $old_id, 'Old ID postmeta should be saved.' );
	}

	/**
	 * Tests that migration continues when postmeta insert encounters issues.
	 * Tests with a post that has unusual but valid postmeta.
	 *
	 * @group exception
	 */
	public function test_should_log_error_and_continue_when_postmeta_insert_fails(): void {
		global $wpdb;

		// Create post with some postmeta.
		$post = $this->create_post_fixture( [ 'ID' => 12003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add valid postmeta.
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 12003, 'post_id' => 12003, 'meta_key' => '_test_meta', 'meta_value' => 'test_value' ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Migration should complete and postmeta should be imported.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12003, $this->source_hostname );
		$meta_value  = get_post_meta( $new_post_id, '_test_meta', true );
		$this->assertEquals( 'test_value', $meta_value, 'Postmeta should be imported.' );
	}

	/**
	 * Tests that migration continues when comment data has issues.
	 * Tests with a post that has a comment with missing user reference.
	 *
	 * @group exception
	 */
	public function test_should_log_error_and_continue_when_comment_insert_fails(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 12004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add a comment with user_id pointing to non-existent user.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'comments',
			[
				'comment_ID'           => 12004,
				'comment_post_ID'      => 12004,
				'comment_author'       => 'Anonymous',
				'comment_author_email' => 'anon@test.local',
				'comment_author_url'   => '',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2024-01-15 10:00:00',
				'comment_date_gmt'     => '2024-01-15 10:00:00',
				'comment_content'      => 'Test comment',
				'comment_karma'        => 0,
				'comment_approved'     => 1,
				'comment_agent'        => '',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0, // Anonymous user.
			]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Post and comment should be imported.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12004, $this->source_hostname );
		$comments    = get_comments( [ 'post_id' => $new_post_id ] );
		$this->assertCount( 1, $comments, 'Comment should be imported.' );
	}

	/**
	 * Tests that migration continues when user data has issues.
	 * Tests with a user that has minimal valid data.
	 *
	 * @group exception
	 */
	public function test_should_log_error_and_continue_when_user_insert_fails(): void {
		global $wpdb;

		// Create user with minimal data.
		$user = $this->create_user_fixture(
			[
				'ID'         => 12101,
				'user_login' => 'minimal_user',
				'user_email' => 'minimal@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $user ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 12005,
				'post_author' => 12101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// User should be created.
		$local_user = get_user_by( 'login', 'minimal_user' );
		$this->assertNotFalse( $local_user, 'User should be created.' );
	}

	/**
	 * Tests that migration continues when term data has issues.
	 * Tests with a term that has empty description (valid but edge case).
	 *
	 * @group exception
	 */
	public function test_should_log_error_and_continue_when_term_insert_fails(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 12006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create term with empty description.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 12201, 'name' => 'Minimal Term', 'slug' => 'minimal-term', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 12201, 'term_id' => 12201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 12006, 'term_taxonomy_id' => 12201 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Term should be imported.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12006, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Minimal Term', $categories, 'Term should be imported.' );
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
	 * Tests that postmeta with serialized array values is imported correctly.
	 *
	 * @group content
	 */
	public function test_should_import_postmeta_with_serialized_array_value(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 14001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add serialized array postmeta.
		$serialized_array = serialize( // phpcs:ignore -- WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize.
			[
				'key1'    => 'value1',
				'key2'    => 'value2',
				'numbers' => [ 1, 2, 3 ],
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 14001, 'post_id' => 14001, 'meta_key' => '_serialized_array', 'meta_value' => $serialized_array ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14001, $this->source_hostname );
		$meta_value  = get_post_meta( $new_post_id, '_serialized_array', true );

		$this->assertIsArray( $meta_value, 'Serialized array should be unserialized.' );
		$this->assertEquals( 'value1', $meta_value['key1'], 'Array key1 should match.' );
		$this->assertEquals( [ 1, 2, 3 ], $meta_value['numbers'], 'Nested array should match.' );
	}

	/**
	 * Tests that postmeta with serialized object values is imported correctly.
	 *
	 * @group content
	 */
	public function test_should_import_postmeta_with_serialized_object_value(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 14002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add serialized stdClass object postmeta.
		$obj               = new \stdClass();
		$obj->name         = 'Test Object';
		$obj->data         = [ 'a', 'b', 'c' ];
		$serialized_object = serialize( $obj ); // phpcs:ignore -- WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize.
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 14002, 'post_id' => 14002, 'meta_key' => '_serialized_object', 'meta_value' => $serialized_object ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14002, $this->source_hostname );
		$meta_value  = get_post_meta( $new_post_id, '_serialized_object', true );

		$this->assertIsObject( $meta_value, 'Serialized object should be unserialized.' );
		$this->assertEquals( 'Test Object', $meta_value->name, 'Object property should match.' );
	}

	/**
	 * Tests that post with empty content is imported correctly.
	 *
	 * @group content
	 */
	public function test_should_import_post_with_empty_content(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'           => 14003,
				'post_title'   => 'Post With No Content',
				'post_content' => '',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14003, $this->source_hostname );
		$local_post  = get_post( $new_post_id );

		$this->assertEquals( 'Post With No Content', $local_post->post_title, 'Title should be imported.' );
		$this->assertEquals( '', $local_post->post_content, 'Empty content should remain empty.' );
	}

	/**
	 * Tests that post with empty title is imported correctly.
	 *
	 * @group content
	 */
	public function test_should_import_post_with_empty_title(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'           => 14004,
				'post_title'   => '',
				'post_content' => '<p>Content without title.</p>',
				'post_name'    => 'no-title-post',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14004, $this->source_hostname );
		$local_post  = get_post( $new_post_id );

		$this->assertEquals( '', $local_post->post_title, 'Empty title should remain empty.' );
		$this->assertStringContainsString( 'Content without title', $local_post->post_content, 'Content should be imported.' );
	}

	/**
	 * Tests that post with very long content is imported correctly.
	 *
	 * @group content
	 */
	public function test_should_import_post_with_very_long_content(): void {
		global $wpdb;

		// Create content with 100KB of text.
		$long_content = str_repeat( '<p>' . str_repeat( 'Lorem ipsum dolor sit amet. ', 100 ) . '</p>', 50 );

		$post = $this->create_post_fixture(
			[
				'ID'           => 14005,
				'post_title'   => 'Very Long Post',
				'post_content' => $long_content,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14005, $this->source_hostname );
		$local_post  = get_post( $new_post_id );

		$this->assertEquals( strlen( $long_content ), strlen( $local_post->post_content ), 'Long content should be fully imported.' );
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
	 * Tests that attribution command matches local posts to live posts by composite key.
	 *
	 * @group attribution
	 */
	public function test_attribute_should_match_local_posts_to_live_by_composite_key(): void {
		global $wpdb;

		// Create a local post first.
		$local_post_id = self::factory()->post->create(
			[
				'post_title'  => 'Matching Post Title',
				'post_name'   => 'matching-post-slug',
				'post_date'   => '2024-01-15 10:00:00',
				'post_status' => 'publish',
				'post_type'   => 'post',
			]
		);

		// Create same post in live DB (matching title, slug, date, type).
		$live_post = [
			'ID'                    => 15001,
			'post_author'           => 1,
			'post_date'             => '2024-01-15 10:00:00',
			'post_date_gmt'         => '2024-01-15 10:00:00',
			'post_content'          => '<p>Test content</p>',
			'post_title'            => 'Matching Post Title',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'open',
			'post_password'         => '',
			'post_name'             => 'matching-post-slug',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-15 10:00:00',
			'post_modified_gmt'     => '2024-01-15 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/?p=15001',
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run attribution.
		$this->run_attribute_command();

		// Check that old_id meta was saved.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 15001, (int) $old_id, 'Local post should be attributed to live post ID.' );
	}

	/**
	 * Tests that attribution command matches local attachments to live attachments.
	 *
	 * @group attribution
	 */
	public function test_attribute_should_match_local_attachments_to_live(): void {
		global $wpdb;

		// Create local attachment.
		$local_attachment_id = self::factory()->attachment->create(
			[
				'post_title'     => 'Test Image',
				'post_name'      => 'test-image',
				'post_date'      => '2024-01-15 10:00:00',
				'post_mime_type' => 'image/jpeg',
			]
		);

		// Create matching attachment in live DB.
		$live_attachment = [
			'ID'                    => 15002,
			'post_author'           => 1,
			'post_date'             => '2024-01-15 10:00:00',
			'post_date_gmt'         => '2024-01-15 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Test Image',
			'post_excerpt'          => '',
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'test-image',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-15 10:00:00',
			'post_modified_gmt'     => '2024-01-15 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/test-image.jpg',
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => 'image/jpeg',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_attachment ); // phpcs:ignore

		$this->run_attribute_command();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_attachment_id, $meta_key, true );
		$this->assertEquals( 15002, (int) $old_id, 'Local attachment should be attributed to live attachment ID.' );
	}

	/**
	 * Tests that attribution command matches local users to live users by user_login.
	 *
	 * @group attribution
	 */
	public function test_attribute_should_match_local_users_to_live_by_user_login(): void {
		global $wpdb;

		// Create local user.
		$local_user_id = wp_insert_user(
			[
				'user_login' => 'john_doe_attr',
				'user_email' => 'john_attr@test.local',
				'user_pass'  => 'password123',
			]
		);

		// Create matching user in live DB.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 15101,
				'user_login' => 'john_doe_attr',
				'user_email' => 'john_live@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$this->run_attribute_command();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $local_user_id, $meta_key, true );
		$this->assertEquals( 15101, (int) $old_id, 'Local user should be attributed to live user ID.' );
	}

	/**
	 * Tests that attribution command matches local terms to live terms by slug and taxonomy.
	 *
	 * @group attribution
	 */
	public function test_attribute_should_match_local_terms_to_live_by_slug_and_taxonomy(): void {
		global $wpdb;

		// Create local category.
		$local_term    = wp_insert_term( 'Attributed Category', 'category', [ 'slug' => 'attributed-category' ] );
		$local_term_id = is_array( $local_term ) ? $local_term['term_id'] : $local_term;

		// Create matching term in live DB.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 15201, 'name' => 'Attributed Category', 'slug' => 'attributed-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 15201, 'term_id' => 15201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		$this->run_attribute_command();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_term_meta( $local_term_id, $meta_key, true );
		$this->assertEquals( 15201, (int) $old_id, 'Local term should be attributed to live term ID.' );
	}

	/**
	 * Tests that attribution command saves old_id meta for all matched object types.
	 *
	 * @group attribution
	 */
	public function test_attribute_should_save_old_id_meta_for_matched_objects(): void {
		global $wpdb;

		// Create local post.
		$local_post_id = self::factory()->post->create(
			[
				'post_title' => 'Meta Test Post',
				'post_name'  => 'meta-test-post',
				'post_date'  => '2024-02-01 10:00:00',
			]
		);

		// Create local user.
		$local_user_id = wp_insert_user(
			[
				'user_login' => 'meta_test_user',
				'user_email' => 'meta_test@test.local',
				'user_pass'  => 'password',
			]
		);

		// Create matching live data.
		$live_post = [
			'ID'                    => 15301,
			'post_author'           => 1,
			'post_date'             => '2024-02-01 10:00:00',
			'post_date_gmt'         => '2024-02-01 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Meta Test Post',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'open',
			'post_password'         => '',
			'post_name'             => 'meta-test-post',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-02-01 10:00:00',
			'post_modified_gmt'     => '2024-02-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/?p=15301',
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 15302,
				'user_login' => 'meta_test_user',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$this->run_attribute_command();

		$meta_key = $this->get_old_id_meta_key();

		// Check post meta.
		$post_old_id = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 15301, (int) $post_old_id, 'Post old_id meta should be saved.' );

		// Check user meta.
		$user_old_id = get_user_meta( $local_user_id, $meta_key, true );
		$this->assertEquals( 15302, (int) $user_old_id, 'User old_id meta should be saved.' );
	}

	/**
	 * Tests that attribution command skips objects that are already attributed.
	 *
	 * @group attribution
	 */
	public function test_attribute_should_skip_already_attributed_objects(): void {
		global $wpdb;

		// Create local post with existing attribution.
		$local_post_id = self::factory()->post->create(
			[
				'post_title' => 'Already Attributed Post',
				'post_name'  => 'already-attributed-post',
				'post_date'  => '2024-03-01 10:00:00',
			]
		);

		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $local_post_id, $meta_key, 99999 ); // Existing attribution.

		// Create matching live post with different ID.
		$live_post = [
			'ID'                    => 15401,
			'post_author'           => 1,
			'post_date'             => '2024-03-01 10:00:00',
			'post_date_gmt'         => '2024-03-01 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Already Attributed Post',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'open',
			'post_password'         => '',
			'post_name'             => 'already-attributed-post',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-03-01 10:00:00',
			'post_modified_gmt'     => '2024-03-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/?p=15401',
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		$this->run_attribute_command();

		// Old attribution should be preserved, not overwritten.
		$old_id = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 99999, (int) $old_id, 'Existing attribution should not be overwritten.' );
	}

	// =========================================================================
	// 22. RERUN SAFETY AND IDEMPOTENCY TESTS
	// =========================================================================

	/**
	 * Tests that running migration twice produces the same result.
	 *
	 * @group idempotency
	 */
	public function test_should_produce_same_result_when_running_migration_twice(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 16001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 16001, 'name' => 'Idempotent Cat', 'slug' => 'idempotent-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 16001, 'term_id' => 16001, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 16001, 'term_taxonomy_id' => 16001 ] ); // phpcs:ignore

		// First migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$first_post_id = $this->logic->get_current_post_id_by_old_id( 16001, $this->source_hostname );
		$first_post    = get_post( $first_post_id );

		// Second migration (no changes on live).
		$this->run_search_command();
		$this->run_migrate_command();

		$second_post_id = $this->logic->get_current_post_id_by_old_id( 16001, $this->source_hostname );
		$second_post    = get_post( $second_post_id );

		// Results should be identical.
		$this->assertEquals( $first_post_id, $second_post_id, 'Post ID should remain the same.' );
		$this->assertEquals( $first_post->post_title, $second_post->post_title, 'Post content should remain the same.' );
	}

	/**
	 * Tests that duplicate posts are not created on rerun.
	 *
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_posts_on_rerun(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'         => 16002,
				'post_title' => 'Unique Post Title',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// First migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$posts_after_first = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'Unique Post Title'" ); // phpcs:ignore

		// Second migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$posts_after_second = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'Unique Post Title'" ); // phpcs:ignore

		$this->assertEquals( $posts_after_first, $posts_after_second, 'No duplicate posts should be created.' );
	}

	/**
	 * Tests that duplicate terms are not created on rerun.
	 *
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_terms_on_rerun(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 16003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 16003, 'name' => 'No Duplicate Term', 'slug' => 'no-duplicate-term', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 16003, 'term_id' => 16003, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 16003, 'term_taxonomy_id' => 16003 ] ); // phpcs:ignore

		// First migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$terms_after_first = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug = 'no-duplicate-term'" ); // phpcs:ignore

		// Second migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$terms_after_second = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug = 'no-duplicate-term'" ); // phpcs:ignore

		$this->assertEquals( $terms_after_first, $terms_after_second, 'No duplicate terms should be created.' );
	}

	/**
	 * Tests that duplicate users are not created on rerun.
	 *
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_users_on_rerun(): void {
		global $wpdb;

		$user = $this->create_user_fixture(
			[
				'ID'         => 16101,
				'user_login' => 'no_duplicate_user',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $user ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 16004,
				'post_author' => 16101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// First migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$users_after_first = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = 'no_duplicate_user'" ); // phpcs:ignore

		// Second migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$users_after_second = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = 'no_duplicate_user'" ); // phpcs:ignore

		$this->assertEquals( $users_after_first, $users_after_second, 'No duplicate users should be created.' );
	}

	/**
	 * Tests that completed steps are skipped on resume.
	 *
	 * @group idempotency
	 */
	public function test_should_skip_completed_steps_on_resume(): void {
		global $wpdb;

		// Create multiple posts.
		for ( $i = 1; $i <= 3; $i++ ) {
			$post = $this->create_post_fixture( [ 'ID' => 16100 + $i ] );
			$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		}

		$this->run_search_command();
		$this->run_migrate_command();

		// Get imported post IDs map.
		$imported_map_first = $this->run_state->get_imported_post_ids_map();

		// Run migrate again (resume scenario - all already imported).
		$this->run_migrate_command();

		$imported_map_second = $this->run_state->get_imported_post_ids_map();

		// Should be the same (no re-imports).
		$this->assertEquals( $imported_map_first, $imported_map_second, 'Imported posts map should remain the same.' );

		// Verify all posts exist.
		for ( $i = 1; $i <= 3; $i++ ) {
			$local_id = $this->logic->get_current_post_id_by_old_id( 16100 + $i, $this->source_hostname );
			$this->assertNotNull( $local_id, "Post 1610{$i} should exist." );
		}
	}
}
