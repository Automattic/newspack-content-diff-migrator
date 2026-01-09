<?php
/**
 * Integration tests for multi-source collision handling.
 *
 * Tests behavior when multiple sources have entities with the same unique identifiers
 * (e.g., same user_login, same category name). Verifies graceful merging without crashes.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;

/**
 * Integration test class for multi-source collision handling.
 *
 * @group integration
 * @group multi-source-collision
 */
class CmdMigrateLiveContentMultiSourceCollisionTest extends IntegrationTestCase {
	/**
	 * Tests that users with the same login from two sources get merged to one local user.
	 *
	 * @group multi-source-collision
	 */
	public function test_same_user_login_from_two_sources_merges_to_one_user(): void {
		global $wpdb;

		// Source 1: Post with author "admin".
		$user1 = [
			'ID'                  => 100,
			'user_login'          => 'admin',
			'user_pass'           => 'hash1',
			'user_email'          => 'admin@source-1.com',
			'display_name'        => 'Admin Source 1',
			'user_nicename'       => 'admin',
			'user_url'            => '',
			'user_registered'     => '2024-01-01 00:00:00',
			'user_activation_key' => '',
			'user_status'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'users', $user1 ); // phpcs:ignore

		$post1 = $this->create_post_fixture(
			[
				'ID'          => 20001,
				'post_author' => 100,
				'post_title'  => 'Post from Source 1',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore

		// Import from source 1.
		$this->run_search_command();
		$this->run_migrate_command();

		// Get the local user created from source 1.
		$local_user_from_source1 = get_user_by( 'login', 'admin' );
		$this->assertInstanceOf( \WP_User::class, $local_user_from_source1 );
		$local_user_id = $local_user_from_source1->ID;

		// Clean up source 1 data from live tables to simulate separate source databases.
		$wpdb->delete( $this->live_table_prefix . 'users', [ 'ID' => 100 ] ); // phpcs:ignore

		// Now import from source 2 with same user login but different email.
		$user2 = [
			'ID'                  => 200,
			'user_login'          => 'admin',
			'user_pass'           => 'hash2',
			'user_email'          => 'admin@source-2.com',
			'display_name'        => 'Admin Source 2',
			'user_nicename'       => 'admin',
			'user_url'            => '',
			'user_registered'     => '2024-02-01 00:00:00',
			'user_activation_key' => '',
			'user_status'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'users', $user2 ); // phpcs:ignore

		$post2 = $this->create_post_fixture(
			[
				'ID'          => 20002,
				'post_author' => 200,
				'post_title'  => 'Post from Source 2',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		// Set up for second source.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		// Verify only one "admin" user exists.
		$all_admins = get_users( [ 'login' => 'admin' ] );
		$this->assertCount( 1, $all_admins, 'Only one user with login "admin" should exist.' );

		// Verify it's the same user.
		$this->assertEquals( $local_user_id, $all_admins[0]->ID, 'User should be the same as from source 1.' );
	}

	/**
	 * Tests that merged user has old_id meta for both sources.
	 *
	 * @group multi-source-collision
	 */
	public function test_merged_user_has_old_id_meta_for_both_sources(): void {
		global $wpdb;

		// Source 1: User "editor".
		$user1 = [
			'ID'                  => 101,
			'user_login'          => 'editor',
			'user_pass'           => 'hash1',
			'user_email'          => 'editor@source-1.com',
			'display_name'        => 'Editor',
			'user_nicename'       => 'editor',
			'user_url'            => '',
			'user_registered'     => '2024-01-01 00:00:00',
			'user_activation_key' => '',
			'user_status'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'users', $user1 ); // phpcs:ignore

		$post1 = $this->create_post_fixture(
			[
				'ID'          => 20101,
				'post_author' => 101,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Clean up source 1 data from live tables to simulate separate source databases.
		// In reality, each source would have its own live_table_prefix with only its own users.
		$wpdb->delete( $this->live_table_prefix . 'users', [ 'ID' => 101 ] ); // phpcs:ignore

		// Source 2: User "editor" with different source ID.
		$user2 = [
			'ID'                  => 201,
			'user_login'          => 'editor',
			'user_pass'           => 'hash2',
			'user_email'          => 'editor@source-2.com',
			'display_name'        => 'Editor 2',
			'user_nicename'       => 'editor',
			'user_url'            => '',
			'user_registered'     => '2024-02-01 00:00:00',
			'user_activation_key' => '',
			'user_status'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'users', $user2 ); // phpcs:ignore

		$post2 = $this->create_post_fixture(
			[
				'ID'          => 20102,
				'post_author' => 201,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		// Get the merged user.
		$local_user = get_user_by( 'login', 'editor' );
		$this->assertInstanceOf( \WP_User::class, $local_user );

		// Verify old_id meta exists for BOTH sources.
		$meta_key_source1 = ContentDiffLogic::get_old_id_meta_key( $this->source_hostname );
		$meta_key_source2 = ContentDiffLogic::get_old_id_meta_key( $this->source_hostname_2 );

		$old_id_source1 = get_user_meta( $local_user->ID, $meta_key_source1, true );
		$old_id_source2 = get_user_meta( $local_user->ID, $meta_key_source2, true );

		$this->assertEquals( '101', $old_id_source1, 'User should have old_id meta for source 1.' );
		$this->assertEquals( '201', $old_id_source2, 'User should have old_id meta for source 2.' );
	}

	/**
	 * Tests that different user logins from two sources create two separate users.
	 *
	 * @group multi-source-collision
	 */
	public function test_different_user_logins_from_two_sources_creates_two_users(): void {
		global $wpdb;

		// Source 1: User "alice".
		$user1 = [
			'ID'                  => 102,
			'user_login'          => 'alice',
			'user_pass'           => 'hash1',
			'user_email'          => 'alice@example.com',
			'display_name'        => 'Alice',
			'user_nicename'       => 'alice',
			'user_url'            => '',
			'user_registered'     => '2024-01-01 00:00:00',
			'user_activation_key' => '',
			'user_status'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'users', $user1 ); // phpcs:ignore

		$post1 = $this->create_post_fixture(
			[
				'ID'          => 20201,
				'post_author' => 102,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Clean up source 1 data from live tables to simulate separate source databases.
		$wpdb->delete( $this->live_table_prefix . 'users', [ 'ID' => 102 ] ); // phpcs:ignore

		// Source 2: User "bob".
		$user2 = [
			'ID'                  => 202,
			'user_login'          => 'bob',
			'user_pass'           => 'hash2',
			'user_email'          => 'bob@example.com',
			'display_name'        => 'Bob',
			'user_nicename'       => 'bob',
			'user_url'            => '',
			'user_registered'     => '2024-02-01 00:00:00',
			'user_activation_key' => '',
			'user_status'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'users', $user2 ); // phpcs:ignore

		$post2 = $this->create_post_fixture(
			[
				'ID'          => 20202,
				'post_author' => 202,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		// Verify both users exist.
		$alice = get_user_by( 'login', 'alice' );
		$bob   = get_user_by( 'login', 'bob' );

		$this->assertInstanceOf( \WP_User::class, $alice, 'Alice should exist.' );
		$this->assertInstanceOf( \WP_User::class, $bob, 'Bob should exist.' );
		$this->assertNotEquals( $alice->ID, $bob->ID, 'Alice and Bob should be different users.' );
	}

	/**
	 * Tests that categories with the same name from two sources merge to one.
	 *
	 * @group multi-source-collision
	 */
	public function test_same_category_name_from_two_sources_merges_to_one(): void {
		global $wpdb;

		// Source 1: Category "News".
		$term1 = [
			'term_id'    => 301,
			'name'       => 'News',
			'slug'       => 'news',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term1 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 301,
				'term_id'          => 301,
				'taxonomy'         => 'category',
				'description'      => 'News from source 1',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		$post1 = $this->create_post_fixture( [ 'ID' => 20301 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 20301,
				'term_taxonomy_id' => 301,
				'term_order'       => 0,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Count local "News" categories after source 1.
		$news_terms_after_source1 = get_terms(
			[
				'taxonomy'   => 'category',
				'name'       => 'News',
				'hide_empty' => false,
			]
		);
		$this->assertCount( 1, $news_terms_after_source1, 'One "News" category should exist after source 1.' );
		$local_news_term_id = $news_terms_after_source1[0]->term_id;

		// Clean up source 1 data from live tables to simulate separate source databases.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 20301 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 20301 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'terms', [ 'term_id' => 301 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'term_taxonomy', [ 'term_id' => 301 ] ); // phpcs:ignore

		// Source 2: Category "News" with same name.
		$term2 = [
			'term_id'    => 401,
			'name'       => 'News',
			'slug'       => 'news',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term2 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 401,
				'term_id'          => 401,
				'taxonomy'         => 'category',
				'description'      => 'News from source 2',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		$post2 = $this->create_post_fixture( [ 'ID' => 20302 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 20302,
				'term_taxonomy_id' => 401,
				'term_order'       => 0,
			]
		);

		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		// Count local "News" categories after source 2 - should still be 1.
		$news_terms_after_source2 = get_terms(
			[
				'taxonomy'   => 'category',
				'name'       => 'News',
				'hide_empty' => false,
			]
		);
		$this->assertCount( 1, $news_terms_after_source2, 'Still only one "News" category should exist after source 2.' );
		$this->assertEquals( $local_news_term_id, $news_terms_after_source2[0]->term_id, 'Should be the same category.' );
	}

	/**
	 * Tests that merged category has old_id meta for both sources.
	 *
	 * @group multi-source-collision
	 */
	public function test_merged_category_has_old_id_meta_for_both_sources(): void {
		global $wpdb;

		// Source 1: Category "Sports".
		$term1 = [
			'term_id'    => 302,
			'name'       => 'Sports',
			'slug'       => 'sports',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term1 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 302,
				'term_id'          => 302,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		$post1 = $this->create_post_fixture( [ 'ID' => 20401 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 20401,
				'term_taxonomy_id' => 302,
				'term_order'       => 0,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Get local Sports category.
		$sports_term = get_term_by( 'name', 'Sports', 'category' );
		$this->assertInstanceOf( \WP_Term::class, $sports_term );
		$local_term_id = $sports_term->term_id;

		// Clean up source 1 data from live tables to simulate separate source databases.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 20401 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 20401 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'terms', [ 'term_id' => 302 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'term_taxonomy', [ 'term_id' => 302 ] ); // phpcs:ignore

		// Source 2: Category "Sports" with different source ID.
		$term2 = [
			'term_id'    => 402,
			'name'       => 'Sports',
			'slug'       => 'sports',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term2 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 402,
				'term_id'          => 402,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		$post2 = $this->create_post_fixture( [ 'ID' => 20402 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 20402,
				'term_taxonomy_id' => 402,
				'term_order'       => 0,
			]
		);

		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		// Verify old_id meta exists for BOTH sources.
		$meta_key_source1 = ContentDiffLogic::get_old_id_meta_key( $this->source_hostname );
		$meta_key_source2 = ContentDiffLogic::get_old_id_meta_key( $this->source_hostname_2 );

		$old_id_source1 = get_term_meta( $local_term_id, $meta_key_source1, true );
		$old_id_source2 = get_term_meta( $local_term_id, $meta_key_source2, true );

		$this->assertEquals( '302', $old_id_source1, 'Category should have old_id meta for source 1.' );
		$this->assertEquals( '402', $old_id_source2, 'Category should have old_id meta for source 2.' );
	}

	/**
	 * Tests that same category name with different parents creates separate categories.
	 *
	 * @group multi-source-collision
	 */
	public function test_same_category_different_parent_creates_separate_categories(): void {
		global $wpdb;

		// Create parent "Sports" first.
		$parent_term = [
			'term_id'    => 303,
			'name'       => 'Sports',
			'slug'       => 'sports-parent',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $parent_term ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 303,
				'term_id'          => 303,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		// Create parent "News".
		$parent_term2 = [
			'term_id'    => 304,
			'name'       => 'News',
			'slug'       => 'news-parent',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $parent_term2 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 304,
				'term_id'          => 304,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		// Child "Football" under "Sports".
		$child1 = [
			'term_id'    => 305,
			'name'       => 'Football',
			'slug'       => 'football-sports',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $child1 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 305,
				'term_id'          => 305,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 303, // Parent is Sports.
				'count'            => 0,
			]
		);

		// Child "Football" under "News".
		$child2 = [
			'term_id'    => 306,
			'name'       => 'Football',
			'slug'       => 'football-news',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $child2 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 306,
				'term_id'          => 306,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 304, // Parent is News.
				'count'            => 0,
			]
		);

		// Post with both categories.
		$post = $this->create_post_fixture( [ 'ID' => 20501 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 20501, 'term_taxonomy_id' => 305, 'term_order' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 20501, 'term_taxonomy_id' => 306, 'term_order' => 0 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify two "Football" categories exist (different parents).
		$football_terms = get_terms(
			[
				'taxonomy'   => 'category',
				'name'       => 'Football',
				'hide_empty' => false,
			]
		);
		$this->assertCount( 2, $football_terms, 'Two "Football" categories should exist with different parents.' );
	}

	/**
	 * Tests that same tag name from two sources merges to one.
	 *
	 * @group multi-source-collision
	 */
	public function test_same_tag_name_from_two_sources_merges_to_one(): void {
		global $wpdb;

		// Source 1: Tag "breaking".
		$tag1 = [
			'term_id'    => 307,
			'name'       => 'breaking',
			'slug'       => 'breaking',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $tag1 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 307,
				'term_id'          => 307,
				'taxonomy'         => 'post_tag',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		$post1 = $this->create_post_fixture( [ 'ID' => 20601 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 20601, 'term_taxonomy_id' => 307, 'term_order' => 0 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Clean up source 1 data from live tables to simulate separate source databases.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 20601 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 20601 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'terms', [ 'term_id' => 307 ] ); // phpcs:ignore
		$wpdb->delete( $this->live_table_prefix . 'term_taxonomy', [ 'term_id' => 307 ] ); // phpcs:ignore

		// Source 2: Same tag "breaking".
		$tag2 = [
			'term_id'    => 407,
			'name'       => 'breaking',
			'slug'       => 'breaking',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $tag2 ); // phpcs:ignore
		$wpdb->insert( // phpcs:ignore
			$this->live_table_prefix . 'term_taxonomy',
			[
				'term_taxonomy_id' => 407,
				'term_id'          => 407,
				'taxonomy'         => 'post_tag',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			]
		);

		$post2 = $this->create_post_fixture( [ 'ID' => 20602 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 20602, 'term_taxonomy_id' => 407, 'term_order' => 0 ] ); // phpcs:ignore

		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		// Verify only one "breaking" tag exists.
		$breaking_tags = get_terms(
			[
				'taxonomy'   => 'post_tag',
				'name'       => 'breaking',
				'hide_empty' => false,
			]
		);
		$this->assertCount( 1, $breaking_tags, 'Only one "breaking" tag should exist.' );
	}

	/**
	 * Tests that posts with same identifiers from two sources import as separate posts.
	 *
	 * @group multi-source-collision
	 */
	public function test_post_with_same_identifier_from_two_sources_imports_both(): void {
		global $wpdb;

		// Source 1: Post with specific title/slug/date.
		$post1 = $this->create_post_fixture(
			[
				'ID'         => 20701,
				'post_title' => 'Breaking News',
				'post_name'  => 'breaking-news',
				'post_date'  => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_post1_id = $this->logic->get_current_post_id_by_old_id( 20701, $this->source_hostname );
		$this->assertNotNull( $local_post1_id );

		// Clean up source 1 data from live tables to simulate separate source databases.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 20701 ] ); // phpcs:ignore

		// Source 2: Post with identical title/slug/date but different source.
		$post2 = $this->create_post_fixture(
			[
				'ID'         => 20702,
				'post_title' => 'Breaking News',
				'post_name'  => 'breaking-news',
				'post_date'  => '2024-01-15 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore

		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		$local_post2_id = $this->logic->get_current_post_id_by_old_id( 20702, $this->source_hostname_2 );
		$this->assertNotNull( $local_post2_id );

		// Both posts should exist as separate entities.
		$this->assertNotEquals( $local_post1_id, $local_post2_id, 'Posts from different sources should have different local IDs.' );

		// Verify both posts exist.
		$this->assertNotNull( get_post( $local_post1_id ) );
		$this->assertNotNull( get_post( $local_post2_id ) );
	}

	/**
	 * Tests that attachments with same filename from two sources import as separate.
	 *
	 * @group multi-source-collision
	 */
	public function test_attachment_with_same_filename_from_two_sources_imports_both(): void {
		global $wpdb;

		// Source 1: Attachment "logo.png".
		$attachment1 = [
			'ID'                    => 20801,
			'post_author'           => 1,
			'post_date'             => '2024-01-01 00:00:00',
			'post_date_gmt'         => '2024-01-01 00:00:00',
			'post_content'          => '',
			'post_title'            => 'logo',
			'post_excerpt'          => '',
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'logo',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-01 00:00:00',
			'post_modified_gmt'     => '2024-01-01 00:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'https://source1.example.com/logo.png',
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => 'image/png',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_attachment1_id = $this->logic->get_current_post_id_by_old_id( 20801, $this->source_hostname );
		$this->assertNotNull( $local_attachment1_id );

		// Clean up source 1 data from live tables to simulate separate source databases.
		$wpdb->delete( $this->live_table_prefix . 'posts', [ 'ID' => 20801 ] ); // phpcs:ignore

		// Source 2: Attachment "logo.png" from different source.
		$attachment2 = [
			'ID'                    => 20802,
			'post_author'           => 1,
			'post_date'             => '2024-02-01 00:00:00',
			'post_date_gmt'         => '2024-02-01 00:00:00',
			'post_content'          => '',
			'post_title'            => 'logo',
			'post_excerpt'          => '',
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'logo',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-02-01 00:00:00',
			'post_modified_gmt'     => '2024-02-01 00:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'https://source2.example.com/logo.png',
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => 'image/png',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore

		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		$local_attachment2_id = $this->logic->get_current_post_id_by_old_id( 20802, $this->source_hostname_2 );
		$this->assertNotNull( $local_attachment2_id );

		// Both attachments should exist as separate entities.
		$this->assertNotEquals( $local_attachment1_id, $local_attachment2_id, 'Attachments from different sources should have different local IDs.' );
	}
}
