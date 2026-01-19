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
	 * cmd_attribute_all_unattributed Tests
	 * =========================================================================
	 */

	/**
	 * @group attribute-command
	 */
	public function test_should_attribute_all_unattributed_posts_to_source_hostname(): void {
		// Create unattributed posts and one already attributed.
		$post1           = self::factory()->post->create(
			[
				'post_title'  => 'Unattributed Post 1',
				'post_status' => 'publish',
			]
		);
		$post2           = self::factory()->post->create(
			[
				'post_title'  => 'Unattributed Post 2',
				'post_status' => 'publish',
			]
		);
		$post_attributed = self::factory()->post->create(
			[
				'post_title'  => 'Already Attributed Post',
				'post_status' => 'publish',
			]
		);

		// Pre-attribute one post to different source.
		$other_meta_key = $this->logic->get_old_id_meta_key( 'other-source.com' );
		update_post_meta( $post_attributed, $other_meta_key, 999 );

		// Run command.
		$this->command->cmd_attribute_all_unattributed(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
			] 
		);

		// Verify unattributed posts were attributed.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( $post1, (int) get_post_meta( $post1, $meta_key, true ), 'Post 1 should be self-attributed.' );
		$this->assertEquals( $post2, (int) get_post_meta( $post2, $meta_key, true ), 'Post 2 should be self-attributed.' );

		// Verify already attributed post was not re-attributed.
		$this->assertEmpty( get_post_meta( $post_attributed, $meta_key, true ), 'Already attributed post should not be re-attributed to new source.' );
	}

	/**
	 * @group attribute-command
	 */
	public function test_should_attribute_all_unattributed_users_to_source_hostname(): void {
		// Create unattributed users and one already attributed.
		$user1           = self::factory()->user->create( [ 'user_login' => 'unattributed_user_1_' . uniqid() ] );
		$user2           = self::factory()->user->create( [ 'user_login' => 'unattributed_user_2_' . uniqid() ] );
		$user_attributed = self::factory()->user->create( [ 'user_login' => 'attributed_user_' . uniqid() ] );

		// Pre-attribute one user to different source.
		$other_meta_key = $this->logic->get_old_id_meta_key( 'other-source.com' );
		update_user_meta( $user_attributed, $other_meta_key, 888 );

		// Run command.
		$this->command->cmd_attribute_all_unattributed(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
			] 
		);

		// Verify unattributed users were attributed.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( $user1, (int) get_user_meta( $user1, $meta_key, true ), 'User 1 should be self-attributed.' );
		$this->assertEquals( $user2, (int) get_user_meta( $user2, $meta_key, true ), 'User 2 should be self-attributed.' );

		// Verify already attributed user was not re-attributed.
		$this->assertEmpty( get_user_meta( $user_attributed, $meta_key, true ), 'Already attributed user should not be re-attributed to new source.' );
	}

	/**
	 * @group attribute-command
	 */
	public function test_should_attribute_all_unattributed_terms_to_source_hostname(): void {
		// Create unattributed terms and one already attributed.
		$term1           = wp_insert_term( 'Unattributed Term 1 ' . uniqid(), 'category' );
		$term2           = wp_insert_term( 'Unattributed Term 2 ' . uniqid(), 'category' );
		$term_attributed = wp_insert_term( 'Attributed Term ' . uniqid(), 'category' );

		// Pre-attribute one term to different source.
		$other_meta_key = $this->logic->get_old_id_meta_key( 'other-source.com' );
		update_term_meta( $term_attributed['term_id'], $other_meta_key, 777 );

		// Run command.
		$this->command->cmd_attribute_all_unattributed(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
			] 
		);

		// Verify unattributed terms were attributed.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( $term1['term_id'], (int) get_term_meta( $term1['term_id'], $meta_key, true ), 'Term 1 should be self-attributed.' );
		$this->assertEquals( $term2['term_id'], (int) get_term_meta( $term2['term_id'], $meta_key, true ), 'Term 2 should be self-attributed.' );

		// Verify already attributed term was not re-attributed.
		$this->assertEmpty( get_term_meta( $term_attributed['term_id'], $meta_key, true ), 'Already attributed term should not be re-attributed to new source.' );
	}

	/**
	 * @group attribute-command
	 */
	public function test_should_skip_already_attributed_content(): void {
		// Create content and attribute it to the same source.
		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$user = self::factory()->user->create( [ 'user_login' => 'test_user_' . uniqid() ] );
		$term = wp_insert_term( 'Test Term ' . uniqid(), 'category' );

		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $post, $meta_key, 100 );
		update_user_meta( $user, $meta_key, 200 );
		update_term_meta( $term['term_id'], $meta_key, 300 );

		// Run command.
		$this->command->cmd_attribute_all_unattributed(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
			] 
		);

		// Verify meta was not changed (still has old values, not self-attributed).
		$this->assertEquals( 100, (int) get_post_meta( $post, $meta_key, true ), 'Post meta should not change.' );
		$this->assertEquals( 200, (int) get_user_meta( $user, $meta_key, true ), 'User meta should not change.' );
		$this->assertEquals( 300, (int) get_term_meta( $term['term_id'], $meta_key, true ), 'Term meta should not change.' );
	}

	/**
	 * =========================================================================
	 * cmd_attribute_ids Tests
	 * =========================================================================
	 */

	/**
	 * @group attribute-command
	 */
	public function test_should_attribute_posts_by_ids(): void {
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
	 * @group attribute-command
	 */
	public function test_should_attribute_users_by_ids(): void {
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
	 * @group attribute-command
	 */
	public function test_should_attribute_terms_by_ids(): void {
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
	 * @group attribute-command
	 */
	public function test_should_skip_already_attributed_ids(): void {
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
}
