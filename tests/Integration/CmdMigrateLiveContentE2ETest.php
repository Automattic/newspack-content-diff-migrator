<?php
/**
 * Integration tests for command cmd_migrate_live_content, end-to-end full migration.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;

/**
 * Integration test class for command cmd_migrate_live_content, end-to-end full migration.
 *
 * @group integration
 */
class CmdMigrateLiveContentE2ETest extends IntegrationTestCase {
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
		// Write manifest and empty new_ids file.
		$this->run_state->write_manifest(
			[
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
				'source_hostname'   => $this->source_hostname,
				'live_table_prefix' => $this->live_table_prefix,
				'post_types'        => [ 'post', 'page', 'attachment' ],
				'counts'            => [
					'new_ids'      => 0,
					'modified_ids' => 0,
				],
			] 
		);
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
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Run search again - should detect as modified.
		$this->run_search_command();

		// Verify modified_ids were written.
		$modified_ids_map = $this->run_state->get_modified_ids_map();
		$this->assertNotNull( $modified_ids_map, 'Modified IDs map should exist.' );
		$this->assertArrayHasKey( $live_post_id, $modified_ids_map, 'Live post should be detected as modified.' );

		// Run migrate command.
		$this->run_migrate_command();

		// Verify post was reimported with preserved ID.
		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );
		$this->assertNotNull( $reimported_post_id, 'Post should have been reimported.' );
		// The reimported post preserves its local ID.
		$this->assertEquals( $new_post_id, $reimported_post_id, 'Reimported post should preserve its local ID.' );

		// Verify post exists at the preserved ID.
		$post = get_post( $reimported_post_id );
		$this->assertNotNull( $post, 'Reimported post should exist at preserved ID.' );
	}

	/**
	 * @group e2e
	 */
	public function test_should_return_early_when_no_new_or_modified_posts(): void {
		// Write manifest and empty new_ids and modified_ids.
		$this->run_state->write_manifest(
			[
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
				'source_hostname'   => $this->source_hostname,
				'live_table_prefix' => $this->live_table_prefix,
				'post_types'        => [ 'post', 'page', 'attachment' ],
				'counts'            => [
					'new_ids'      => 0,
					'modified_ids' => 0,
				],
			] 
		);
		$this->run_state->write_new_ids( [] );
		$this->run_state->write_modified_ids( [] );

		// Run migrate command - should return early without errors.
		$this->run_migrate_command();

		// Just verify no exception was thrown.
		$this->assertTrue( true, 'Migration should complete without errors when no posts to migrate.' );
	}
}
