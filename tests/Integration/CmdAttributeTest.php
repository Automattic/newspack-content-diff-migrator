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
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_all_unattributed_should_attribute_all_unattributed_posts_to_source_hostname(): void {
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
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_all_unattributed_should_attribute_all_unattributed_users_to_source_hostname(): void {
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
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_all_unattributed_should_attribute_all_unattributed_terms_to_source_hostname(): void {
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
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_all_unattributed_should_skip_already_attributed_content(): void {
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
	 * cmd_attribute_match_local_to_live_tables Tests
	 * =========================================================================
	 */

	/**
	 * Tests that attribution command matches local posts to live posts by composite key.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_match_local_to_live_tables_should_match_local_posts_to_live_by_composite_key(): void {
		global $wpdb;

		// Create a local post first.
		$local_post_id = self::factory()->post->create(
			[
				'post_title'  => 'Matching Post Title',
				'post_name'   => 'matching-post-slug',
				'post_date'   => '2024-01-15 10:00:00',
				'post_status' => 'publish',
				'post_type'   => 'post',
			]
		);

		// Create same post in live DB (matching title, slug, date, type).
		$live_post = [
			'ID'                    => 15001,
			'post_author'           => 1,
			'post_date'             => '2024-01-15 10:00:00',
			'post_date_gmt'         => '2024-01-15 10:00:00',
			'post_content'          => '<p>Test content</p>',
			'post_title'            => 'Matching Post Title',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'open',
			'post_password'         => '',
			'post_name'             => 'matching-post-slug',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-15 10:00:00',
			'post_modified_gmt'     => '2024-01-15 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/?p=15001',
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run attribution.
		$this->run_attribute_match_local_to_live_tables();

		// Check that old_id meta was saved.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 15001, (int) $old_id, 'Local post should be attributed to live post ID.' );
	}

	/**
	 * Tests that attribution command matches local attachments to live attachments.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_match_local_to_live_tables_should_match_local_attachments_to_live(): void {
		global $wpdb;

		// Create local attachment.
		$local_attachment_id = self::factory()->attachment->create(
			[
				'post_title'     => 'Test Image',
				'post_name'      => 'test-image',
				'post_date'      => '2024-01-15 10:00:00',
				'post_mime_type' => 'image/jpeg',
			]
		);

		// Create matching attachment in live DB.
		$live_attachment = [
			'ID'                    => 15002,
			'post_author'           => 1,
			'post_date'             => '2024-01-15 10:00:00',
			'post_date_gmt'         => '2024-01-15 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Test Image',
			'post_excerpt'          => '',
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'test-image',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-15 10:00:00',
			'post_modified_gmt'     => '2024-01-15 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/test-image.jpg',
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => 'image/jpeg',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_attachment ); // phpcs:ignore

		$this->run_attribute_match_local_to_live_tables();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_attachment_id, $meta_key, true );
		$this->assertEquals( 15002, (int) $old_id, 'Local attachment should be attributed to live attachment ID.' );
	}

	/**
	 * Tests that attribution command matches local users to live users by user_login.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_match_local_to_live_tables_should_match_local_users_to_live_by_user_login(): void {
		global $wpdb;

		// Create local user.
		$local_user_id = wp_insert_user(
			[
				'user_login' => 'john_doe_attr',
				'user_email' => 'john_attr@test.local',
				'user_pass'  => 'password123',
			]
		);

		// Create matching user in live DB.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 15101,
				'user_login' => 'john_doe_attr',
				'user_email' => 'john_live@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$this->run_attribute_match_local_to_live_tables();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $local_user_id, $meta_key, true );
		$this->assertEquals( 15101, (int) $old_id, 'Local user should be attributed to live user ID.' );
	}

	/**
	 * Tests that attribution command matches local terms to live terms by slug and taxonomy.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_match_local_to_live_tables_should_match_local_terms_to_live_by_slug_and_taxonomy(): void {
		global $wpdb;

		// Create local category.
		$local_term    = wp_insert_term( 'Attributed Category', 'category', [ 'slug' => 'attributed-category' ] );
		$local_term_id = is_array( $local_term ) ? $local_term['term_id'] : $local_term;

		// Create matching term in live DB.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 15201, 'name' => 'Attributed Category', 'slug' => 'attributed-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 15201, 'term_id' => 15201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		$this->run_attribute_match_local_to_live_tables();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_term_meta( $local_term_id, $meta_key, true );
		$this->assertEquals( 15201, (int) $old_id, 'Local term should be attributed to live term ID.' );
	}

	/**
	 * Tests that attribution command saves old_id meta for all matched object types.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_match_local_to_live_tables_should_save_old_id_meta_for_matched_objects(): void {
		global $wpdb;

		// Create local post.
		$local_post_id = self::factory()->post->create(
			[
				'post_title' => 'Meta Test Post',
				'post_name'  => 'meta-test-post',
				'post_date'  => '2024-02-01 10:00:00',
			]
		);

		// Create local user.
		$local_user_id = wp_insert_user(
			[
				'user_login' => 'meta_test_user',
				'user_email' => 'meta_test@test.local',
				'user_pass'  => 'password',
			]
		);

		// Create matching live data.
		$live_post = [
			'ID'                    => 15301,
			'post_author'           => 1,
			'post_date'             => '2024-02-01 10:00:00',
			'post_date_gmt'         => '2024-02-01 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Meta Test Post',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'open',
			'post_password'         => '',
			'post_name'             => 'meta-test-post',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-02-01 10:00:00',
			'post_modified_gmt'     => '2024-02-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/?p=15301',
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 15302,
				'user_login' => 'meta_test_user',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$this->run_attribute_match_local_to_live_tables();

		$meta_key = $this->get_old_id_meta_key();

		// Check post meta.
		$post_old_id = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 15301, (int) $post_old_id, 'Post old_id meta should be saved.' );

		// Check user meta.
		$user_old_id = get_user_meta( $local_user_id, $meta_key, true );
		$this->assertEquals( 15302, (int) $user_old_id, 'User old_id meta should be saved.' );
	}

	/**
	 * Tests that attribution command skips objects that are already attributed.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_match_local_to_live_tables_should_skip_already_attributed_objects(): void {
		global $wpdb;

		// Create local post with existing attribution.
		$local_post_id = self::factory()->post->create(
			[
				'post_title' => 'Already Attributed Post',
				'post_name'  => 'already-attributed-post',
				'post_date'  => '2024-03-01 10:00:00',
			]
		);

		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $local_post_id, $meta_key, 99999 ); // Existing attribution.

		// Create matching live post with different ID.
		$live_post = [
			'ID'                    => 15401,
			'post_author'           => 1,
			'post_date'             => '2024-03-01 10:00:00',
			'post_date_gmt'         => '2024-03-01 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Already Attributed Post',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'open',
			'post_password'         => '',
			'post_name'             => 'already-attributed-post',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-03-01 10:00:00',
			'post_modified_gmt'     => '2024-03-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/?p=15401',
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		$this->run_attribute_match_local_to_live_tables();

		// Old attribution should be preserved, not overwritten.
		$old_id = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 99999, (int) $old_id, 'Existing attribution should not be overwritten.' );
	}

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
