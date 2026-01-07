<?php
/**
 * Integration tests for cmd_migrate_live_content command, Migration Data Consistency Standard.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

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
}
