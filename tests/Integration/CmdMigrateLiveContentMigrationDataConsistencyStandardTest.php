<?php
/**
 * Integration tests for cmd_migrate_live_content command, Migration Data Consistency Standard.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Utils\SLAHelper;

/**
 * Integration test class for command cmd_migrate_live_content, Migration Data Consistency Standard.
 *
 * @group integration
 */
class CmdMigrateLiveContentMigrationDataConsistencyStandardTest extends IntegrationTestCase {
	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_user_email_when_changed_on_live(): void {
		global $wpdb;

		// Create and import a user.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 13001,
				'user_login' => 'mdcsuser1',
				'user_email' => 'original@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 13002,
				'post_author' => 13001,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$local_user = get_user_by( 'login', 'mdcsuser1' );
		$this->assertEquals( 'original@test.local', $local_user->user_email );

		// Update email in live DB.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'users',
			[ 'user_email' => 'updated@test.local' ],
			[ 'ID' => 13001 ]
		);

		// Run migration again (it will run update_modified_users).
		$this->run_migrate_command();

		// Verify local user email was updated.
		$local_user = get_user_by( 'login', 'mdcsuser1' );
		$this->assertEquals( 'updated@test.local', $local_user->user_email, 'User email should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_user_display_name_when_changed_on_live(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'           => 13101,
				'user_login'   => 'mdcsuser2',
				'display_name' => 'Original Name',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 13102,
				'post_author' => 13101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Update display_name in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'users',
			[ 'display_name' => 'Updated Name' ],
			[ 'ID' => 13101 ]
		);

		$this->run_migrate_command();

		$local_user = get_user_by( 'login', 'mdcsuser2' );
		$this->assertEquals( 'Updated Name', $local_user->display_name, 'Display name should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_user_avatar_when_changed_on_live(): void {
		global $wpdb;

		// Create user with an avatar attachment.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 14001,
				'user_login' => 'avataruser1',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		// Create original and new avatar attachments.
		$original_avatar = $this->create_post_fixture(
			[
				'ID'          => 14002,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$new_avatar      = $this->create_post_fixture(
			[
				'ID'          => 14003,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $original_avatar ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $new_avatar ); // phpcs:ignore

		// Create post referencing user.
		$post = $this->create_post_fixture(
			[
				'ID'          => 14004,
				'post_author' => 14001,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Set original avatar usermeta on live.
		$wpdb->insert( $this->live_table_prefix . 'usermeta', // phpcs:ignore
			[
				'user_id'    => 14001,
				'meta_key'   => SLAHelper::AVATAR_META_KEY, // phpcs:ignore
				'meta_value' => maybe_serialize( [ 'media_id' => 14002, 'full' => 'http://live.example.com/a1.jpg', 'blog_id' => 1 ] ), // phpcs:ignore
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$local_user           = get_user_by( 'login', 'avataruser1' );
		$local_avatar         = get_user_meta( $local_user->ID, SLAHelper::AVATAR_META_KEY, true );
		$local_orig_avatar_id = $this->logic->get_current_post_id_by_old_id( 14002, $this->source_hostname );
		$this->assertIsArray( $local_avatar, 'Avatar should be set after initial import.' );
		$this->assertEquals( (int) $local_orig_avatar_id, $local_avatar['media_id'], 'Avatar media_id should match imported attachment.' );

		// Change avatar on live to new attachment.
		$wpdb->update( $this->live_table_prefix . 'usermeta', // phpcs:ignore
			[ 'meta_value' => maybe_serialize( [ 'media_id' => 14003, 'full' => 'http://live.example.com/a2.jpg', 'blog_id' => 1 ] ) ], // phpcs:ignore
			[ 'user_id' => 14001, 'meta_key' => SLAHelper::AVATAR_META_KEY ] // phpcs:ignore
		);

		$this->run_migrate_command();

		$local_avatar        = get_user_meta( $local_user->ID, SLAHelper::AVATAR_META_KEY, true );
		$local_new_avatar_id = $this->logic->get_current_post_id_by_old_id( 14003, $this->source_hostname );
		$this->assertIsArray( $local_avatar, 'Avatar should still be set after update.' );
		$this->assertEquals( (int) $local_new_avatar_id, $local_avatar['media_id'], 'Avatar media_id should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_remove_user_avatar_when_deleted_on_live(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 14101,
				'user_login' => 'avataruser2',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$avatar = $this->create_post_fixture(
			[
				'ID'          => 14102,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $avatar ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 14103,
				'post_author' => 14101,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Set avatar usermeta on live.
		$wpdb->insert( $this->live_table_prefix . 'usermeta', // phpcs:ignore
			[ 
				'user_id'    => 14101,
				'meta_key'   => SLAHelper::AVATAR_META_KEY, // phpcs:ignore
				'meta_value' => maybe_serialize( // phpcs:ignore
					[
						'media_id' => 14102,
						'full'     => 'http://live.example.com/avatar.jpg',
						'blog_id'  => 1,
					]
				), // phpcs:ignore
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$local_user   = get_user_by( 'login', 'avataruser2' );
		$local_avatar = get_user_meta( $local_user->ID, SLAHelper::AVATAR_META_KEY, true );
		$this->assertIsArray( $local_avatar, 'Avatar should be set after initial import.' );

		// Remove avatar on live.
		$wpdb->delete( $this->live_table_prefix . 'usermeta', [ 'user_id' => 14101, 'meta_key' => SLAHelper::AVATAR_META_KEY ] ); // phpcs:ignore

		$this->run_migrate_command();

		$local_avatar = get_user_meta( $local_user->ID, SLAHelper::AVATAR_META_KEY, true );
		$this->assertEmpty( $local_avatar, 'Avatar should be removed per MDCS when deleted on live.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_add_user_avatar_when_added_on_live_after_initial_import(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 14201,
				'user_login' => 'avataruser3',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$avatar = $this->create_post_fixture(
			[
				'ID'          => 14202,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $avatar ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 14203,
				'post_author' => 14201,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// No avatar set initially.
		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$local_user   = get_user_by( 'login', 'avataruser3' );
		$local_avatar = get_user_meta( $local_user->ID, SLAHelper::AVATAR_META_KEY, true );
		$this->assertEmpty( $local_avatar, 'No avatar should be set initially.' );

		// Add avatar on live.
		$wpdb->insert( $this->live_table_prefix . 'usermeta', // phpcs:ignore
			[ 
				'user_id'    => 14201,
				'meta_key'   => SLAHelper::AVATAR_META_KEY, // phpcs:ignore
				'meta_value' => maybe_serialize( [ 'media_id' => 14202, 'full' => 'http://live.example.com/avatar.jpg', 'blog_id' => 1 ] ), // phpcs:ignore
			]
		);

		$this->run_migrate_command();

		$local_avatar        = get_user_meta( $local_user->ID, SLAHelper::AVATAR_META_KEY, true );
		$local_avatar_att_id = $this->logic->get_current_post_id_by_old_id( 14202, $this->source_hostname );
		$this->assertIsArray( $local_avatar, 'Avatar should be set per MDCS when added on live.' );
		$this->assertEquals( (int) $local_avatar_att_id, $local_avatar['media_id'], 'Avatar media_id should match local attachment ID.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_not_update_user_login_when_changed_on_live_and_should_create_new_user(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 13201,
				'user_login' => 'original_login',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 13202,
				'post_author' => 13201,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Update user_login in live (not allowed by MDCS, should result in new user creation).
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'users',
			[ 'user_login' => 'changed_login' ],
			[ 'ID' => 13201 ]
		);

		$this->run_migrate_command();

		// Verify post author user_login was NOT updated.
		$new_post_id    = $this->logic->get_current_post_id_by_old_id( 13202, $this->source_hostname );
		$post_author_id = get_post_field( 'post_author', $new_post_id );
		$post_author    = get_user_by( 'ID', $post_author_id );
		$this->assertEquals( 'original_login', $post_author->user_login, 'Post author user_login should NOT be changed.' );

		// A new user was created.
		$changed_user = get_user_by( 'login', 'changed_login' );
		$this->assertNotFalse( $changed_user, 'Changed login should not exist.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_attachment_caption_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'           => 13301,
				'post_type'    => 'attachment',
				'post_status'  => 'inherit',
				'post_excerpt' => 'Original caption', // Caption is stored in post_excerpt.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 13301, $this->source_hostname );

		// Update caption in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_excerpt' => 'Updated caption' ],
			[ 'ID' => 13301 ]
		);

		$this->run_migrate_command();

		$local_attachment = get_post( $new_attachment_id );
		$this->assertEquals( 'Updated caption', $local_attachment->post_excerpt, 'Attachment caption should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_attachment_description_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'           => 13401,
				'post_type'    => 'attachment',
				'post_status'  => 'inherit',
				'post_content' => 'Original description',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 13401, $this->source_hostname );

		// Update description in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[ 'post_content' => 'Updated description' ],
			[ 'ID' => 13401 ]
		);

		$this->run_migrate_command();

		$local_attachment = get_post( $new_attachment_id );
		$this->assertEquals( 'Updated description', $local_attachment->post_content, 'Attachment description should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_attachment_alt_text_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 13501,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 13501,
				'meta_key'   => '_wp_attachment_image_alt',
				'meta_value' => 'Original alt', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 13501, $this->source_hostname );

		// Update alt text in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[ 'meta_value' => 'Updated alt' ], // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			[
				'post_id'  => 13501,
				'meta_key' => '_wp_attachment_image_alt',
			]
		);

		$this->run_migrate_command();

		$alt = get_post_meta( $new_attachment_id, '_wp_attachment_image_alt', true );
		$this->assertEquals( 'Updated alt', $alt, 'Attachment alt text should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_attachment_media_credit_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 13601,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 13601,
				'meta_key'   => '_media_credit',
				'meta_value' => 'Original Credit', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 13601, $this->source_hostname );

		// Update media credit in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[ 'meta_value' => 'Updated Credit' ], // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			[
				'post_id'  => 13601,
				'meta_key' => '_media_credit',
			]
		);

		$this->run_migrate_command();

		$credit = get_post_meta( $new_attachment_id, '_media_credit', true );
		$this->assertEquals( 'Updated Credit', $credit, 'Attachment media credit should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_term_slug_when_changed_on_live(): void {
		global $wpdb;

		// Create post with category.
		$post = $this->create_post_fixture( [ 'ID' => 13701 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 13702,
			'name'       => 'MDCS Term',
			'slug'       => 'original-slug',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 13702,
			'term_id'          => 13702,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 13701,
				'term_taxonomy_id' => 13702,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Update slug in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'terms',
			[ 'slug' => 'updated-slug' ],
			[ 'term_id' => 13702 ]
		);

		$this->run_migrate_command();

		// Verify slug was updated.
		$term = get_term_by( 'slug', 'updated-slug', 'category' );
		$this->assertNotFalse( $term, 'Term slug should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_term_description_when_changed_on_live(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 13801 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 13802,
			'name'       => 'Desc Term',
			'slug'       => 'desc-term',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 13802,
			'term_id'          => 13802,
			'taxonomy'         => 'category',
			'description'      => 'Original description',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 13801,
				'term_taxonomy_id' => 13802,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Update description in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_taxonomy',
			[ 'description' => 'Updated description' ],
			[ 'term_taxonomy_id' => 13802 ]
		);

		$this->run_migrate_command();

		$term = get_term_by( 'slug', 'desc-term', 'category' );
		$this->assertEquals( 'Updated description', $term->description, 'Term description should be updated per MDCS.' );
	}

	/**
	 * @group migration-data-consistency-standard
	 */
	public function test_should_not_update_term_name_when_changed_on_live(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 13901 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 13902,
			'name'       => 'Original Name',
			'slug'       => 'name-term',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 13902,
			'term_id'          => 13902,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 13901,
				'term_taxonomy_id' => 13902,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Update name in live (not allowed by MDCS).
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'terms',
			[ 'name' => 'Changed Name' ],
			[ 'term_id' => 13902 ]
		);

		$this->run_migrate_command();

		// Name should NOT be updated.
		$term = get_term_by( 'slug', 'name-term', 'category' );
		$this->assertEquals( 'Original Name', $term->name, 'Term name should NOT be changed per MDCS.' );
	}


	/**
	 * Tests that pages are NOT detected as modified even when their fields change.
	 *
	 * Per the Migration Data Consistency Standard: "Pages only get imported once during the first import
	 * (and new pages on consecutive migration runs), but existing (already migrated) pages and their fields
	 * do not get updated later on (even if they change on live)."
	 *
	 * @group posts-modified
	 */
	public function test_should_not_detect_pages_as_modified_per_standard(): void {
		global $wpdb;

		// Import a page.
		$page = $this->create_post_fixture(
			[
				'ID'            => 4050,
				'post_type'     => 'page',
				'post_title'    => 'Original Page Title',
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $page ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,page,attachment' ] );
		$this->run_migrate_command();

		$original_page_id = $this->logic->get_current_post_id_by_old_id( 4050, $this->source_hostname );
		$this->assertNotNull( $original_page_id, 'Page should be imported.' );
		$original_title = get_post( $original_page_id )->post_title;

		// Modify the page in live (change title and post_modified).
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Page Title',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4050 ]
		);

		// Run search again.
		$this->run_search_command( [ 'post-types-csv' => 'post,page,attachment' ] );

		// Page should NOT be in modified IDs.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayNotHasKey( 4050, $modified_ids, 'Page should NOT be detected as modified per the standard.' );

		// Run migrate to verify page is not reimported.
		$this->run_migrate_command();

		// Page should still have same local ID (not reimported).
		$page_id_after = $this->logic->get_current_post_id_by_old_id( 4050, $this->source_hostname );
		$this->assertEquals( $original_page_id, $page_id_after, 'Page should not be reimported.' );

		// Title should remain unchanged (original value).
		$title_after = get_post( $page_id_after )->post_title;
		$this->assertEquals( $original_title, $title_after, 'Page title should not be updated.' );
	}

	/**
	 * Tests that posts ARE detected as modified while pages in the same run are NOT.
	 *
	 * @group posts-modified
	 */
	public function test_should_detect_modified_posts_but_not_pages_in_same_run(): void {
		global $wpdb;

		// Import both a post and a page.
		$post = $this->create_post_fixture(
			[
				'ID'            => 4051,
				'post_type'     => 'post',
				'post_title'    => 'Original Post',
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$page = $this->create_post_fixture(
			[
				'ID'            => 4052,
				'post_type'     => 'page',
				'post_title'    => 'Original Page',
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $page ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,page,attachment' ] );
		$this->run_migrate_command();

		// Modify both in live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Post',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4051 ]
		);
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Page',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4052 ]
		);

		// Run search.
		$this->run_search_command( [ 'post-types-csv' => 'post,page,attachment' ] );

		$modified_ids = $this->run_state->get_modified_ids_map();

		// Post should be detected as modified.
		$this->assertArrayHasKey( 4051, $modified_ids, 'Post should be detected as modified.' );

		// Page should NOT be detected as modified.
		$this->assertArrayNotHasKey( 4052, $modified_ids, 'Page should NOT be detected as modified.' );
	}

	/**
	 * Tests that post title is NOT updated when changed on live (identifier field).
	 *
	 * Per the Migration Data Consistency Standard: Title is an identifier field.
	 * Once a post is imported (has old_id meta), changes to title are ignored.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_not_update_post_title_when_changed_on_live(): void {
		global $wpdb;

		// Create and import a post.
		$post = $this->create_post_fixture(
			[
				'ID'         => 15001,
				'post_title' => 'Original Title',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_post_id = $this->logic->get_current_post_id_by_old_id( 15001, $this->source_hostname );
		$this->assertNotNull( $local_post_id, 'Post should be imported.' );
		$this->assertEquals( 'Original Title', get_post( $local_post_id )->post_title );

		// Change title on live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[ 'post_title' => 'Changed Title' ],
			[ 'ID' => 15001 ]
		);

		// Run migration again.
		$this->run_search_command();
		$this->run_migrate_command();

		// Title should NOT be updated (identifier field is ignored once imported).
		$this->assertEquals( 'Original Title', get_post( $local_post_id )->post_title, 'Post title should NOT be changed per MDCS (identifier field).' );
	}

	/**
	 * Tests that post slug is NOT updated when changed on live (identifier field).
	 *
	 * Per the Migration Data Consistency Standard: Slug is an identifier field.
	 * Once a post is imported (has old_id meta), changes to slug are ignored.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_not_update_post_slug_when_changed_on_live(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'        => 15101,
				'post_name' => 'original-slug',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_post_id = $this->logic->get_current_post_id_by_old_id( 15101, $this->source_hostname );
		$this->assertNotNull( $local_post_id, 'Post should be imported.' );
		$this->assertEquals( 'original-slug', get_post( $local_post_id )->post_name );

		// Change slug on live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[ 'post_name' => 'changed-slug' ],
			[ 'ID' => 15101 ]
		);

		// Run migration again.
		$this->run_search_command();
		$this->run_migrate_command();

		// Slug should NOT be updated (identifier field is ignored once imported).
		$this->assertEquals( 'original-slug', get_post( $local_post_id )->post_name, 'Post slug should NOT be changed per MDCS (identifier field).' );
	}

	/**
	 * Tests that post date published is NOT updated when changed on live (identifier field).
	 *
	 * Per the Migration Data Consistency Standard: Date published is an identifier field.
	 * Once a post is imported (has old_id meta), changes to date are ignored.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_not_update_post_date_published_when_changed_on_live(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 15201,
				'post_date'     => '2024-01-15 10:00:00',
				'post_date_gmt' => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_post_id = $this->logic->get_current_post_id_by_old_id( 15201, $this->source_hostname );
		$this->assertNotNull( $local_post_id, 'Post should be imported.' );
		$this->assertEquals( '2024-01-15 10:00:00', get_post( $local_post_id )->post_date );

		// Change date published on live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_date'     => '2025-06-01 12:00:00',
				'post_date_gmt' => '2025-06-01 12:00:00',
			],
			[ 'ID' => 15201 ]
		);

		// Run migration again.
		$this->run_search_command();
		$this->run_migrate_command();

		// Date should NOT be updated (identifier field is ignored once imported).
		$this->assertEquals( '2024-01-15 10:00:00', get_post( $local_post_id )->post_date, 'Post date should NOT be changed per MDCS (identifier field).' );
	}

	/**
	 * Tests that custom CPTs are detected as modified (same rules as posts).
	 *
	 * Per the Migration Data Consistency Standard: Custom post types follow the same rules as posts.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_detect_custom_cpt_as_modified_when_post_modified_changes(): void {
		global $wpdb;

		// Register custom post type.
		register_post_type( 'custom_cpt', [ 'public' => true ] );

		// Create and import a CPT post.
		$cpt_post = $this->create_post_fixture(
			[
				'ID'            => 15301,
				'post_type'     => 'custom_cpt',
				'post_title'    => 'Original CPT Title',
				'post_content'  => 'Original CPT Content',
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $cpt_post ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,custom_cpt' ] );
		$this->run_migrate_command();

		$local_post_id = $this->logic->get_current_post_id_by_old_id( 15301, $this->source_hostname );
		$this->assertNotNull( $local_post_id, 'CPT post should be imported.' );
		$this->assertEquals( 'Original CPT Content', get_post( $local_post_id )->post_content );

		// Modify the CPT on live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_content'      => 'Updated CPT Content',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 15301 ]
		);

		// Run search again.
		$this->run_search_command( [ 'post-types-csv' => 'post,custom_cpt' ] );

		// CPT should be detected as modified.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 15301, $modified_ids, 'Custom CPT should be detected as modified per MDCS.' );

		// Run migrate to verify content is updated.
		$this->run_migrate_command();
		$this->assertEquals( 'Updated CPT Content', get_post( $local_post_id )->post_content, 'CPT content should be updated after reimport.' );
	}

	/**
	 * Tests that new pages are NOT imported on consecutive migration runs.
	 *
	 * Per the Migration Data Consistency Standard: Pages are migrated only once during
	 * the first migration run. New pages do NOT get migrated during consecutive runs.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_not_import_new_pages_on_consecutive_runs(): void {
		global $wpdb;

		// First run: import initial page.
		$initial_page = $this->create_post_fixture(
			[
				'ID'         => 15401,
				'post_type'  => 'page',
				'post_title' => 'Initial Page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $initial_page ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,page' ] );
		$this->run_migrate_command();

		$initial_page_id = $this->logic->get_current_post_id_by_old_id( 15401, $this->source_hostname );
		$this->assertNotNull( $initial_page_id, 'Initial page should be imported on first run.' );

		// Add a NEW page on live after initial migration.
		$new_page = $this->create_post_fixture(
			[
				'ID'         => 15402,
				'post_type'  => 'page',
				'post_title' => 'New Page After Initial Migration',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $new_page ); // phpcs:ignore

		// Run search again (consecutive run).
		$this->run_search_command( [ 'post-types-csv' => 'post,page' ] );

		// New page should NOT be in the list of new IDs.
		$new_ids = $this->run_state->get_new_ids();
		$this->assertNotContains( 15402, $new_ids, 'New page should NOT be detected on consecutive runs per MDCS.' );

		// Run migrate to verify page is not imported.
		$this->run_migrate_command();

		$new_page_id = $this->logic->get_current_post_id_by_old_id( 15402, $this->source_hostname );
		$this->assertNull( $new_page_id, 'New page should NOT be imported on consecutive runs per MDCS.' );
	}

	/**
	 * Tests that category parent is NOT updated when changed on live (identifier field).
	 *
	 * Per the Migration Data Consistency Standard: Parent is an identifier field for categories.
	 * Once a category is imported (has old_id termmeta), changes to parent are ignored.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_not_update_category_parent_when_changed_on_live(): void {
		global $wpdb;

		// Create parent category.
		$parent_term = [
			'term_id'    => 15501,
			'name'       => 'Parent Category',
			'slug'       => 'parent-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $parent_term ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 15501,
				'term_id'          => 15501,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		// Create child category with no parent initially.
		$child_term = [
			'term_id'    => 15502,
			'name'       => 'Child Category',
			'slug'       => 'child-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $child_term ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 15502,
				'term_id'          => 15502,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0, // No parent initially.
				'count'            => 1,
			]
		);

		// Create post with child category.
		$post = $this->create_post_fixture( [ 'ID' => 15503 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 15503,
				'term_taxonomy_id' => 15502,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify child category was imported with no parent.
		$local_child_term = get_term_by( 'slug', 'child-category', 'category' );
		$this->assertNotFalse( $local_child_term, 'Child category should be imported.' );
		$this->assertEquals( 0, $local_child_term->parent, 'Child category should have no parent initially.' );

		// Change parent on live (set parent to 15501).
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[ 'parent' => 15501 ],
			[ 'term_taxonomy_id' => 15502 ]
		);

		// Run migration again.
		$this->run_migrate_command();

		// Parent should NOT be updated (identifier field is ignored once imported).
		$local_child_term = get_term_by( 'slug', 'child-category', 'category' );
		$this->assertEquals( 0, $local_child_term->parent, 'Category parent should NOT be changed per MDCS (identifier field).' );
	}

	/**
	 * Tests that attachment credit URL is updated when changed on live.
	 *
	 * Per the Migration Data Consistency Standard: Credit URL is an updateable field.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_update_attachment_credit_url_when_changed_on_live(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 15601,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 15601,
				'meta_key'   => '_media_credit_url',
				'meta_value' => 'https://original-credit.example.com', // phpcs:ignore
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 15601, $this->source_hostname );
		$credit_url        = get_post_meta( $new_attachment_id, '_media_credit_url', true );
		$this->assertEquals( 'https://original-credit.example.com', $credit_url );

		// Update credit URL on live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'postmeta',
			[ 'meta_value' => 'https://updated-credit.example.com' ], // phpcs:ignore
			[
				'post_id'  => 15601,
				'meta_key' => '_media_credit_url',
			]
		);

		$this->run_migrate_command();

		$credit_url = get_post_meta( $new_attachment_id, '_media_credit_url', true );
		$this->assertEquals( 'https://updated-credit.example.com', $credit_url, 'Attachment credit URL should be updated per MDCS.' );
	}

	/**
	 * Tests that posts are detected as modified to be fully reimported when post_modified date changes.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_filter_modified_posts_when_post_modified_date_changed(): void {
		global $wpdb;

		// Initial import.
		$post = $this->create_post_fixture(
			[
				'ID'            => 4001,
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_post_id = $this->logic->get_current_post_id_by_old_id( 4001, $this->source_hostname );
		$this->assertNotNull( $original_post_id, 'Post should be imported.' );

		// Update post_modified in live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
				'post_title'        => 'Updated Title',
			],
			[ 'ID' => 4001 ]
		);

		// Run search again - should detect as modified.
		$this->run_search_command();

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertNotEmpty( $modified_ids, 'Modified IDs should be detected.' );
		$this->assertArrayHasKey( 4001, $modified_ids, 'Post 4001 should be detected as modified.' );
	}

	/**
	 * Tests that posts are detected as modified when post_status changes.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_filter_modified_posts_when_post_status_changed(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'          => 4002,
				'post_status' => 'publish',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Change status to draft in live.
		$wpdb->update( $this->live_table_prefix . 'posts', [ 'post_status' => 'draft' ], [ 'ID' => 4002 ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post' ] );

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4002, $modified_ids, 'Post should be detected as modified when status changes.' );
	}

	/**
	 * Tests that posts are detected as modified when post_author changes.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_filter_modified_posts_when_post_author_changed(): void {
		global $wpdb;

		// Create two users.
		$user1 = $this->create_user_fixture(
			[
				'ID'         => 4101,
				'user_login' => 'author_orig',
			] 
		);
		$user2 = $this->create_user_fixture(
			[
				'ID'         => 4102,
				'user_login' => 'author_new',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $user1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'users', $user2 ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 4003,
				'post_author' => 4101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Change author in live.
		$wpdb->update( $this->live_table_prefix . 'posts', [ 'post_author' => 4102 ], [ 'ID' => 4003 ] ); // phpcs:ignore

		$this->run_search_command();

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4003, $modified_ids, 'Post should be detected as modified when author changes.' );
	}

	/**
	 * Tests that posts are detected as modified when thumbnail_id changes.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_filter_modified_posts_when_thumbnail_id_changed(): void {
		global $wpdb;

		// Create two attachments.
		$att1 = $this->create_post_fixture(
			[
				'ID'          => 4201,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$att2 = $this->create_post_fixture(
			[
				'ID'          => 4202,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $att1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $att2 ); // phpcs:ignore

		$post = $this->create_post_fixture( [ 'ID' => 4004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 4001, 'post_id' => 4004, 'meta_key' => '_thumbnail_id', 'meta_value' => '4201' ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		// Change thumbnail in live.
		$wpdb->update( $this->live_table_prefix . 'postmeta', [ 'meta_value' => '4202' ], [ 'post_id' => 4004, 'meta_key' => '_thumbnail_id' ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4004, $modified_ids, 'Post should be detected as modified when thumbnail changes.' );
	}

	/**
	 * Tests that posts are detected as modified when thumbnail_id is added on live
	 * (post had no thumbnail initially, then gets one).
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_filter_modified_posts_when_thumbnail_id_did_not_exist_initially_and_was_later_added_on_live(): void {
		global $wpdb;

		// Create attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 4210,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		// Create post WITHOUT a thumbnail.
		$post = $this->create_post_fixture( [ 'ID' => 4211 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		// Note: No _thumbnail_id postmeta inserted.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		// Verify post was imported without a thumbnail.
		$local_post_id = $this->logic->get_current_post_id_by_old_id( 4211, $this->source_hostname );
		$this->assertNotNull( $local_post_id, 'Post should be imported.' );
		$this->assertEmpty( get_post_meta( $local_post_id, '_thumbnail_id', true ), 'Post should have no thumbnail initially.' );

		// Now ADD a thumbnail on live (simulating editor adding featured image after initial migration).
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 4210, 'post_id' => 4211, 'meta_key' => '_thumbnail_id', 'meta_value' => '4210' ] ); // phpcs:ignore

		// Fresh run-state for new migration cycle.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4211, $modified_ids, 'Post should be detected as modified when thumbnail is added on live.' );
	}

	/**
	 * Tests that posts are detected as modified when taxonomies change.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_filter_modified_posts_when_taxonomies_changed(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 4005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create initial category.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 4301, 'name' => 'Cat A', 'slug' => 'cat-a', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 4301, 'term_id' => 4301, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 4005, 'term_taxonomy_id' => 4301 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Add new category in live.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 4302, 'name' => 'Cat B', 'slug' => 'cat-b', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 4302, 'term_id' => 4302, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 4005, 'term_taxonomy_id' => 4302 ] ); // phpcs:ignore

		$this->run_search_command();

		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4005, $modified_ids, 'Post should be detected as modified when taxonomies change.' );
	}

	/**
	 * Tests that reimporting updates post content correctly.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_reimport_should_update_post_content(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4014,
				'post_title'    => 'Content Test Post',
				'post_content'  => '<p>Original content paragraph.</p>',
				'post_excerpt'  => 'Original excerpt.',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4014, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		$original_post = get_post( $original_local_id );
		$this->assertStringContainsString( 'Original content paragraph', $original_post->post_content );
		$this->assertEquals( 'Original excerpt.', $original_post->post_excerpt );

		// Modify content in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_content'      => '<p>Updated content with new information.</p><!-- wp:paragraph --><p>And a block.</p><!-- /wp:paragraph -->',
				'post_excerpt'      => 'Updated excerpt with more detail.',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4014 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4014, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );
		$this->assertEquals( $original_local_id, $new_local_id, 'Post should preserve its local ID.' );

		$new_post = get_post( $new_local_id );
		$this->assertStringContainsString( 'Updated content with new information', $new_post->post_content );
		$this->assertStringContainsString( 'And a block', $new_post->post_content );
		$this->assertEquals( 'Updated excerpt with more detail.', $new_post->post_excerpt );
	}

	/**
	 * Tests reimporting with post meta preserved.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_reimport_should_include_updated_post_meta(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4040,
				'post_title'    => 'Meta Test Post',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add post meta.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'meta_id'    => 40001,
				'post_id'    => 4040,
				'meta_key'   => 'custom_meta_key',
				'meta_value' => 'original_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4040, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		$original_meta = get_post_meta( $original_local_id, 'custom_meta_key', true );
		$this->assertEquals( 'original_value', $original_meta, 'Original meta should be imported.' );

		// Modify post and meta in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Meta Test Post Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4040 ]
		); // phpcs:ignore

		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[ 'meta_value' => 'updated_value' ], // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			[
				'post_id'  => 4040,
				'meta_key' => 'custom_meta_key', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_key.
			]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4040, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );
		$this->assertEquals( $original_local_id, $new_local_id, 'Post should preserve its local ID.' );

		$new_meta = get_post_meta( $new_local_id, 'custom_meta_key', true );
		$this->assertEquals( 'updated_value', $new_meta, 'Reimported post should have updated meta.' );
	}

	/**
	 * Tests that reimported posts preserve the old_id meta pointing to the live ID.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_reimport_should_preserve_old_id_meta(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 4011,
				'post_title'    => 'Original Title',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_local_id = $this->logic->get_current_post_id_by_old_id( 4011, $this->source_hostname );
		$this->assertNotNull( $original_local_id, 'Post should be imported.' );

		// Verify old_id meta exists on original import.
		$meta_key        = $this->logic->get_old_id_meta_key( $this->source_hostname );
		$original_old_id = get_post_meta( $original_local_id, $meta_key, true );
		$this->assertEquals( 4011, (int) $original_old_id, 'Original post should have old_id meta.' );

		// Modify in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Title',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4011 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify reimported post has old_id meta.
		$new_local_id = $this->logic->get_current_post_id_by_old_id( 4011, $this->source_hostname );
		$this->assertNotNull( $new_local_id, 'Post should be reimported.' );
		$this->assertEquals( $original_local_id, $new_local_id, 'Post should preserve its local ID.' );

		$new_old_id = get_post_meta( $new_local_id, $meta_key, true );
		$this->assertEquals( 4011, (int) $new_old_id, 'Reimported post should preserve old_id meta.' );
	}

	/**
	 * Tests that reimporting a post with a featured image correctly updates the _thumbnail_id.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_reimport_should_update_featured_image_id(): void {
		global $wpdb;

		// Create attachment in live.
		$attachment = [
			'ID'                    => 4050,
			'post_author'           => 1,
			'post_date'             => '2024-01-01 10:00:00',
			'post_date_gmt'         => '2024-01-01 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Featured Image',
			'post_excerpt'          => '',
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'featured-image',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-01 10:00:00',
			'post_modified_gmt'     => '2024-01-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/wp-content/uploads/featured.jpg',
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => 'image/jpeg',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		// Create post with featured image in live.
		$post = $this->create_post_fixture(
			[
				'ID'            => 4051,
				'post_title'    => 'Post With Featured Image',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add _thumbnail_id meta pointing to attachment.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'meta_id'    => 40501,
				'post_id'    => 4051,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => '4050', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_post_local_id       = $this->logic->get_current_post_id_by_old_id( 4051, $this->source_hostname );
		$original_attachment_local_id = $this->logic->get_current_post_id_by_old_id( 4050, $this->source_hostname );
		$this->assertNotNull( $original_post_local_id, 'Post should be imported.' );
		$this->assertNotNull( $original_attachment_local_id, 'Attachment should be imported.' );

		// Verify featured image was set correctly.
		$original_thumbnail_id = get_post_meta( $original_post_local_id, '_thumbnail_id', true );
		$this->assertEquals( $original_attachment_local_id, (int) $original_thumbnail_id, 'Featured image should point to local attachment.' );

		// Modify post in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Post With Featured Image Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4051 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was reimported.
		$new_post_local_id = $this->logic->get_current_post_id_by_old_id( 4051, $this->source_hostname );
		$this->assertNotNull( $new_post_local_id, 'Post should be reimported.' );
		$this->assertEquals( $original_post_local_id, $new_post_local_id, 'Post should preserve its local ID.' );

		// Verify featured image still points to correct local attachment (not the old live ID).
		$new_thumbnail_id = get_post_meta( $new_post_local_id, '_thumbnail_id', true );
		$this->assertEquals( $original_attachment_local_id, (int) $new_thumbnail_id, 'Reimported post featured image should point to local attachment ID.' );
	}

	/**
	 * Tests that when a post is reimported after a comment is deleted on live,
	 * the reimported post no longer has that comment.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_remove_comment_when_reimporting_modified_post_with_comment_deleted_on_live(): void {
		global $wpdb;

		// Create post with a comment.
		$post = $this->create_post_fixture(
			[
				'ID'            => 8001,
				'post_modified' => '2024-01-01 10:00:00',
				'comment_count' => '2',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create comment.
		$comment = [
			'comment_ID'           => 8101,
			'comment_post_ID'      => 8001,
			'comment_author'       => 'Test Commenter',
			'comment_author_email' => 'commenter@example.com',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2024-01-01 12:00:00',
			'comment_date_gmt'     => '2024-01-01 12:00:00',
			'comment_content'      => 'This comment will be deleted.',
			'comment_karma'        => 0,
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'comments', $comment ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 8001, $this->source_hostname );
		$comments    = get_comments( [ 'post_id' => $new_post_id ] );
		$this->assertCount( 1, $comments, 'Post should have 1 comment after initial import.' );
		$this->assertEquals( 'This comment will be deleted.', $comments[0]->comment_content );

		// Delete comment on live and modify post.
		$wpdb->delete( $this->live_table_prefix . 'comments', [ 'comment_ID' => 8101 ] ); // phpcs:ignore
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 8001 ]
		);

		// Fresh run-state for new migration cycle.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify comment was removed after reimport.
		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( 8001, $this->source_hostname );
		$comments_after     = get_comments( [ 'post_id' => $reimported_post_id ] );
		$this->assertCount( 0, $comments_after, 'Post should have no comments after reimport with comment deleted on live.' );
	}

	/**
	 * Tests that when a post is reimported after a postmeta entry is deleted on live,
	 * the reimported post no longer has that postmeta.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_remove_postmeta_when_reimporting_modified_post_with_meta_deleted_on_live(): void {
		global $wpdb;

		// Create post with custom meta.
		$post = $this->create_post_fixture(
			[
				'ID'            => 15001,
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add postmeta.
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'postmeta',
			[
				'meta_id'    => 15101,
				'post_id'    => 15001,
				'meta_key'   => 'custom_meta_to_delete',
				'meta_value' => 'This meta will be deleted.', // phpcs:ignore
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 15001, $this->source_hostname );
		$meta_value  = get_post_meta( $new_post_id, 'custom_meta_to_delete', true );
		$this->assertEquals( 'This meta will be deleted.', $meta_value, 'Meta should exist after initial import.' );

		// Delete meta on live and modify post.
		$wpdb->delete( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 15101 ] ); // phpcs:ignore
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 15001 ]
		);

		// Fresh run-state for new migration cycle.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify meta was removed after reimport.
		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( 15001, $this->source_hostname );
		$meta_after         = get_post_meta( $reimported_post_id, 'custom_meta_to_delete', true );
		$this->assertEmpty( $meta_after, 'Meta should be removed after reimport with meta deleted on live.' );
	}

	/**
	 * Tests that reimporting a post with a parent correctly updates parent relationships.
	 *
	 * @group posts-modified
	 */
	public function test_reimport_post_with_parent_should_update_parent_correctly(): void {
		global $wpdb;

		// Create parent post.
		$parent_post = $this->create_post_fixture(
			[
				'ID'            => 4012,
				'post_title'    => 'Parent Post',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent_post ); // phpcs:ignore

		// Create child post with parent reference.
		$child_post = $this->create_post_fixture(
			[
				'ID'            => 4013,
				'post_title'    => 'Child Post',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 4012,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child_post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$parent_local_id = $this->logic->get_current_post_id_by_old_id( 4012, $this->source_hostname );
		$child_local_id  = $this->logic->get_current_post_id_by_old_id( 4013, $this->source_hostname );
		$this->assertNotNull( $parent_local_id, 'Parent should be imported.' );
		$this->assertNotNull( $child_local_id, 'Child should be imported.' );

		// Verify initial parent relationship.
		$child_post_obj = get_post( $child_local_id );
		$this->assertEquals( $parent_local_id, (int) $child_post_obj->post_parent, 'Child should reference parent.' );

		// Modify child in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Child Post',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4013 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify child was reimported and still references parent.
		$new_child_local_id = $this->logic->get_current_post_id_by_old_id( 4013, $this->source_hostname );
		$this->assertNotNull( $new_child_local_id, 'Child should be reimported.' );
		$this->assertEquals( $child_local_id, $new_child_local_id, 'Child should preserve its local ID.' );

		$new_child_post_obj = get_post( $new_child_local_id );
		$this->assertEquals( $parent_local_id, (int) $new_child_post_obj->post_parent, 'Reimported child should still reference parent.' );
		$this->assertEquals( 'Modified Child Post', $new_child_post_obj->post_title, 'Reimported child should have updated title.' );
	}

	/**
	 * Tests reimporting a child post when the parent was also modified.
	 *
	 * @group posts-modified
	 */
	public function test_reimport_parent_and_child_together(): void {
		global $wpdb;

		// Create parent and child.
		$parent_post = $this->create_post_fixture(
			[
				'ID'            => 4030,
				'post_title'    => 'Parent Original',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent_post ); // phpcs:ignore

		$child_post = $this->create_post_fixture(
			[
				'ID'            => 4031,
				'post_title'    => 'Child Original',
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 4030,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child_post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_parent_local_id = $this->logic->get_current_post_id_by_old_id( 4030, $this->source_hostname );
		$original_child_local_id  = $this->logic->get_current_post_id_by_old_id( 4031, $this->source_hostname );
		$this->assertNotNull( $original_parent_local_id, 'Parent should be imported.' );
		$this->assertNotNull( $original_child_local_id, 'Child should be imported.' );

		// Modify both parent and child in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Parent Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4030 ]
		); // phpcs:ignore

		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Child Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4031 ]
		); // phpcs:ignore

		$this->run_search_command();

		// Verify both are detected as modified.
		$modified_ids = $this->run_state->get_modified_ids_map();
		$this->assertArrayHasKey( 4030, $modified_ids, 'Parent should be detected as modified.' );
		$this->assertArrayHasKey( 4031, $modified_ids, 'Child should be detected as modified.' );

		$this->run_migrate_command();

		// Verify both were reimported.
		$new_parent_local_id = $this->logic->get_current_post_id_by_old_id( 4030, $this->source_hostname );
		$new_child_local_id  = $this->logic->get_current_post_id_by_old_id( 4031, $this->source_hostname );
		$this->assertNotNull( $new_parent_local_id, 'Parent should be reimported.' );
		$this->assertNotNull( $new_child_local_id, 'Child should be reimported.' );
		$this->assertEquals( $original_parent_local_id, $new_parent_local_id, 'Parent should preserve its local ID.' );
		$this->assertEquals( $original_child_local_id, $new_child_local_id, 'Child should preserve its local ID.' );

		// Verify titles updated.
		$this->assertEquals( 'Parent Modified', get_the_title( $new_parent_local_id ), 'Parent should have updated title.' );
		$this->assertEquals( 'Child Modified', get_the_title( $new_child_local_id ), 'Child should have updated title.' );

		// Verify child still references the new parent.
		$new_child_post = get_post( $new_child_local_id );
		$this->assertEquals( $new_parent_local_id, (int) $new_child_post->post_parent, 'Child should reference new parent ID.' );
	}

	// =========================================================================
	// MDCS TRACKING TESTS - USERS
	// =========================================================================

	/**
	 * Tests that user email update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_user_email_update_as_modified_in_runstate(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 25001,
				'user_login' => 'email_track_user_' . uniqid(),
				'user_email' => 'original@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 25002,
				'post_author' => 25001,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Update email in live.
		$wpdb->update( $this->live_table_prefix . 'users', [ 'user_email' => 'updated@test.local' ], [ 'ID' => 25001 ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify user is tracked as modified.
		$imported_users = $this->run_state->read_imported_users();
		$found_modified = false;
		foreach ( $imported_users as $user ) {
			if ( 25001 === (int) $user['id_old'] && 'modified' === $user['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'User with email update should be tracked as modified.' );
	}

	/**
	 * Tests that user display_name update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_user_display_name_update_as_modified_in_runstate(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'           => 25101,
				'user_login'   => 'display_track_user_' . uniqid(),
				'display_name' => 'Original Name',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 25102,
				'post_author' => 25101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Update display_name in live.
		$wpdb->update( $this->live_table_prefix . 'users', [ 'display_name' => 'Updated Name' ], [ 'ID' => 25101 ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify user is tracked as modified.
		$imported_users = $this->run_state->read_imported_users();
		$found_modified = false;
		foreach ( $imported_users as $user ) {
			if ( 25101 === (int) $user['id_old'] && 'modified' === $user['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'User with display_name update should be tracked as modified.' );
	}

	/**
	 * Tests that user avatar update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_user_avatar_update_as_modified_in_runstate(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 25201,
				'user_login' => 'avatar_track_user_' . uniqid(),
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore

		$avatar1 = $this->create_post_fixture(
			[
				'ID'          => 25202,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$avatar2 = $this->create_post_fixture(
			[
				'ID'          => 25203,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $avatar1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $avatar2 ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 25204,
				'post_author' => 25201,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Set initial avatar.
		$wpdb->insert( $this->live_table_prefix . 'usermeta', // phpcs:ignore
			[
				'user_id'    => 25201,
				'meta_key'   => SLAHelper::AVATAR_META_KEY, // phpcs:ignore
				'meta_value' => maybe_serialize( [ 'media_id' => 25202, 'full' => 'http://test.local/a1.jpg', 'blog_id' => 1 ] ), // phpcs:ignore
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		// Update avatar in live.
		$wpdb->update( $this->live_table_prefix . 'usermeta', // phpcs:ignore
			[ 'meta_value' => maybe_serialize( [ 'media_id' => 25203, 'full' => 'http://test.local/a2.jpg', 'blog_id' => 1 ] ) ], // phpcs:ignore
			[ 'user_id' => 25201, 'meta_key' => SLAHelper::AVATAR_META_KEY ] // phpcs:ignore
		);

		$this->run_migrate_command();

		// Verify user is tracked as modified.
		$imported_users = $this->run_state->read_imported_users();
		$found_modified = false;
		foreach ( $imported_users as $user ) {
			if ( 25201 === (int) $user['id_old'] && 'modified' === $user['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'User with avatar update should be tracked as modified.' );
	}

	// =========================================================================
	// MDCS TRACKING TESTS - ATTACHMENTS
	// =========================================================================

	/**
	 * Tests that attachment caption update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_attachment_caption_update_as_modified_in_runstate(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'           => 26001,
				'post_type'    => 'attachment',
				'post_status'  => 'inherit',
				'post_excerpt' => 'Original caption',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		// Update caption in live.
		$wpdb->update( $this->live_table_prefix . 'posts', [ 'post_excerpt' => 'Updated caption' ], [ 'ID' => 26001 ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify attachment is tracked as modified.
		$imported_posts = $this->run_state->read_imported_posts();
		$found_modified = false;
		foreach ( $imported_posts as $post ) {
			if ( 26001 === (int) $post['id_old'] && 'modified' === $post['status'] && 'attachment' === $post['post_type'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'Attachment with caption update should be tracked as modified.' );
	}

	/**
	 * Tests that attachment alt text update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_attachment_alt_text_update_as_modified_in_runstate(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 26101,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'post_id' => 26101, 'meta_key' => '_wp_attachment_image_alt', 'meta_value' => 'Original alt' ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		// Update alt text in live.
		$wpdb->update( $this->live_table_prefix . 'postmeta', [ 'meta_value' => 'Updated alt' ], [ 'post_id' => 26101, 'meta_key' => '_wp_attachment_image_alt' ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify attachment is tracked as modified.
		$imported_posts = $this->run_state->read_imported_posts();
		$found_modified = false;
		foreach ( $imported_posts as $post ) {
			if ( 26101 === (int) $post['id_old'] && 'modified' === $post['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'Attachment with alt text update should be tracked as modified.' );
	}

	/**
	 * Tests that attachment description update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_attachment_description_update_as_modified_in_runstate(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'           => 26201,
				'post_type'    => 'attachment',
				'post_status'  => 'inherit',
				'post_content' => 'Original description',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		// Update description in live.
		$wpdb->update( $this->live_table_prefix . 'posts', [ 'post_content' => 'Updated description' ], [ 'ID' => 26201 ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify attachment is tracked as modified.
		$imported_posts = $this->run_state->read_imported_posts();
		$found_modified = false;
		foreach ( $imported_posts as $post ) {
			if ( 26201 === (int) $post['id_old'] && 'modified' === $post['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'Attachment with description update should be tracked as modified.' );
	}

	/**
	 * Tests that attachment media credit update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_attachment_media_credit_update_as_modified_in_runstate(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 26301,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'post_id' => 26301, 'meta_key' => '_media_credit', 'meta_value' => 'Original Credit' ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		// Update media credit in live.
		$wpdb->update( $this->live_table_prefix . 'postmeta', [ 'meta_value' => 'Updated Credit' ], [ 'post_id' => 26301, 'meta_key' => '_media_credit' ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify attachment is tracked as modified.
		$imported_posts = $this->run_state->read_imported_posts();
		$found_modified = false;
		foreach ( $imported_posts as $post ) {
			if ( 26301 === (int) $post['id_old'] && 'modified' === $post['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'Attachment with media credit update should be tracked as modified.' );
	}

	/**
	 * Tests that attachment credit URL update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_attachment_credit_url_update_as_modified_in_runstate(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 26401,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'post_id' => 26401, 'meta_key' => '_media_credit_url', 'meta_value' => 'https://original.example.com' ] ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		// Update credit URL in live.
		$wpdb->update( $this->live_table_prefix . 'postmeta', [ 'meta_value' => 'https://updated.example.com' ], [ 'post_id' => 26401, 'meta_key' => '_media_credit_url' ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify attachment is tracked as modified.
		$imported_posts = $this->run_state->read_imported_posts();
		$found_modified = false;
		foreach ( $imported_posts as $post ) {
			if ( 26401 === (int) $post['id_old'] && 'modified' === $post['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'Attachment with credit URL update should be tracked as modified.' );
	}

	// =========================================================================
	// MDCS TRACKING TESTS - TERMS
	// =========================================================================

	/**
	 * Tests that term slug update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_term_slug_update_as_modified_in_runstate(): void {
		global $wpdb;

		$term_name = 'SlugTrackTerm' . uniqid();
		$post      = $this->create_post_fixture( [ 'ID' => 27001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 27002, 'name' => $term_name, 'slug' => 'original-slug', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 27002, 'term_id' => 27002, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 27001, 'term_taxonomy_id' => 27002 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Update slug in live.
		$wpdb->update( $this->live_table_prefix . 'terms', [ 'slug' => 'updated-slug' ], [ 'term_id' => 27002 ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify term is tracked as modified.
		$imported_terms = $this->run_state->read_imported_terms();
		$found_modified = false;
		foreach ( $imported_terms as $term ) {
			if ( 27002 === (int) $term['term_id_old'] && 'modified' === $term['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'Term with slug update should be tracked as modified.' );
	}

	/**
	 * Tests that term description update is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_term_description_update_as_modified_in_runstate(): void {
		global $wpdb;

		$term_name = 'DescTrackTerm' . uniqid();
		$post      = $this->create_post_fixture( [ 'ID' => 27101 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 27102, 'name' => $term_name, 'slug' => sanitize_title( $term_name ), 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 27102, 'term_id' => 27102, 'taxonomy' => 'category', 'description' => 'Original description', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 27101, 'term_taxonomy_id' => 27102 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Update description in live.
		$wpdb->update( $this->live_table_prefix . 'term_taxonomy', [ 'description' => 'Updated description' ], [ 'term_taxonomy_id' => 27102 ] ); // phpcs:ignore

		$this->run_migrate_command();

		// Verify term is tracked as modified.
		$imported_terms = $this->run_state->read_imported_terms();
		$found_modified = false;
		foreach ( $imported_terms as $term ) {
			if ( 27102 === (int) $term['term_id_old'] && 'modified' === $term['status'] ) {
				$found_modified = true;
				break;
			}
		}
		$this->assertTrue( $found_modified, 'Term with description update should be tracked as modified.' );
	}

	// =========================================================================
	// MDCS TRACKING TESTS - POSTS
	// =========================================================================

	/**
	 * Tests that reimported modified post is tracked as modified in run-state.
	 *
	 * @group migration-data-consistency-standard
	 */
	public function test_should_track_reimported_modified_post_as_modified(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 28001,
				'post_title'    => 'Original Title',
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Modify post in live.
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Modified Title',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 28001 ]
		);

		// Fresh run-state for new migration cycle.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post is tracked as modified (via deleted_modified_ids).
		$deleted_map = $this->run_state->get_deleted_modified_ids_map();
		$this->assertArrayHasKey( 28001, $deleted_map, 'Reimported modified post should be in deleted_modified_ids.' );
	}
}
