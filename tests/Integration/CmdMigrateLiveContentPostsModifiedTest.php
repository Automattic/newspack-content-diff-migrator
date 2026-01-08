<?php
/**
 * Integration tests for command cmd_migrate_live_content, modified posts detection and reimport.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for command cmd_migrate_live_content, modified posts detection and reimport.
 *
 * @group integration
 */
class CmdMigrateLiveContentPostsModifiedTest extends IntegrationTestCase {
	/**
	 * Tests that posts are detected as modified when post_modified date changes.
	 *
	 * @group posts-modified
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
	 * @group posts-modified
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
	 * @group posts-modified
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
	 * @group posts-modified
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
	 * @group posts-modified
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
	 * Tests that modified posts are reimported with their local ID preserved.
	 * Note: Uses simple post without parent to avoid update_post_parent edge case.
	 *
	 * @group posts-modified
	 */
	public function test_should_reimport_modified_post_with_preserved_local_id(): void {
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

		// Post should be reimported with the same ID.
		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4006, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Reimported post should exist.' );
		$this->assertEquals( $original_local_id, $new_local_id, 'Reimported post should preserve its local ID.' );

		// Verify the post content was updated.
		$reimported_post = get_post( $new_local_id );
		$this->assertNotNull( $reimported_post, 'Reimported post should exist at the same ID.' );
		$this->assertEquals( 'Updated Title', $reimported_post->post_title, 'Reimported post should have updated title.' );
	}

	/**
	 * Tests that deleted modified IDs are saved to run-state.
	 *
	 * @group posts-modified
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
	 * @group posts-modified
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
		$this->assertEquals( $original_local_id, $new_local_id, 'Modified post should preserve its local ID.' );
		$this->assertEquals( 'Modified Title', get_the_title( $new_local_id ), 'Modified post should have updated title.' );
	}

	/**
	 * Tests that deleted modified ID entries are stored in the run-state for resume capability,
	 * and subsequent runs correctly skip already-deleted IDs while still reimporting.
	 *
	 * @group posts-modified
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
		$this->assertEquals( $original_local_id, $new_local_id, 'Post should preserve its local ID after reimport.' );
		$this->assertEquals( 'Modified Title', get_the_title( $new_local_id ), 'Reimported post should have updated title.' );

		// Second migrate run should skip deletion (already deleted) but not skip reimport if needed.
		// Since we're using the same run-state, it should skip reimporting the same post again.
		$this->run_migrate_command();

		// Post should still exist with same ID (not deleted again or duplicated).
		$final_local_id = $this->logic->get_current_post_id_by_old_id( 4010, $this->source_hostname );
		$this->assertEquals( $new_local_id, $final_local_id, 'Post ID should remain the same after second migrate run.' );
	}

	/**
	 * Tests that a post can be imported, then modified and reimported multiple times across independent migration cycles,
	 * always preserving the same local ID.
	 *
	 * Scenario:
	 * 1. Initial import → post gets local ID=X
	 * 2. First modification → reimport with preserved ID=X
	 * 3. Second modification (new cycle) → reimport AGAIN with preserved ID=X
	 *
	 * @group posts-modified
	 */
	public function test_should_preserve_id_across_multiple_independent_reimport_cycles(): void {
		global $wpdb;

		// --- Cycle 1: Initial import ---
		$post = $this->create_post_fixture(
			[
				'ID'            => 4200,
				'post_title'    => 'Original Title',
				'post_content'  => 'Original content.',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4200, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported on initial cycle.' );
		$this->assertEquals( 'Original Title', get_the_title( $original_local_id ) );

		// --- Cycle 2: First modification and reimport ---
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'First Modification',
				'post_content'      => 'First modified content.',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4200 ]
		); // phpcs:ignore

		// Start fresh run-state for new migration cycle.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		// Verify detected as modified.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4200, $modified_ids, 'Post should be detected as modified in cycle 2.' );

		$this->run_migrate_command();

		$cycle2_local_id = $this->logic->get_current_post_id_by_old_id( 4200, $this->source_hostname );
		$this->assertNotNull( $cycle2_local_id, 'Post should be reimported in cycle 2.' );
		$this->assertEquals( $original_local_id, $cycle2_local_id, 'Post should preserve local ID after first reimport.' );
		$this->assertEquals( 'First Modification', get_the_title( $cycle2_local_id ) );

		// --- Cycle 3: Second modification and reimport ---
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Second Modification',
				'post_content'      => 'Second modified content.',
				'post_modified'     => '2024-12-01 10:00:00',
				'post_modified_gmt' => '2024-12-01 10:00:00',
			],
			[ 'ID' => 4200 ]
		); // phpcs:ignore

		// Start fresh run-state for another new migration cycle.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		// Verify detected as modified again.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4200, $modified_ids, 'Post should be detected as modified in cycle 3.' );

		$this->run_migrate_command();

		$cycle3_local_id = $this->logic->get_current_post_id_by_old_id( 4200, $this->source_hostname );
		$this->assertNotNull( $cycle3_local_id, 'Post should be reimported in cycle 3.' );
		$this->assertEquals( $original_local_id, $cycle3_local_id, 'Post should preserve same local ID after second reimport.' );
		$this->assertEquals( 'Second Modification', get_the_title( $cycle3_local_id ) );

		// Verify old_id meta is still correct.
		$meta_key = $this->logic->get_old_id_meta_key( $this->source_hostname );
		$old_id   = get_post_meta( $cycle3_local_id, $meta_key, true );
		$this->assertEquals( 4200, (int) $old_id, 'Old ID meta should still point to original live ID.' );
	}

	/**
	 * Tests that reimported modified posts preserve their local wp_posts.ID.
	 *
	 * @group posts-modified
	 */
	public function test_reimport_should_preserve_local_post_id(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4100,
				'post_title'    => 'Original Title',
				'post_content'  => 'Original content.',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4100, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		$original_post = get_post( $original_local_id );
		$this->assertEquals( 'Original Title', $original_post->post_title );
		$this->assertEquals( 'Original content.', $original_post->post_content );

		// Modify post in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Title',
				'post_content'      => 'Modified content with updates.',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4100 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// The reimported post should have the SAME local ID.
		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4100, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );
		$this->assertEquals( $original_local_id, $new_local_id, 'Reimported post should preserve its local ID.' );

		// Verify content was updated.
		$reimported_post = get_post( $new_local_id );
		$this->assertEquals( 'Modified Title', $reimported_post->post_title, 'Reimported post should have updated title.' );
		$this->assertEquals( 'Modified content with updates.', $reimported_post->post_content, 'Reimported post should have updated content.' );
	}

	/**
	 * Tests that reimported posts preserve the old_id meta pointing to the live ID.
	 *
	 * @group posts-modified
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
		$this->assertEquals( $original_local_id, $new_local_id, 'Post should preserve its local ID.' );

		$new_old_id = get_post_meta( $new_local_id, $meta_key, true );
		$this->assertEquals( 4011, (int) $new_old_id, 'Reimported post should preserve old_id meta.' );
	}

	/**
	 * Tests that reimporting a post with a parent correctly updates parent relationships.
	 *
	 * @group posts-modified
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
		$this->assertEquals( $child_local_id, $new_child_local_id, 'Child should preserve its local ID.' );

		$new_child_post_obj = get_post( $new_child_local_id );
		$this->assertEquals( $parent_local_id, (int) $new_child_post_obj->post_parent, 'Reimported child should still reference parent.' );
		$this->assertEquals( 'Modified Child Post', $new_child_post_obj->post_title, 'Reimported child should have updated title.' );
	}

	/**
	 * Tests that reimporting updates post content correctly.
	 *
	 * @group posts-modified
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
		$this->assertEquals( $original_local_id, $new_local_id, 'Post should preserve its local ID.' );

		$new_post = get_post( $new_local_id );
		$this->assertStringContainsString( 'Updated content with new information', $new_post->post_content );
		$this->assertStringContainsString( 'And a block', $new_post->post_content );
		$this->assertEquals( 'Updated excerpt with more detail.', $new_post->post_excerpt );
	}

	/**
	 * Tests that multiple modified posts are all reimported correctly.
	 *
	 * @group posts-modified
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
			$this->assertEquals( $original_local_ids[ $live_id ], $new_local_id, "Post {$i} should preserve its local ID." );
			$this->assertEquals( "Modified Post {$i}", get_the_title( $new_local_id ), "Post {$i} should have updated title." );
		}
	}

	/**
	 * Tests reimporting a child post when the parent was also modified.
	 *
	 * @group posts-modified
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
		$this->assertEquals( $original_parent_local_id, $new_parent_local_id, 'Parent should preserve its local ID.' );
		$this->assertEquals( $original_child_local_id, $new_child_local_id, 'Child should preserve its local ID.' );

		// Verify titles updated.
		$this->assertEquals( 'Parent Modified', get_the_title( $new_parent_local_id ), 'Parent should have updated title.' );
		$this->assertEquals( 'Child Modified', get_the_title( $new_child_local_id ), 'Child should have updated title.' );

		// Verify child still references the new parent.
		$new_child_post = get_post( $new_child_local_id );
		$this->assertEquals( $new_parent_local_id, (int) $new_child_post->post_parent, 'Child should reference new parent ID.' );
	}

	/**
	 * Tests that reimporting a post with a featured image correctly updates the _thumbnail_id.
	 *
	 * @group posts-modified
	 */
	public function test_reimport_should_update_featured_image_id(): void {
		global $wpdb;

		// Create attachment in live.
		$attachment = [
			'ID'                    => 4050,
			'post_author'           => 1,
			'post_date'             => '2024-01-01 10:00:00',
			'post_date_gmt'         => '2024-01-01 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Featured Image',
			'post_excerpt'          => '',
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'featured-image',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-01 10:00:00',
			'post_modified_gmt'     => '2024-01-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/wp-content/uploads/featured.jpg',
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => 'image/jpeg',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		// Create post with featured image in live.
		$post = $this->create_post_fixture(
			[
				'ID'            => 4051,
				'post_title'    => 'Post With Featured Image',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add _thumbnail_id meta pointing to attachment.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'meta_id'    => 40501,
				'post_id'    => 4051,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => '4050', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_post_local_id       = $this->logic->get_current_post_id_by_old_id( 4051, $this->source_hostname );
		$original_attachment_local_id = $this->logic->get_current_post_id_by_old_id( 4050, $this->source_hostname );
		$this->assertNotNull( $original_post_local_id, 'Post should be imported.' );
		$this->assertNotNull( $original_attachment_local_id, 'Attachment should be imported.' );

		// Verify featured image was set correctly.
		$original_thumbnail_id = get_post_meta( $original_post_local_id, '_thumbnail_id', true );
		$this->assertEquals( $original_attachment_local_id, (int) $original_thumbnail_id, 'Featured image should point to local attachment.' );

		// Modify post in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Post With Featured Image Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4051 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was reimported.
		$new_post_local_id = $this->logic->get_current_post_id_by_old_id( 4051, $this->source_hostname );
		$this->assertNotNull( $new_post_local_id, 'Post should be reimported.' );
		$this->assertEquals( $original_post_local_id, $new_post_local_id, 'Post should preserve its local ID.' );

		// Verify featured image still points to correct local attachment (not the old live ID).
		$new_thumbnail_id = get_post_meta( $new_post_local_id, '_thumbnail_id', true );
		$this->assertEquals( $original_attachment_local_id, (int) $new_thumbnail_id, 'Reimported post featured image should point to local attachment ID.' );
	}

	/**
	 * Tests that reimporting a post with block content correctly updates attachment IDs in blocks.
	 *
	 * @group posts-modified
	 */
	public function test_reimport_should_update_attachment_ids_in_blocks(): void {
		global $wpdb;

		// Create attachment in live.
		$attachment = [
			'ID'                    => 4060,
			'post_author'           => 1,
			'post_date'             => '2024-01-01 10:00:00',
			'post_date_gmt'         => '2024-01-01 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Block Image',
			'post_excerpt'          => '',
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'block-image',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-01 10:00:00',
			'post_modified_gmt'     => '2024-01-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/wp-content/uploads/block-image.jpg',
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => 'image/jpeg',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		// Create post with image block referencing the attachment.
		$block_content = '<!-- wp:image {"id":4060} --><figure class="wp-block-image"><img src="http://test.local/block-image.jpg" class="wp-image-4060"/></figure><!-- /wp:image -->';
		$post          = $this->create_post_fixture(
			[
				'ID'            => 4061,
				'post_title'    => 'Post With Image Block',
				'post_content'  => $block_content,
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_post_local_id       = $this->logic->get_current_post_id_by_old_id( 4061, $this->source_hostname );
		$original_attachment_local_id = $this->logic->get_current_post_id_by_old_id( 4060, $this->source_hostname );
		$this->assertNotNull( $original_post_local_id, 'Post should be imported.' );
		$this->assertNotNull( $original_attachment_local_id, 'Attachment should be imported.' );

		// Verify block content was updated with local attachment ID.
		$original_post_content = get_post( $original_post_local_id )->post_content;
		$this->assertStringContainsString( '"id":' . $original_attachment_local_id, $original_post_content, 'Block should reference local attachment ID.' );

		// Modify post in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Post With Image Block Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4061 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was reimported.
		$new_post_local_id = $this->logic->get_current_post_id_by_old_id( 4061, $this->source_hostname );
		$this->assertNotNull( $new_post_local_id, 'Post should be reimported.' );
		$this->assertEquals( $original_post_local_id, $new_post_local_id, 'Post should preserve its local ID.' );

		// Verify block content still has correct local attachment ID (not the old live ID).
		$new_post_content = get_post( $new_post_local_id )->post_content;
		$this->assertStringContainsString( '"id":' . $original_attachment_local_id, $new_post_content, 'Reimported post block should reference local attachment ID.' );
		$this->assertStringNotContainsString( '"id":4060', $new_post_content, 'Reimported post block should NOT reference old live attachment ID.' );
	}

	/**
	 * Tests reimporting with post meta preserved.
	 *
	 * @group posts-modified
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
		$this->assertEquals( $original_local_id, $new_local_id, 'Post should preserve its local ID.' );

		$new_meta = get_post_meta( $new_local_id, 'custom_meta_key', true );
		$this->assertEquals( 'updated_value', $new_meta, 'Reimported post should have updated meta.' );
	}
}
