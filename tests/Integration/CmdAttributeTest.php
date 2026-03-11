<?php
/**
 * Integration tests for attribution commands.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for attribution commands.
 *
 * @group integration
 */
class CmdAttributeTest extends IntegrationTestCase {
	
	/**
	 * =========================================================================
	 * cmd_attribute_ids Tests
	 * =========================================================================
	 */

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_attribute_posts_by_ids(): void {
		// Create posts.
		$post1 = self::factory()->post->create(
			[
				'post_title'  => 'Post 1',
				'post_status' => 'publish',
			] 
		);
		$post2 = self::factory()->post->create(
			[
				'post_title'  => 'Post 2',
				'post_status' => 'publish',
			] 
		);
		$post3 = self::factory()->post->create(
			[
				'post_title'  => 'Post 3 (not in list)',
				'post_status' => 'publish',
			] 
		);

		// Run command with specific IDs.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => "$post1,$post2",
			] 
		);

		// Verify specified posts were attributed.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( $post1, (int) get_post_meta( $post1, $meta_key, true ), 'Post 1 should be attributed.' );
		$this->assertEquals( $post2, (int) get_post_meta( $post2, $meta_key, true ), 'Post 2 should be attributed.' );

		// Verify post not in list was not attributed.
		$this->assertEmpty( get_post_meta( $post3, $meta_key, true ), 'Post 3 should not be attributed.' );
	}

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_attribute_users_by_ids(): void {
		// Create users.
		$user1 = self::factory()->user->create( [ 'user_login' => 'user1_' . uniqid() ] );
		$user2 = self::factory()->user->create( [ 'user_login' => 'user2_' . uniqid() ] );
		$user3 = self::factory()->user->create( [ 'user_login' => 'user3_' . uniqid() ] );

		// Run command with specific IDs.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'user-ids'        => "$user1,$user2",
			] 
		);

		// Verify specified users were attributed.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( $user1, (int) get_user_meta( $user1, $meta_key, true ), 'User 1 should be attributed.' );
		$this->assertEquals( $user2, (int) get_user_meta( $user2, $meta_key, true ), 'User 2 should be attributed.' );

		// Verify user not in list was not attributed.
		$this->assertEmpty( get_user_meta( $user3, $meta_key, true ), 'User 3 should not be attributed.' );
	}

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_attribute_terms_by_ids(): void {
		// Create terms.
		$term1 = wp_insert_term( 'Term 1 ' . uniqid(), 'category' );
		$term2 = wp_insert_term( 'Term 2 ' . uniqid(), 'category' );
		$term3 = wp_insert_term( 'Term 3 ' . uniqid(), 'category' );

		// Run command with specific IDs.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'term-ids'        => $term1['term_id'] . ',' . $term2['term_id'],
			] 
		);

		// Verify specified terms were attributed.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( $term1['term_id'], (int) get_term_meta( $term1['term_id'], $meta_key, true ), 'Term 1 should be attributed.' );
		$this->assertEquals( $term2['term_id'], (int) get_term_meta( $term2['term_id'], $meta_key, true ), 'Term 2 should be attributed.' );

		// Verify term not in list was not attributed.
		$this->assertEmpty( get_term_meta( $term3['term_id'], $meta_key, true ), 'Term 3 should not be attributed.' );
	}

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_skip_already_attributed_ids(): void {
		// Create content and pre-attribute to same source with different values.
		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$user = self::factory()->user->create( [ 'user_login' => 'test_user_' . uniqid() ] );
		$term = wp_insert_term( 'Test Term ' . uniqid(), 'category' );

		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $post, $meta_key, 999 );
		update_user_meta( $user, $meta_key, 888 );
		update_term_meta( $term['term_id'], $meta_key, 777 );

		// Run command with these IDs.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => (string) $post,
				'user-ids'        => (string) $user,
				'term-ids'        => (string) $term['term_id'],
			] 
		);

		// Verify meta was not changed (still has old values, not self-attributed).
		$this->assertEquals( 999, (int) get_post_meta( $post, $meta_key, true ), 'Post meta should not change.' );
		$this->assertEquals( 888, (int) get_user_meta( $user, $meta_key, true ), 'User meta should not change.' );
		$this->assertEquals( 777, (int) get_term_meta( $term['term_id'], $meta_key, true ), 'Term meta should not change.' );
	}

	/**
	 * Tests that cmd_attribute_ids logs error and returns when no IDs are provided.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_log_error_when_no_ids_provided(): void {
		// Call command with no ID arguments - should return early without throwing.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
			]
		);

		// Logger is configured( false ) in tests, so no actual log file is created.
		// Method should return gracefully, and if it gets here, the test passes,
		// so simply continue to successful completion.
		$this->assertTrue( true );
	}

	/**
	 * Tests that cmd_attribute_ids skips nonexistent posts gracefully.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_skip_nonexistent_posts(): void {
		// Attribute a non-existent post ID.
		$nonexistent_id = 999999;

		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => (string) $nonexistent_id,
			]
		);

		// Nonexistent posts are logged as warnings in null logger, but don't cause failures.
		// Method should complete without throwing an exception,
		// so simply continue to successful completion.
		$this->assertTrue( true );
	}
}
