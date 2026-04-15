<?php
/**
 * Integration tests for cmd_migrate_live_content command, run-state and resume capability.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\ReportCreator;

/**
 * Integration test class for cmd_migrate_live_content command, run-state and resume capability.
 *
 * @group integration
 */
class CmdMigrateLiveContentRunStateTest extends IntegrationTestCase {
	/**
	 * @group run-state
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
	 * @group run-state
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
	 * @group run-state
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
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		$second_post_new_id = $this->logic->get_current_post_id_by_old_id( 7102, $this->source_hostname );
		$this->assertNotNull( $second_post_new_id, 'Second post should be imported on resume.' );
	}

	/**
	 * @group run-state
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
	 * @group run-state
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
	 * @group run-state
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
	 * @group run-state
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
	 * @group run-state
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
	 * @group run-state
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
	 * @group run-state
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
	 * @group run-state
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
	 * @group run-state
	 */
	public function test_should_create_runstate_directory_if_not_exists(): void {
		global $wpdb;

		// Create a new temp directory that doesn't exist.
		$new_temp_dir  = sys_get_temp_dir() . '/cdiff-test-new-' . uniqid();
		$new_run_state = new RunState( $new_temp_dir . '/run-state' );
		$this->command->set_run_state( $new_run_state );

		$post = $this->create_post_fixture( [ 'ID' => 9005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();

		// Directory should now exist.
		$this->assertDirectoryExists( $new_temp_dir . '/run-state', 'Run-state directory should be created.' );

		// Cleanup.
		$this->cleanup_temp_dir( $new_temp_dir );

		// Restore original run-state.
		$this->command->set_run_state( $this->run_state );
	}

	/**
	 * Tests that manifest.json is written with migration summary.
	 *
	 * @group run-state
	 */
	public function test_should_write_manifest_json_with_migration_summary(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 9006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();

		// Check manifest file exists and has content.
		$manifest_path = $this->temp_data_dir . '/run-state/' . RunState::FILE_MANIFEST;
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
	 * @group run-state
	 */
	public function test_should_read_manifest_json_correctly(): void {
		global $wpdb;

		$post1 = $this->create_post_fixture( [ 'ID' => 9007 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 9008 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->run_search_command();

		$manifest_path = $this->temp_data_dir . '/run-state/' . RunState::FILE_MANIFEST;
		$manifest      = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore

		$this->assertEquals( 2, $manifest['counts']['new_ids'], 'Manifest should correctly count new_ids.' );
		$this->assertEquals( 0, $manifest['counts']['modified_ids'], 'Manifest should correctly count modified_ids.' );
	}

	/**
	 * Tests that deleted modified ID entries are stored in the run-state for resume capability,
	 * and subsequent runs correctly skip already-deleted IDs while still reimporting.
	 *
	 * @group run-state
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

	// =========================================================================
	// MODIFIED POST DELETION FAILURE + RESUME TESTS
	// =========================================================================

	/**
	 * Tests that when deletion of a modified post fails, the post is:
	 * - Logged as error
	 * - NOT saved to run-state as deleted
	 * - Skipped during reimport (not duplicated)
	 *
	 * Simulates deletion failure by intercepting DELETE queries via the 'query' filter
	 * and modifying them to exclude the target post ID.
	 *
	 * @group run-state
	 * @group deletion-failure
	 */
	public function test_should_handle_modified_post_deletion_failure(): void {
		global $wpdb;

		// Create and import a post.
		$post = $this->create_post_fixture(
			[
				'ID'            => 40001,
				'post_title'    => 'Deletable Post',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 40001, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		// Modify the post in live DB.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Deletable Post',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 40001 ]
		);

		// Create new run-state for the update cycle.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		// Verify post was detected as modified.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 40001, $modified_ids, 'Post should be detected as modified.' );

		// Block deletion by modifying DELETE queries to exclude the target post ID.
		$block_deletion_filter = function ( $query ) use ( $original_local_id ) {
			// Only intercept DELETE queries targeting the posts table with our post ID.
			if (
				preg_match( '/DELETE IGNORE FROM.*posts.*WHERE ID IN/i', $query )
				&& strpos( $query, (string) $original_local_id ) !== false
			) {
				// Remove target ID from the IN clause to simulate failed deletion.
				$query = preg_replace( '/\b' . $original_local_id . '\b,?\s*/', '', $query );
				// Clean up trailing comma if the ID was last in the list.
				$query = preg_replace( '/,\s*\)/', ')', $query );
				// If IN clause is now empty, replace with a no-op condition.
				$query = preg_replace( '/WHERE ID IN\s*\(\s*\)/i', 'WHERE 1=0', $query );
			}
			return $query;
		};
		add_filter( 'query', $block_deletion_filter );

		// Run migrate - deletion should fail for our target post.
		$this->run_migrate_command();

		remove_filter( 'query', $block_deletion_filter );

		// Verify post still exists (deletion failed).
		$post_after_migrate = get_post( $original_local_id );
		$this->assertNotNull( $post_after_migrate, 'Post should still exist after failed deletion.' );

		// Verify the post was NOT saved to run-state as deleted.
		$deleted_map = $this->run_state->get_deleted_modified_ids_map();
		$this->assertArrayNotHasKey( 40001, $deleted_map, 'Failed deletion should NOT be recorded in run-state.' );

		// Verify the post title was NOT updated (reimport was skipped due to failed deletion).
		$this->assertEquals( 'Deletable Post', $post_after_migrate->post_title, 'Post should retain original title since deletion failed.' );
	}

	/**
	 * Tests that on resume after deletion failure, the system retries deletion.
	 *
	 * Simulates deletion failure by intercepting DELETE queries via the 'query' filter
	 * and modifying them to exclude the target post ID on the first attempt.
	 *
	 * @group run-state
	 * @group deletion-failure
	 */
	public function test_should_retry_deletion_on_resume_after_failure(): void {
		global $wpdb;

		// Create and import a post.
		$post = $this->create_post_fixture(
			[
				'ID'            => 40002,
				'post_title'    => 'Retry Deletable Post',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 40002, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		// Modify the post in live DB.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Retry Modified Post',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 40002 ]
		);

		// Create new run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();

		// First migrate attempt - block deletion by modifying DELETE queries to exclude the target post ID.
		$migrate_attempt_count = 0;
		$block_deletion_filter = function ( $query ) use ( $original_local_id, &$migrate_attempt_count ) {
			// Only intercept DELETE queries targeting the posts table with our post ID.
			if (
				0 === $migrate_attempt_count
				&& preg_match( '/DELETE IGNORE FROM.*posts.*WHERE ID IN/i', $query )
				&& strpos( $query, (string) $original_local_id ) !== false
			) {
				// Remove target ID from the IN clause to simulate failed deletion.
				$query = preg_replace( '/\b' . $original_local_id . '\b,?\s*/', '', $query );
				// Clean up trailing comma if the ID was last in the list.
				$query = preg_replace( '/,\s*\)/', ')', $query );
				// If IN clause is now empty, replace with a no-op condition.
				$query = preg_replace( '/WHERE ID IN\s*\(\s*\)/i', 'WHERE 1=0', $query );
			}
			return $query;
		};
		add_filter( 'query', $block_deletion_filter );

		// First migrate - deletion fails for our target post.
		$this->run_migrate_command();
		$migrate_attempt_count++;

		remove_filter( 'query', $block_deletion_filter );

		// Verify post still exists.
		$post_after_first = get_post( $original_local_id );
		$this->assertNotNull( $post_after_first, 'Post should exist after first failed deletion.' );

		// Second migrate (resume) - deletion should succeed now.
		$this->run_migrate_command();

		// After second attempt, the post should be deleted and reimported.
		$deleted_map = $this->run_state->get_deleted_modified_ids_map();
		$this->assertArrayHasKey( 40002, $deleted_map, 'Successful deletion on retry should be recorded.' );

		// Verify the post was reimported with new title.
		$new_local_id = $this->logic->get_current_post_id_by_old_id( 40002, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );

		$reimported_post = get_post( $new_local_id );
		$this->assertEquals( 'Retry Modified Post', $reimported_post->post_title, 'Reimported post should have updated title.' );
	}

	// =========================================================================
	// TRACKING TESTS - USERS
	// =========================================================================

	/**
	 * Tests that imported users are saved to run-state with status "imported".
	 *
	 * @group run-state
	 */
	public function test_should_save_imported_user_to_runstate(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 20001,
				'user_login' => 'runstate_user_' . uniqid(),
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 20002,
				'post_author' => 20001,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify user was saved to run-state.
		$imported_users = $this->run_state->read_imported_users();
		$this->assertNotEmpty( $imported_users, 'Imported users should be saved to run-state.' );

		// Find the user by old_id.
		$found = false;
		foreach ( $imported_users as $user ) {
			if ( 20001 === (int) $user['id_old'] ) {
				$found = true;
				$this->assertEquals( 'imported', $user['status'], 'User status should be imported.' );
				break;
			}
		}
		$this->assertTrue( $found, 'User 20001 should be in run-state.' );
	}

	/**
	 * Tests that existing users matched during auto-attribution are saved to run-state
	 * with status "modified" when their data is updated from live.
	 *
	 * Note: With auto-attribution in search command, users with matching login are
	 * automatically attributed before migrate. During migrate, if user data differs
	 * (e.g., email, display_name), the user gets "modified" status.
	 *
	 * @group run-state
	 */
	public function test_should_save_merged_user_to_runstate(): void {
		global $wpdb;

		$unique_login = 'merge_user_' . uniqid();

		// Create local user first.
		$local_user_id = $this->factory->user->create( [ 'user_login' => $unique_login ] );

		// Create same user in live DB with different ID (and different email/display_name from fixture).
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 21001,
				'user_login' => $unique_login,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 21002,
				'post_author' => 21001,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify user was saved to run-state with modified status.
		// The user was auto-attributed during search, then updated during migrate
		// (because live user has different email/display_name from local).
		$imported_users = $this->run_state->read_imported_users();

		$found = false;
		foreach ( $imported_users as $user ) {
			if ( 21001 === (int) $user['id_old'] && $local_user_id === (int) $user['id_new'] ) {
				$found = true;
				$this->assertEquals( 'modified', $user['status'], 'User status should be modified (auto-attributed during search, updated during migrate).' );
				break;
			}
		}
		$this->assertTrue( $found, 'Auto-attributed user should be in run-state.' );
	}

	// =========================================================================
	// TRACKING TESTS - TERMS
	// =========================================================================

	/**
	 * Tests that imported terms are saved to run-state with status "imported".
	 *
	 * @group run-state
	 */
	public function test_should_save_imported_term_to_runstate(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 22001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create unique term name.
		$term_name = 'ImportedTerm' . uniqid();
		$term      = [
			'term_id'    => 22002,
			'name'       => $term_name,
			'slug'       => sanitize_title( $term_name ),
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore

		$term_taxonomy = [
			'term_taxonomy_id' => 22002,
			'term_id'          => 22002,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 22001, 'term_taxonomy_id' => 22002 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify term was saved to run-state.
		$imported_terms = $this->run_state->read_imported_terms();
		$this->assertNotEmpty( $imported_terms, 'Imported terms should be saved to run-state.' );

		// Find the term by old_id.
		$found = false;
		foreach ( $imported_terms as $term_record ) {
			if ( 22002 === (int) $term_record['term_id_old'] ) {
				$found = true;
				$this->assertEquals( 'imported', $term_record['status'], 'Term status should be imported.' );
				$this->assertEquals( 'category', $term_record['taxonomy'], 'Term taxonomy should be category.' );
				break;
			}
		}
		$this->assertTrue( $found, 'Term 22002 should be in run-state.' );
	}

	/**
	 * Tests that merged terms are saved to run-state with status "merged".
	 *
	 * @group run-state
	 */
	public function test_should_save_merged_term_to_runstate(): void {
		global $wpdb;

		$term_name = 'MergedTerm' . uniqid();

		// Create local term first.
		$local_term    = wp_insert_term( $term_name, 'category' );
		$local_term_id = $local_term['term_id'];

		// Create same term in live DB with different ID.
		$post = $this->create_post_fixture( [ 'ID' => 23001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$term = [
			'term_id'    => 23002,
			'name'       => $term_name,
			'slug'       => sanitize_title( $term_name ),
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore

		$term_taxonomy = [
			'term_taxonomy_id' => 23002,
			'term_id'          => 23002,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 23001, 'term_taxonomy_id' => 23002 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify term was saved to run-state with merged status.
		$imported_terms = $this->run_state->read_imported_terms();

		$found = false;
		foreach ( $imported_terms as $term_record ) {
			if ( 23002 === (int) $term_record['term_id_old'] && $local_term_id === (int) $term_record['term_id_new'] ) {
				$found = true;
				$this->assertEquals( 'merged', $term_record['status'], 'Term status should be merged.' );
				break;
			}
		}
		$this->assertTrue( $found, 'Merged term should be in run-state.' );
	}

	/**
	 * Tests that comment count changes mark a post as modified in run-state and reports.
	 *
	 * @group run-state
	 */
	public function test_should_mark_post_modified_when_comment_count_changes(): void {
		global $wpdb;

		$live_post_id = 25001;
		$post         = $this->create_post_fixture(
			[
				'ID'            => $live_post_id,
				'comment_count' => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Add a comment on live and update comment_count without changing post_modified.
		$comment = [
			'comment_ID'           => 25002,
			'comment_post_ID'      => $live_post_id,
			'comment_author'       => 'Test Commenter',
			'comment_author_email' => 'commenter@test.local',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-20 10:00:00',
			'comment_date_gmt'     => '2024-01-20 10:00:00',
			'comment_content'      => 'Test comment content',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => '',
			'comment_parent'       => 0,
			'user_id'              => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'comments', $comment ); // phpcs:ignore
		$wpdb->update( $this->live_table_prefix . 'posts', [ 'comment_count' => 1 ], [ 'ID' => $live_post_id ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify modified status is recorded in run-state.
		$imported_posts = $this->run_state->read_imported_posts();
		$found_modified = false;
		foreach ( $imported_posts as $post_record ) {
			if ( $live_post_id === (int) $post_record['id_old'] && 'modified' === ( $post_record['status'] ?? '' ) ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'Post should be recorded as modified in run-state when comment_count changes.' );

		// Verify reports CSV contains modified status.
		$reports_dir = rtrim( (string) $this->temp_data_dir, '/' ) . '/reports';
		$posts_csv   = $reports_dir . '/' . ReportCreator::REPORT_POSTS;
		$this->assertFileExists( $posts_csv, 'Posts report should be generated.' );

		$handle = fopen( $posts_csv, 'r' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.
		$this->assertNotFalse( $handle, 'Posts report should be readable.' );

		// Escape='' for RFC 4180 compliance (php.net/fgetcsv).
		$header = fgetcsv( $handle, null, ',', '"', '' );
		$this->assertNotEmpty( $header, 'Posts report header should be present.' );

		$found_in_csv = false;
		while ( ( $row = fgetcsv( $handle, null, ',', '"', '' ) ) !== false ) {
			if ( 'modified' === $row[0] && $live_post_id === (int) $row[2] ) {
				$found_in_csv = true;
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertTrue( $found_in_csv, 'Posts report should include modified status for comment_count changes.' );
	}

	// =========================================================================
	// TRACKING TESTS - ALL ENTITIES
	// =========================================================================

	/**
	 * Tests that all entity types are tracked in a single migration run.
	 *
	 * @group run-state
	 */
	public function test_should_track_all_entity_types_in_single_migration_run(): void {
		global $wpdb;

		// Create user.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 24001,
				'user_login' => 'all_entities_user_' . uniqid(),
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		// Create term.
		$term_name = 'AllEntitiesTerm' . uniqid();
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 24002, 'name' => $term_name, 'slug' => sanitize_title( $term_name ), 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 24002, 'term_id' => 24002, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore

		// Create post with user and term.
		$post = $this->create_post_fixture(
			[
				'ID'          => 24003,
				'post_author' => 24001,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 24003, 'term_taxonomy_id' => 24002 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify all entities are tracked.
		$imported_posts = $this->run_state->read_imported_posts();
		$imported_users = $this->run_state->read_imported_users();
		$imported_terms = $this->run_state->read_imported_terms();

		$this->assertNotEmpty( $imported_posts, 'Posts should be tracked.' );
		$this->assertNotEmpty( $imported_users, 'Users should be tracked.' );
		$this->assertNotEmpty( $imported_terms, 'Terms should be tracked.' );

		// Verify specific IDs.
		$post_found = false;
		foreach ( $imported_posts as $p ) {
			if ( 24003 === (int) $p['id_old'] ) {
				$post_found = true;
				break;
			}
		}
		$this->assertTrue( $post_found, 'Post 24003 should be tracked.' );

		$user_found = false;
		foreach ( $imported_users as $u ) {
			if ( 24001 === (int) $u['id_old'] ) {
				$user_found = true;
				break;
			}
		}
		$this->assertTrue( $user_found, 'User 24001 should be tracked.' );

		$term_found = false;
		foreach ( $imported_terms as $t ) {
			if ( 24002 === (int) $t['term_id_old'] ) {
				$term_found = true;
				break;
			}
		}
		$this->assertTrue( $term_found, 'Term 24002 should be tracked.' );
	}
}
