<?php
/**
 * Integration tests for cmd_migrate_live_content command, run-state and resume capability.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;

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
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
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
	 * @group run-state
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
	 * @group run-state
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
}
