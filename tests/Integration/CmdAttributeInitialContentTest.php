<?php
/**
 * Integration tests for cmd_attribute_existing_content_to_hostname command.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for cmd_attribute_existing_content_to_hostname command.
 *
 * @group integration
 */
class CmdAttributeInitialContentTest extends IntegrationTestCase {
	/**
	 * Tests that attribution command matches local posts to live posts by composite key.
	 *
	 * @group attribute-command
	 */
	public function test_attribute_should_match_local_posts_to_live_by_composite_key(): void {
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
		$this->run_attribute_command();

		// Check that old_id meta was saved.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 15001, (int) $old_id, 'Local post should be attributed to live post ID.' );
	}

	/**
	 * Tests that attribution command matches local attachments to live attachments.
	 *
	 * @group attribute-command
	 */
	public function test_attribute_should_match_local_attachments_to_live(): void {
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

		$this->run_attribute_command();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_attachment_id, $meta_key, true );
		$this->assertEquals( 15002, (int) $old_id, 'Local attachment should be attributed to live attachment ID.' );
	}

	/**
	 * Tests that attribution command matches local users to live users by user_login.
	 *
	 * @group attribute-command
	 */
	public function test_attribute_should_match_local_users_to_live_by_user_login(): void {
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

		$this->run_attribute_command();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $local_user_id, $meta_key, true );
		$this->assertEquals( 15101, (int) $old_id, 'Local user should be attributed to live user ID.' );
	}

	/**
	 * Tests that attribution command matches local terms to live terms by slug and taxonomy.
	 *
	 * @group attribute-command
	 */
	public function test_attribute_should_match_local_terms_to_live_by_slug_and_taxonomy(): void {
		global $wpdb;

		// Create local category.
		$local_term    = wp_insert_term( 'Attributed Category', 'category', [ 'slug' => 'attributed-category' ] );
		$local_term_id = is_array( $local_term ) ? $local_term['term_id'] : $local_term;

		// Create matching term in live DB.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 15201, 'name' => 'Attributed Category', 'slug' => 'attributed-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 15201, 'term_id' => 15201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		$this->run_attribute_command();

		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_term_meta( $local_term_id, $meta_key, true );
		$this->assertEquals( 15201, (int) $old_id, 'Local term should be attributed to live term ID.' );
	}

	/**
	 * Tests that attribution command saves old_id meta for all matched object types.
	 *
	 * @group attribute-command
	 */
	public function test_attribute_should_save_old_id_meta_for_matched_objects(): void {
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

		$this->run_attribute_command();

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
	 * @group attribute-command
	 */
	public function test_attribute_should_skip_already_attributed_objects(): void {
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

		$this->run_attribute_command();

		// Old attribution should be preserved, not overwritten.
		$old_id = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 99999, (int) $old_id, 'Existing attribution should not be overwritten.' );
	}
}
