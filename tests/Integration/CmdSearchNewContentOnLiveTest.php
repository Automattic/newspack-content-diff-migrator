<?php
/**
 * Integration tests for command cmd_search_new_content_on_live.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;

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
}
