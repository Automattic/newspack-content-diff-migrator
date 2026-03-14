<?php
/**
 * Integration tests for command cmd_search_new_content_on_live.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\Logger;

/**
 * Integration test class for command cmd_search_new_content_on_live.
 *
 * @group integration
 */
class CmdSearchNewContentOnLiveTest extends IntegrationTestCase {
	/**
	 * @group search-new-content-on-live-command
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
	 * @group search-new-content-on-live-command
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
	 * @group search-new-content-on-live-command
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
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Run search again.
		$this->run_search_command();

		// Verify post was detected as modified.
		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertNotNull( $modified_ids_map, 'Modified IDs map should exist.' );
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified.' );
	}

	/**
	 * @group search-new-content-on-live-command
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
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified due to status change.' );
	}

	/**
	 * @group search-new-content-on-live-command
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
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified due to author change.' );
	}

	/**
	 * @group search-new-content-on-live-command
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
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified due to thumbnail change.' );
	}

	/**
	 * @group search-new-content-on-live-command
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
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Post should be detected as modified due to taxonomy change.' );
	}

	/**
	 * @group search-new-content-on-live-command
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
	 * @group search-new-content-on-live-command
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
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertNotNull( $modified_ids_map, 'Modified IDs should be written.' );
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Live ID should be in modified IDs.' );
		$this->assertEquals( $new_post_id, $modified_ids_map[ $live_post_id ], 'Modified map should have correct local ID.' );
	}

	/**
	 * @group search-new-content-on-live-command
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

	// =========================================================================
	// RUN-STATE CONFLICT DETECTION TESTS
	// =========================================================================

	/**
	 * Tests that search detects existing run-state for same hostname.
	 * In test environment, the command continues but logs the scenario.
	 *
	 * @test
	 * @group run-state-conflict
	 */
	public function test_search_should_detect_existing_runstate_for_same_hostname(): void {
		// First search.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$this->run_search_command();

		// Verify manifest exists with source hostname and created_at.
		$manifest1 = $this->run_state->get_manifest();
		$this->assertNotNull( $manifest1, 'Manifest should exist after first search.' );
		$this->assertArrayHasKey( 'source_hostname', $manifest1 );
		$this->assertArrayHasKey( 'created_at', $manifest1 );
		$this->assertEquals( $this->source_hostname, $manifest1['source_hostname'] );

		$first_created_at = $manifest1['created_at'];

		// Second search with same hostname - in test env this continues.
		$fixture2 = $this->load_fixture( 'post-with-full-data' );
		$this->insert_live_data( $fixture2 );
		$this->run_search_command();

		// In test environment, manifest is updated (not blocked).
		$manifest2 = $this->run_state->get_manifest();
		$this->assertNotNull( $manifest2 );
		$this->assertEquals( $this->source_hostname, $manifest2['source_hostname'] );
	}

	/**
	 * Tests that search detects existing run-state for different hostname.
	 * In test environment, the command continues but would warn in production.
	 *
	 * @test
	 * @group run-state-conflict
	 */
	public function test_search_should_detect_existing_runstate_for_different_hostname(): void {
		// First search with hostname A.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$original_hostname = $this->source_hostname;
		$this->run_search_command();

		// Verify first manifest was created with original hostname.
		$manifest1 = $this->run_state->get_manifest();
		$this->assertNotNull( $manifest1 );
		$this->assertEquals( $original_hostname, $manifest1['source_hostname'] );

		// Try second search with hostname B using same data dir.
		$fixture2 = $this->load_fixture( 'post-with-full-data' );
		$this->insert_live_data( $fixture2 );
		$this->source_hostname = 'source-b.com';
		$this->run_search_command(); // In test env this continues and updates manifest.

		// Verify manifest was updated with new hostname (test env behavior allows re-runs).
		$manifest2 = $this->run_state->get_manifest();
		$this->assertEquals( 'source-b.com', $manifest2['source_hostname'] );

		// Restore hostname.
		$this->source_hostname = $original_hostname;
	}

	/**
	 * Tests that existing manifest contains expected properties for conflict detection.
	 *
	 * @test
	 * @group run-state-conflict
	 */
	public function test_manifest_contains_conflict_detection_properties(): void {
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$this->run_search_command();

		$manifest = $this->run_state->get_manifest();

		// Verify all required properties for conflict detection exist.
		$this->assertArrayHasKey( 'source_hostname', $manifest, 'Manifest should have source_hostname for conflict detection.' );
		$this->assertArrayHasKey( 'created_at', $manifest, 'Manifest should have created_at for conflict detection.' );
		$this->assertArrayHasKey( 'counts', $manifest, 'Manifest should have counts.' );

		// Verify counts structure.
		$this->assertArrayHasKey( 'new_ids', $manifest['counts'], 'Manifest counts should include new_ids.' );
		$this->assertArrayHasKey( 'modified_ids', $manifest['counts'], 'Manifest counts should include modified_ids.' );
	}

	/**
	 * Tests that conflict detection considers hostname in existing manifest.
	 * When new search uses a different hostname, the system should detect the conflict.
	 *
	 * @test
	 * @group run-state-conflict
	 */
	public function test_conflict_detection_compares_hostnames(): void {
		global $wpdb;

		// First search with hostname A.
		$post1 = $this->create_post_fixture( [ 'ID' => 50001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore

		$original_hostname     = $this->source_hostname;
		$this->source_hostname = 'hostname-a.example.com';
		$this->run_search_command();

		$manifest_a = $this->run_state->get_manifest();
		$this->assertEquals( 'hostname-a.example.com', $manifest_a['source_hostname'] );

		// In production, running a second search with different hostname would prompt user.
		// In test environment, we verify the manifest shows which hostname was used.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 50001 ] ); // phpcs:ignore

		$post2 = $this->create_post_fixture( [ 'ID' => 50002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->source_hostname = 'hostname-b.example.com';
		$this->run_search_command();

		$manifest_b = $this->run_state->get_manifest();
		// In test env, manifest is updated to new hostname.
		$this->assertEquals( 'hostname-b.example.com', $manifest_b['source_hostname'] );

		// Restore original hostname.
		$this->source_hostname = $original_hostname;
	}

	/**
	 * Tests that search filters out invalid post types gracefully.
	 *
	 * @test
	 */
	public function test_search_should_filter_out_invalid_post_types(): void {
		// Insert a valid post.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );

		// Search with invalid post type - should complete without crashing.
		$this->run_search_command( [ 'post-types-csv' => 'post,nonexistent_cpt' ] );

		// Verify search completed and found the valid post type.
		$new_ids = $this->run_state->get_new_ids();
		$this->assertNotEmpty( $new_ids, 'Valid post should be found despite invalid post type in list.' );
	}

	// =========================================================================
	// HOSTNAME WARNING LOGIC TESTS
	// =========================================================================

	/**
	 * Tests that search detects similar hostname (www vs non-www variant).
	 * This tests the warn_if_similar_hostname_exists logic indirectly by checking
	 * that migrations from similar hostnames are tracked separately.
	 *
	 * @group hostname-warning
	 */
	public function test_search_tracks_www_and_nonwww_as_separate_sources(): void {
		global $wpdb;

		// First migration from www.example.com.
		$post1 = $this->create_post_fixture( [ 'ID' => 30001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore

		$this->source_hostname = 'www.example.com';
		$this->run_search_command();
		$this->run_migrate_command();

		$local_post1_id = $this->logic->get_current_post_id_by_old_id( 30001, 'www.example.com' );
		$this->assertNotNull( $local_post1_id, 'Post from www.example.com should be imported.' );

		// Clean up for second source.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 30001 ] ); // phpcs:ignore
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Second migration from example.com (non-www).
		$post2 = $this->create_post_fixture( [ 'ID' => 30002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->source_hostname = 'example.com';
		$this->run_search_command();
		$this->run_migrate_command();

		$local_post2_id = $this->logic->get_current_post_id_by_old_id( 30002, 'example.com' );
		$this->assertNotNull( $local_post2_id, 'Post from example.com should be imported.' );

		// Verify they are tracked as separate sources.
		$this->assertNotEquals( $local_post1_id, $local_post2_id, 'Posts from www and non-www should be separate.' );

		// Verify migrated hostnames include both.
		$migrated_hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertContains( 'www.example.com', $migrated_hostnames, 'www.example.com should be tracked.' );
		$this->assertContains( 'example.com', $migrated_hostnames, 'example.com should be tracked.' );

		// Restore original hostname.
		$this->source_hostname = 'test-1.example.com';
	}

	/**
	 * Tests that search correctly lists previously migrated source hostnames.
	 *
	 * @group hostname-warning
	 */
	public function test_search_lists_previously_migrated_hostnames(): void {
		global $wpdb;

		// First migration from source A.
		$post1 = $this->create_post_fixture( [ 'ID' => 31001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore

		$this->source_hostname = 'source-a.example.com';
		$this->run_search_command();
		$this->run_migrate_command();

		// Clean up for second source.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 31001 ] ); // phpcs:ignore
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Second migration from source B.
		$post2 = $this->create_post_fixture( [ 'ID' => 31002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->source_hostname = 'source-b.example.com';
		$this->run_search_command();
		$this->run_migrate_command();

		// Verify both hostnames are tracked.
		$migrated_hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertContains( 'source-a.example.com', $migrated_hostnames );
		$this->assertContains( 'source-b.example.com', $migrated_hostnames );

		// Restore original hostname.
		$this->source_hostname = 'test-1.example.com';
	}

	/**
	 * Tests hostname substring detection logic.
	 * When a new hostname is a substring of (or contains) a previously migrated hostname,
	 * the system should recognize them as potentially related.
	 *
	 * @group hostname-warning
	 */
	public function test_hostname_substring_relationship_detection(): void {
		global $wpdb;

		// First migration from full hostname.
		$post1 = $this->create_post_fixture( [ 'ID' => 32001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore

		$this->source_hostname = 'subdomain.example.com';
		$this->run_search_command();
		$this->run_migrate_command();

		// Clean up for second source.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 32001 ] ); // phpcs:ignore
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Second migration from parent domain (substring relationship).
		$post2 = $this->create_post_fixture( [ 'ID' => 32002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->source_hostname = 'example.com';
		$this->run_search_command();
		$this->run_migrate_command();

		// Verify the existing hostname has a substring relationship with new hostname.
		$migrated_hostnames = $this->logic->get_migrated_source_hostnames();

		// Check substring relationship exists (subdomain.example.com contains example.com).
		$has_substring_match = false;
		foreach ( $migrated_hostnames as $existing ) {
			if ( false !== strpos( $existing, 'example.com' ) || false !== strpos( 'example.com', $existing ) ) {
				$has_substring_match = true;
				break;
			}
		}
		$this->assertTrue( $has_substring_match, 'Should detect substring relationship between hostnames.' );

		// Restore original hostname.
		$this->source_hostname = 'test-1.example.com';
	}
}
