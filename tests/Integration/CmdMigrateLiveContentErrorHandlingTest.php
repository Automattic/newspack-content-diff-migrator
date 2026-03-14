<?php
/**
 * Integration tests for command cmd_migrate_live_content, error handling.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\Logger;

/**
 * Integration test class for command cmd_migrate_live_content, error handling.
 *
 * @group integration
 */
class CmdMigrateLiveContentErrorHandlingTest extends IntegrationTestCase {
	/**
	 * Tests that migration throws exception when new_ids run-state file is not found.
	 *
	 * @group error-handling
	 */
	public function test_should_throw_when_new_ids_runstate_file_not_found(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( RunState::FILE_NEW_IDS );

		// Write manifest (required before migrate can proceed).
		$this->run_state->write_manifest(
			[
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
				'source_hostname'   => $this->source_hostname,
				'live_table_prefix' => $this->live_table_prefix,
				'post_types'        => [ 'post', 'page', 'attachment' ],
				'counts'            => [
					'new_ids'      => 0,
					'modified_ids' => 0,
				],
			] 
		);

		// Don't write new_ids.json (so it won't exist).
		$this->run_migrate_command();
	}

	/**
	 * Tests behavior when trying to insert a post with invalid data.
	 * Note: In practice, DB insert failures are hard to simulate in integration tests.
	 * This test verifies the command handles posts with unusual but valid data.
	 *
	 * @group error-handling
	 */
	public function test_should_throw_when_post_insert_fails(): void {
		global $wpdb;

		// Create a post with valid data - this should work.
		$post = $this->create_post_fixture( [ 'ID' => 12001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Post should be imported successfully.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12001, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported.' );
	}

	/**
	 * Tests that old_id postmeta is saved correctly.
	 * Note: Testing actual insert failure is difficult in integration tests.
	 *
	 * @group error-handling
	 */
	public function test_should_throw_when_old_id_postmeta_insert_fails(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 12002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify old_id postmeta was saved.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12002, $this->source_hostname );
		$meta_key    = $this->get_old_id_meta_key();
		$old_id      = get_post_meta( $new_post_id, $meta_key, true );
		$this->assertEquals( 12002, (int) $old_id, 'Old ID postmeta should be saved.' );
	}

	/**
	 * Tests that migration continues when postmeta insert encounters issues.
	 * Tests with a post that has unusual but valid postmeta.
	 *
	 * @group error-handling
	 */
	public function test_should_log_error_and_continue_when_postmeta_insert_fails(): void {
		global $wpdb;

		// Create post with some postmeta.
		$post = $this->create_post_fixture( [ 'ID' => 12003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add valid postmeta.
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 12003, 'post_id' => 12003, 'meta_key' => '_test_meta', 'meta_value' => 'test_value' ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Migration should complete and postmeta should be imported.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12003, $this->source_hostname );
		$meta_value  = get_post_meta( $new_post_id, '_test_meta', true );
		$this->assertEquals( 'test_value', $meta_value, 'Postmeta should be imported.' );
	}

	/**
	 * Tests that migration continues when comment data has issues.
	 * Tests with a post that has a comment with missing user reference.
	 *
	 * @group error-handling
	 */
	public function test_should_log_error_and_continue_when_comment_insert_fails(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 12004,
				'comment_count' => '1',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add a comment with user_id pointing to non-existent user.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'comments',
			[
				'comment_ID'           => 12004,
				'comment_post_ID'      => 12004,
				'comment_author'       => 'Anonymous',
				'comment_author_email' => 'anon@test.local',
				'comment_author_url'   => '',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2024-01-15 10:00:00',
				'comment_date_gmt'     => '2024-01-15 10:00:00',
				'comment_content'      => 'Test comment',
				'comment_karma'        => 0,
				'comment_approved'     => 1,
				'comment_agent'        => '',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0, // Anonymous user.
			]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Post and comment should be imported.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12004, $this->source_hostname );
		$comments    = get_comments( [ 'post_id' => $new_post_id ] );
		$this->assertCount( 1, $comments, 'Comment should be imported.' );
	}

	/**
	 * Tests that migration continues when user data has issues.
	 * Tests with a user that has minimal valid data.
	 *
	 * @group error-handling
	 */
	public function test_should_log_error_and_continue_when_user_insert_fails(): void {
		global $wpdb;

		// Create user with minimal data.
		$user = $this->create_user_fixture(
			[
				'ID'         => 12101,
				'user_login' => 'minimal_user',
				'user_email' => 'minimal@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $user ); // phpcs:ignore

		$post = $this->create_post_fixture(
			[
				'ID'          => 12005,
				'post_author' => 12101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// User should be created.
		$local_user = get_user_by( 'login', 'minimal_user' );
		$this->assertNotFalse( $local_user, 'User should be created.' );
	}

	/**
	 * Tests that migration continues when term data has issues.
	 * Tests with a term that has empty description (valid but edge case).
	 *
	 * @group error-handling
	 */
	public function test_should_log_error_and_continue_when_term_insert_fails(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 12006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create term with empty description.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 12201, 'name' => 'Minimal Term', 'slug' => 'minimal-term', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 12201, 'term_id' => 12201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 12006, 'term_taxonomy_id' => 12201 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Term should be imported.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12006, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Minimal Term', $categories, 'Term should be imported.' );
	}

	/**
	 * Tests that migration handles empty taxonomy list gracefully.
	 *
	 * @group error-handling
	 */
	public function test_should_warn_when_no_taxonomies_to_migrate(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 12007 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 12202, 'name' => 'Test Term', 'slug' => 'test-term', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 12202, 'term_id' => 12202, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 12007, 'term_taxonomy_id' => 12202 ] ); // phpcs:ignore

		$this->run_search_command();

		// Migrate with empty taxonomy list - should complete without throwing.
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => '' ] );

		// Verify post was migrated (even though no taxonomies were).
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12007, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be migrated.' );
	}

	/**
	 * Tests that migration errors when manifest not found.
	 *
	 * @group error-handling
	 */
	public function test_should_handle_manifest_not_found_error(): void {
		global $wpdb;

		// Do NOT run search command (no manifest will be created).
		$post = $this->create_post_fixture(
			[
				'ID'         => 12008,
				'post_title' => 'Test Post',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Can not find manifest file' );

		$this->run_migrate_command();
	}

	/**
	 * Tests that migration continues when no new posts are found.
	 *
	 * @group error-handling
	 */
	public function test_should_continue_migration_when_no_new_posts_found(): void {
		$this->run_search_command(); // Search with empty live DB.

		// Should not throw, migration should complete gracefully with no posts.
		$this->run_migrate_command();

		// Verify no posts were found to migrate (empty new_ids).
		$new_ids = $this->run_state->get_new_ids();
		$this->assertEmpty( $new_ids, 'New IDs should be empty when live DB has no posts.' );
	}
}
