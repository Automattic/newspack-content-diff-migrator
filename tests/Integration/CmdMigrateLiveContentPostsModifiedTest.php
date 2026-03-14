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
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/run-state' );
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
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/run-state' );
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
	 * Tests that when a post has multiple comments and one is deleted on live,
	 * only the remaining comments exist after reimport.
	 *
	 * @group posts-modified
	 */
	public function test_should_remove_one_comment_when_post_has_multiple_comments_and_one_deleted(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 8002,
				'post_modified' => '2024-01-01 10:00:00',
				'comment_count' => 1,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create three comments.
		$comment_base = [
			'comment_post_ID'      => 8002,
			'comment_author'       => 'Commenter',
			'comment_author_email' => 'commenter@example.com',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-01 12:00:00',
			'comment_date_gmt'     => '2024-01-01 12:00:00',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0,
		];

		$wpdb->insert( $this->live_table_prefix . 'comments', array_merge( $comment_base, [ 'comment_ID' => 8201, 'comment_content' => 'Comment Alpha' ] ) ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'comments', array_merge( $comment_base, [ 'comment_ID' => 8202, 'comment_content' => 'Comment Beta' ] ) ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'comments', array_merge( $comment_base, [ 'comment_ID' => 8203, 'comment_content' => 'Comment Gamma' ] ) ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 8002, $this->source_hostname );
		$comments    = get_comments(
			[
				'post_id' => $new_post_id,
				'orderby' => 'comment_content',
				'order'   => 'ASC',
			] 
		);
		$this->assertCount( 3, $comments, 'Post should have 3 comments after initial import.' );

		// Delete Comment Beta on live and modify post.
		$wpdb->delete( $this->live_table_prefix . 'comments', [ 'comment_ID' => 8202 ] ); // phpcs:ignore
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 8002 ]
		);

		// Fresh run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( 8002, $this->source_hostname );
		$comments_after     = get_comments(
			[
				'post_id' => $reimported_post_id,
				'orderby' => 'comment_content',
				'order'   => 'ASC',
			] 
		);

		$this->assertCount( 2, $comments_after, 'Post should have 2 comments after reimport.' );

		$comment_contents = wp_list_pluck( $comments_after, 'comment_content' );
		$this->assertContains( 'Comment Alpha', $comment_contents, 'Comment Alpha should remain.' );
		$this->assertNotContains( 'Comment Beta', $comment_contents, 'Comment Beta should be removed.' );
		$this->assertContains( 'Comment Gamma', $comment_contents, 'Comment Gamma should remain.' );
	}

	/**
	 * Tests that when a post has multiple postmeta entries and one is deleted on live,
	 * only the remaining metas exist after reimport.
	 *
	 * @group posts-modified
	 */
	public function test_should_remove_one_postmeta_when_post_has_multiple_metas_and_one_deleted(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 15002,
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add three postmeta entries.
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 15201, 'post_id' => 15002, 'meta_key' => 'meta_alpha', 'meta_value' => 'Alpha Value' ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 15202, 'post_id' => 15002, 'meta_key' => 'meta_beta', 'meta_value' => 'Beta Value' ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 15203, 'post_id' => 15002, 'meta_key' => 'meta_gamma', 'meta_value' => 'Gamma Value' ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 15002, $this->source_hostname );
		$this->assertEquals( 'Alpha Value', get_post_meta( $new_post_id, 'meta_alpha', true ) );
		$this->assertEquals( 'Beta Value', get_post_meta( $new_post_id, 'meta_beta', true ) );
		$this->assertEquals( 'Gamma Value', get_post_meta( $new_post_id, 'meta_gamma', true ) );

		// Delete meta_beta on live and modify post.
		$wpdb->delete( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 15202 ] ); // phpcs:ignore
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 15002 ]
		);

		// Fresh run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( 15002, $this->source_hostname );

		$this->assertEquals( 'Alpha Value', get_post_meta( $reimported_post_id, 'meta_alpha', true ), 'Meta Alpha should remain.' );
		$this->assertEmpty( get_post_meta( $reimported_post_id, 'meta_beta', true ), 'Meta Beta should be removed.' );
		$this->assertEquals( 'Gamma Value', get_post_meta( $reimported_post_id, 'meta_gamma', true ), 'Meta Gamma should remain.' );
	}
}
