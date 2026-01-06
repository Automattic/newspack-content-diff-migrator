<?php
/**
 * Integration tests for command cmd_migrate_live_content, comments migration.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for cmd_migrate_live_content command.
 *
 * @group integration
 */
class CmdMigrateLiveContentCommentTest extends IntegrationTestCase {
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
}
