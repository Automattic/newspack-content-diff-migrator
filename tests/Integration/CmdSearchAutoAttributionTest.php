<?php
/**
 * Integration tests for auto-attribution in the search command.
 *
 * These tests verify the auto-attribution logic that runs during
 * cmd_search_new_content_on_live(), which automatically attributes
 * local content that matches live content by identifiers.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;

/**
 * Integration test class for auto-attribution in search command.
 *
 * @group integration
 * @group auto-attribution
 */
class CmdSearchAutoAttributionTest extends IntegrationTestCase {

	// =========================================================================
	// POSTS AUTO-ATTRIBUTION TESTS
	// =========================================================================

	/**
	 * Tests that unattributed local posts matching live posts get auto-attributed.
	 *
	 * @group auto-attribution
	 */
	public function test_search_auto_attributes_matching_posts(): void {
		global $wpdb;

		// Create a local post.
		$local_post_id = wp_insert_post(
			[
				'post_title'   => 'Matching Post Title',
				'post_name'    => 'matching-post-title',
				'post_date'    => '2024-01-15 10:00:00',
				'post_content' => 'Some content',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			]
		);

		// Create a matching live post with same title/slug/date.
		$live_post = $this->create_post_fixture(
			[
				'ID'         => 5001,
				'post_title' => 'Matching Post Title',
				'post_name'  => 'matching-post-title',
				'post_date'  => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search (which should auto-attribute).
		$this->run_search_command();

		// Verify local post was attributed to live ID.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 5001, (int) $old_id, 'Local post should be attributed to matching live post.' );

		// Clean up.
		wp_delete_post( $local_post_id, true );
	}

	/**
	 * Tests that non-matching local posts are NOT auto-attributed.
	 *
	 * @group auto-attribution
	 */
	public function test_search_does_not_attribute_non_matching_posts(): void {
		global $wpdb;

		// Create a local post.
		$local_post_id = wp_insert_post(
			[
				'post_title'   => 'Local Only Post',
				'post_name'    => 'local-only-post',
				'post_date'    => '2024-01-15 10:00:00',
				'post_content' => 'Some content',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			]
		);

		// Create a live post with DIFFERENT title/slug.
		$live_post = $this->create_post_fixture(
			[
				'ID'         => 5002,
				'post_title' => 'Completely Different Title',
				'post_name'  => 'completely-different',
				'post_date'  => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search.
		$this->run_search_command();

		// Verify local post was NOT attributed.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEmpty( $old_id, 'Non-matching local post should not be attributed.' );

		// Clean up.
		wp_delete_post( $local_post_id, true );
	}

	/**
	 * Tests that posts already attributed to the SAME source are NOT re-attributed.
	 *
	 * @group auto-attribution
	 */
	public function test_search_skips_posts_already_attributed_to_same_source(): void {
		global $wpdb;

		// Create a local post and pre-attribute it.
		$local_post_id = wp_insert_post(
			[
				'post_title'   => 'Pre-Attributed Post',
				'post_name'    => 'pre-attributed-post',
				'post_date'    => '2024-01-15 10:00:00',
				'post_content' => 'Some content',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			]
		);

		// Pre-attribute to this source with a specific ID.
		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $local_post_id, $meta_key, 999 );

		// Create a matching live post.
		$live_post = $this->create_post_fixture(
			[
				'ID'         => 5003,
				'post_title' => 'Pre-Attributed Post',
				'post_name'  => 'pre-attributed-post',
				'post_date'  => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search.
		$this->run_search_command();

		// Verify meta was NOT changed.
		$old_id = get_post_meta( $local_post_id, $meta_key, true );
		$this->assertEquals( 999, (int) $old_id, 'Pre-attributed post should keep original meta value.' );

		// Clean up.
		wp_delete_post( $local_post_id, true );
	}

	/**
	 * Tests that posts already attributed to a DIFFERENT source are NOT re-attributed.
	 * This is the key fix for multi-source collision scenarios.
	 *
	 * @group auto-attribution
	 * @group multi-source
	 */
	public function test_search_skips_posts_attributed_to_different_source(): void {
		global $wpdb;

		// Create a local post and attribute it to a DIFFERENT source.
		$local_post_id = wp_insert_post(
			[
				'post_title'   => 'Multi-Source Post',
				'post_name'    => 'multi-source-post',
				'post_date'    => '2024-01-15 10:00:00',
				'post_content' => 'Some content',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			]
		);

		// Attribute to source 2 (different from test source).
		$meta_key_source2 = ContentDiffLogic::get_old_id_meta_key( $this->source_hostname_2 );
		update_post_meta( $local_post_id, $meta_key_source2, 8888 );

		// Create a matching live post in source 1's tables.
		$live_post = $this->create_post_fixture(
			[
				'ID'         => 5004,
				'post_title' => 'Multi-Source Post',
				'post_name'  => 'multi-source-post',
				'post_date'  => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search for source 1.
		$this->run_search_command();

		// Verify source 1 meta was NOT added (post is attributed to source 2).
		$meta_key_source1 = $this->get_old_id_meta_key();
		$old_id_source1   = get_post_meta( $local_post_id, $meta_key_source1, true );
		$this->assertEmpty( $old_id_source1, 'Post attributed to source 2 should NOT be attributed to source 1.' );

		// Verify source 2 meta is still intact.
		$old_id_source2 = get_post_meta( $local_post_id, $meta_key_source2, true );
		$this->assertEquals( 8888, (int) $old_id_source2, 'Source 2 attribution should remain unchanged.' );

		// Clean up.
		wp_delete_post( $local_post_id, true );
	}

	// =========================================================================
	// USERS AUTO-ATTRIBUTION TESTS
	// =========================================================================

	/**
	 * Tests that unattributed local users matching live users get auto-attributed.
	 *
	 * @group auto-attribution
	 */
	public function test_search_auto_attributes_matching_users(): void {
		global $wpdb;

		$unique_login = 'testuser_' . uniqid();

		// Create a local user.
		$local_user_id = $this->factory->user->create( [ 'user_login' => $unique_login ] );

		// Create a matching live user with same login.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 6001,
				'user_login' => $unique_login,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		// Create a live post to trigger the search (users are only processed if posts exist).
		$live_post = $this->create_post_fixture( [ 'ID' => 6002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search.
		$this->run_search_command();

		// Verify local user was attributed to live ID.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $local_user_id, $meta_key, true );
		$this->assertEquals( 6001, (int) $old_id, 'Local user should be attributed to matching live user.' );
	}

	/**
	 * Tests that users already attributed to a different source are NOT re-attributed.
	 *
	 * @group auto-attribution
	 * @group multi-source
	 */
	public function test_search_skips_users_attributed_to_different_source(): void {
		global $wpdb;

		$unique_login = 'multiuser_' . uniqid();

		// Create a local user and attribute to source 2.
		$local_user_id = $this->factory->user->create( [ 'user_login' => $unique_login ] );

		$meta_key_source2 = ContentDiffLogic::get_old_id_meta_key( $this->source_hostname_2 );
		update_user_meta( $local_user_id, $meta_key_source2, 7777 );

		// Create matching live user in source 1's tables.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 6003,
				'user_login' => $unique_login,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		// Create a live post to trigger search.
		$live_post = $this->create_post_fixture( [ 'ID' => 6004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search for source 1.
		$this->run_search_command();

		// Verify source 1 meta was NOT added.
		$meta_key_source1 = $this->get_old_id_meta_key();
		$old_id_source1   = get_user_meta( $local_user_id, $meta_key_source1, true );
		$this->assertEmpty( $old_id_source1, 'User attributed to source 2 should NOT be attributed to source 1.' );
	}

	// =========================================================================
	// ATTACHMENTS AUTO-ATTRIBUTION TESTS
	// =========================================================================

	/**
	 * Tests that unattributed local attachments matching live attachments get auto-attributed.
	 *
	 * @group auto-attribution
	 */
	public function test_search_auto_attributes_matching_attachments(): void {
		global $wpdb;

		// Create a local attachment.
		$local_attachment_id = wp_insert_attachment(
			[
				'post_title'     => 'test-image.jpg',
				'post_name'      => 'test-image',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'post_date'      => '2024-01-15 10:00:00',
			]
		);

		// Create a matching live attachment.
		$live_attachment = $this->create_post_fixture(
			[
				'ID'             => 7001,
				'post_title'     => 'test-image.jpg',
				'post_name'      => 'test-image',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_date'      => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_attachment ); // phpcs:ignore

		// Run search with attachments.
		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		// Verify local attachment was attributed.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_attachment_id, $meta_key, true );
		$this->assertEquals( 7001, (int) $old_id, 'Local attachment should be attributed to matching live attachment.' );

		// Clean up.
		wp_delete_attachment( $local_attachment_id, true );
	}

	/**
	 * Tests that attachments attributed to a different source are NOT re-attributed.
	 *
	 * @group auto-attribution
	 * @group multi-source
	 */
	public function test_search_skips_attachments_attributed_to_different_source(): void {
		global $wpdb;

		// Create a local attachment and attribute to source 2.
		$local_attachment_id = wp_insert_attachment(
			[
				'post_title'     => 'multi-source-image.jpg',
				'post_name'      => 'multi-source-image',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'post_date'      => '2024-01-15 10:00:00',
			]
		);

		$meta_key_source2 = ContentDiffLogic::get_old_id_meta_key( $this->source_hostname_2 );
		update_post_meta( $local_attachment_id, $meta_key_source2, 6666 );

		// Create matching live attachment.
		$live_attachment = $this->create_post_fixture(
			[
				'ID'             => 7002,
				'post_title'     => 'multi-source-image.jpg',
				'post_name'      => 'multi-source-image',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_date'      => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_attachment ); // phpcs:ignore

		// Run search for source 1.
		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		// Verify source 1 meta was NOT added.
		$meta_key_source1 = $this->get_old_id_meta_key();
		$old_id_source1   = get_post_meta( $local_attachment_id, $meta_key_source1, true );
		$this->assertEmpty( $old_id_source1, 'Attachment attributed to source 2 should NOT be attributed to source 1.' );

		// Clean up.
		wp_delete_attachment( $local_attachment_id, true );
	}

	// =========================================================================
	// TERMS AUTO-ATTRIBUTION TESTS
	// =========================================================================

	/**
	 * Tests that unattributed local terms matching live terms get auto-attributed.
	 *
	 * @group auto-attribution
	 */
	public function test_search_auto_attributes_matching_terms(): void {
		global $wpdb;

		// Create a local category term.
		$term_result   = wp_insert_term( 'Matching Category', 'category' );
		$local_term_id = $term_result['term_id'];

		// Create matching live term.
		$live_term = $this->create_term_fixture(
			[
				'term_id' => 8001,
				'name'    => 'Matching Category',
				'slug'    => 'matching-category',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'terms', $live_term ); // phpcs:ignore

		$live_term_taxonomy = $this->create_term_taxonomy_fixture(
			[
				'term_taxonomy_id' => 8001,
				'term_id'          => 8001,
				'taxonomy'         => 'category',
				'parent'           => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $live_term_taxonomy ); // phpcs:ignore

		// Create a live post to trigger search.
		$live_post = $this->create_post_fixture( [ 'ID' => 8002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search.
		$this->run_search_command();

		// Verify local term was attributed.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_term_meta( $local_term_id, $meta_key, true );
		$this->assertEquals( 8001, (int) $old_id, 'Local term should be attributed to matching live term.' );
	}

	/**
	 * Tests that terms attributed to a different source are NOT re-attributed.
	 *
	 * @group auto-attribution
	 * @group multi-source
	 */
	public function test_search_skips_terms_attributed_to_different_source(): void {
		global $wpdb;

		// Create a local term and attribute to source 2.
		$term_result   = wp_insert_term( 'Multi-Source Category', 'category' );
		$local_term_id = $term_result['term_id'];

		$meta_key_source2 = ContentDiffLogic::get_old_id_meta_key( $this->source_hostname_2 );
		update_term_meta( $local_term_id, $meta_key_source2, 5555 );

		// Create matching live term.
		$live_term = $this->create_term_fixture(
			[
				'term_id' => 8003,
				'name'    => 'Multi-Source Category',
				'slug'    => 'multi-source-category',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'terms', $live_term ); // phpcs:ignore

		$live_term_taxonomy = $this->create_term_taxonomy_fixture(
			[
				'term_taxonomy_id' => 8003,
				'term_id'          => 8003,
				'taxonomy'         => 'category',
				'parent'           => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $live_term_taxonomy ); // phpcs:ignore

		// Create a live post.
		$live_post = $this->create_post_fixture( [ 'ID' => 8004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search for source 1.
		$this->run_search_command();

		// Verify source 1 meta was NOT added.
		$meta_key_source1 = $this->get_old_id_meta_key();
		$old_id_source1   = get_term_meta( $local_term_id, $meta_key_source1, true );
		$this->assertEmpty( $old_id_source1, 'Term attributed to source 2 should NOT be attributed to source 1.' );
	}

	// =========================================================================
	// CUSTOM TAXONOMIES TESTS
	// =========================================================================

	/**
	 * Tests that custom taxonomies argument limits which terms get auto-attributed.
	 *
	 * @group auto-attribution
	 */
	public function test_search_respects_custom_taxonomies_argument(): void {
		global $wpdb;

		// Register a custom taxonomy for testing.
		register_taxonomy( 'test_taxonomy', 'post' );

		// Create a local term in custom taxonomy.
		$term_result   = wp_insert_term( 'Custom Tax Term', 'test_taxonomy' );
		$local_term_id = $term_result['term_id'];

		// Create matching live term.
		$live_term = $this->create_term_fixture(
			[
				'term_id' => 9001,
				'name'    => 'Custom Tax Term',
				'slug'    => 'custom-tax-term',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'terms', $live_term ); // phpcs:ignore

		$live_term_taxonomy = $this->create_term_taxonomy_fixture(
			[
				'term_taxonomy_id' => 9001,
				'term_id'          => 9001,
				'taxonomy'         => 'test_taxonomy',
				'parent'           => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $live_term_taxonomy ); // phpcs:ignore

		// Create a live post.
		$live_post = $this->create_post_fixture( [ 'ID' => 9002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search WITHOUT custom taxonomy in the list.
		$this->run_search_command( [ 'custom-taxonomies-csv' => 'category,post_tag' ] );

		// Verify term was NOT attributed (taxonomy not in list).
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_term_meta( $local_term_id, $meta_key, true );
		$this->assertEmpty( $old_id, 'Term in unlisted taxonomy should not be attributed.' );

		// Clean up and reset run state for second search.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Run search WITH custom taxonomy in the list.
		$this->run_search_command( [ 'custom-taxonomies-csv' => 'category,post_tag,test_taxonomy' ] );

		// Verify term WAS attributed.
		$old_id = get_term_meta( $local_term_id, $meta_key, true );
		$this->assertEquals( 9001, (int) $old_id, 'Term in listed taxonomy should be attributed.' );

		// Clean up.
		unregister_taxonomy( 'test_taxonomy' );
	}

	// =========================================================================
	// INTEGRATION WITH MIGRATE TESTS
	// =========================================================================

	/**
	 * Tests that auto-attributed content during search is correctly handled during migrate.
	 * Verifies the full search → migrate flow works correctly.
	 *
	 * @group auto-attribution
	 */
	public function test_auto_attributed_user_is_reused_during_migrate(): void {
		global $wpdb;

		$unique_login = 'reuse_user_' . uniqid();

		// Create a local user.
		$local_user_id = $this->factory->user->create( [ 'user_login' => $unique_login ] );

		// Create matching live user and a post authored by them.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 10001,
				'user_login' => $unique_login,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$live_post = $this->create_post_fixture(
			[
				'ID'          => 10002,
				'post_author' => 10001,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore

		// Run search (auto-attributes the user).
		$this->run_search_command();

		// Verify user was auto-attributed.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $local_user_id, $meta_key, true );
		$this->assertEquals( 10001, (int) $old_id, 'User should be auto-attributed during search.' );

		// Run migrate.
		$this->run_migrate_command();

		// Verify the imported post uses the existing local user.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 10002, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported.' );

		$new_post = get_post( $new_post_id );
		$this->assertEquals( $local_user_id, (int) $new_post->post_author, 'Imported post should use the auto-attributed local user.' );

		// Verify no duplicate users were created.
		$users = get_users( [ 'login' => $unique_login ] );
		$this->assertCount( 1, $users, 'Should not create duplicate user.' );
	}

	/**
	 * Tests that auto-attributed attachments during search are correctly handled during migrate.
	 *
	 * @group auto-attribution
	 */
	public function test_auto_attributed_attachment_is_reused_during_migrate(): void {
		global $wpdb;

		// Create a local attachment.
		$local_attachment_id = wp_insert_attachment(
			[
				'post_title'     => 'reuse-image.jpg',
				'post_name'      => 'reuse-image',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'post_date'      => '2024-01-15 10:00:00',
			]
		);

		// Create matching live attachment.
		$live_attachment = $this->create_post_fixture(
			[
				'ID'             => 11001,
				'post_title'     => 'reuse-image.jpg',
				'post_name'      => 'reuse-image',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_date'      => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_attachment ); // phpcs:ignore

		// Create a live post with the attachment as featured image.
		$live_post = $this->create_post_fixture( [ 'ID' => 11002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 11002,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 11001, // phpcs:ignore
			]
		);

		// Run search with attachments.
		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		// Verify attachment was auto-attributed.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_attachment_id, $meta_key, true );
		$this->assertEquals( 11001, (int) $old_id, 'Attachment should be auto-attributed during search.' );

		// Run migrate.
		$this->run_migrate_command();

		// Verify the imported post uses the existing local attachment for thumbnail.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 11002, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported.' );

		$thumbnail_id = get_post_meta( $new_post_id, '_thumbnail_id', true );
		$this->assertEquals( $local_attachment_id, (int) $thumbnail_id, 'Imported post should use the auto-attributed local attachment as thumbnail.' );

		// Clean up.
		wp_delete_attachment( $local_attachment_id, true );
	}

	// =========================================================================
	// EDGE CASES
	// =========================================================================

	/**
	 * Tests that wp_block post type is included in auto-attribution.
	 *
	 * @group auto-attribution
	 */
	public function test_search_auto_attributes_wp_block_post_type(): void {
		global $wpdb;

		// Create a local reusable block.
		$local_block_id = wp_insert_post(
			[
				'post_title'   => 'My Reusable Block',
				'post_name'    => 'my-reusable-block',
				'post_date'    => '2024-01-15 10:00:00',
				'post_content' => '<!-- wp:paragraph --><p>Block content</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_type'    => 'wp_block',
			]
		);

		// Create matching live block.
		$live_block = $this->create_post_fixture(
			[
				'ID'         => 12001,
				'post_title' => 'My Reusable Block',
				'post_name'  => 'my-reusable-block',
				'post_date'  => '2024-01-15 10:00:00',
				'post_type'  => 'wp_block',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $live_block ); // phpcs:ignore

		// Run search with wp_block included.
		$this->run_search_command( [ 'post-types-csv' => 'post,page,wp_block' ] );

		// Verify block was auto-attributed.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_post_meta( $local_block_id, $meta_key, true );
		$this->assertEquals( 12001, (int) $old_id, 'wp_block post type should be auto-attributed.' );

		// Clean up.
		wp_delete_post( $local_block_id, true );
	}

	/**
	 * Tests that auto-attribution handles large numbers of objects efficiently.
	 * This is a sanity check, not a performance benchmark.
	 *
	 * @group auto-attribution
	 */
	public function test_search_handles_multiple_objects_for_attribution(): void {
		global $wpdb;

		$local_post_ids = [];
		$live_post_ids  = [];

		// Create 10 matching local/live post pairs.
		for ( $i = 1; $i <= 10; $i++ ) {
			$title = "Batch Post $i";
			$slug  = "batch-post-$i";
			$date  = '2024-01-15 10:00:00';

			$local_post_ids[] = wp_insert_post(
				[
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_date'    => $date,
					'post_content' => "Content $i",
					'post_status'  => 'publish',
					'post_type'    => 'post',
				]
			);

			$live_id         = 13000 + $i;
			$live_post_ids[] = $live_id;

			$live_post = $this->create_post_fixture(
				[
					'ID'         => $live_id,
					'post_title' => $title,
					'post_name'  => $slug,
					'post_date'  => $date,
				]
			);
			$wpdb->insert( $this->live_table_prefix . 'posts', $live_post ); // phpcs:ignore
		}

		// Run search.
		$this->run_search_command();

		// Verify all were attributed.
		$meta_key         = $this->get_old_id_meta_key();
		$attributed_count = 0;
		foreach ( $local_post_ids as $index => $local_id ) {
			$old_id = get_post_meta( $local_id, $meta_key, true );
			if ( (int) $old_id === $live_post_ids[ $index ] ) {
				$attributed_count++;
			}
		}

		$this->assertEquals( 10, $attributed_count, 'All 10 posts should be auto-attributed.' );

		// Clean up.
		foreach ( $local_post_ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
