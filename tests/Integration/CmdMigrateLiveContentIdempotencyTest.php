<?php
/**
 * Integration tests for command cmd_migrate_live_content, idempotency and rerun safety.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for command cmd_migrate_live_content, idempotency and rerun safety.
 *
 * @group integration
 */
class CmdMigrateLiveContentIdempotencyTest extends IntegrationTestCase {
	/**
	 * Tests that running migration twice produces the same result.
	 *
	 * @group idempotency
	 */
	public function test_should_produce_same_result_when_running_migration_twice(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 16001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 16001, 'name' => 'Idempotent Cat', 'slug' => 'idempotent-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 16001, 'term_id' => 16001, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 16001, 'term_taxonomy_id' => 16001 ] ); // phpcs:ignore

		// First migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$first_post_id = $this->logic->get_current_post_id_by_old_id( 16001, $this->source_hostname );
		$first_post    = get_post( $first_post_id );

		// Second migration (no changes on live).
		$this->run_search_command();
		$this->run_migrate_command();

		$second_post_id = $this->logic->get_current_post_id_by_old_id( 16001, $this->source_hostname );
		$second_post    = get_post( $second_post_id );

		// Results should be identical.
		$this->assertEquals( $first_post_id, $second_post_id, 'Post ID should remain the same.' );
		$this->assertEquals( $first_post->post_title, $second_post->post_title, 'Post content should remain the same.' );
	}

	/**
	 * Tests that duplicate posts are not created on rerun.
	 *
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_posts_on_rerun(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'         => 16002,
				'post_title' => 'Unique Post Title',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// First migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$posts_after_first = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'Unique Post Title'" ); // phpcs:ignore

		// Second migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$posts_after_second = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'Unique Post Title'" ); // phpcs:ignore

		$this->assertEquals( $posts_after_first, $posts_after_second, 'No duplicate posts should be created.' );
	}

	/**
	 * Tests that duplicate terms are not created on rerun.
	 *
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_terms_on_rerun(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 16003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 16003, 'name' => 'No Duplicate Term', 'slug' => 'no-duplicate-term', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 16003, 'term_id' => 16003, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 16003, 'term_taxonomy_id' => 16003 ] ); // phpcs:ignore

		// First migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$terms_after_first = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug = 'no-duplicate-term'" ); // phpcs:ignore

		// Second migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$terms_after_second = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug = 'no-duplicate-term'" ); // phpcs:ignore

		$this->assertEquals( $terms_after_first, $terms_after_second, 'No duplicate terms should be created.' );
	}

	/**
	 * Tests that duplicate users are not created on rerun.
	 *
	 * @group idempotency
	 */
	public function test_should_not_create_duplicate_users_on_rerun(): void {
		global $wpdb;

		$user = $this->create_user_fixture(
			[
				'ID'         => 16101,
				'user_login' => 'no_duplicate_user',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $user ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 16004,
				'post_author' => 16101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// First migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$users_after_first = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = 'no_duplicate_user'" ); // phpcs:ignore

		// Second migration.
		$this->run_search_command();
		$this->run_migrate_command();

		$users_after_second = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = 'no_duplicate_user'" ); // phpcs:ignore

		$this->assertEquals( $users_after_first, $users_after_second, 'No duplicate users should be created.' );
	}

	/**
	 * Tests that completed steps are skipped on resume.
	 *
	 * @group idempotency
	 */
	public function test_should_skip_completed_steps_on_resume(): void {
		global $wpdb;

		// Create multiple posts.
		for ( $i = 1; $i <= 3; $i++ ) {
			$post = $this->create_post_fixture( [ 'ID' => 16100 + $i ] );
			$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		}

		$this->run_search_command();
		$this->run_migrate_command();

		// Get imported post IDs map.
		$imported_map_first = $this->run_state->get_imported_post_ids_map();

		// Run migrate again (resume scenario - all already imported).
		$this->run_migrate_command();

		$imported_map_second = $this->run_state->get_imported_post_ids_map();

		// Should be the same (no re-imports).
		$this->assertEquals( $imported_map_first, $imported_map_second, 'Imported posts map should remain the same.' );

		// Verify all posts exist.
		for ( $i = 1; $i <= 3; $i++ ) {
			$local_id = $this->logic->get_current_post_id_by_old_id( 16100 + $i, $this->source_hostname );
			$this->assertNotNull( $local_id, "Post 1610{$i} should exist." );
		}
	}
}
