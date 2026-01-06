<?php
/**
 * Integration tests for command cmd_migrate_live_content, multiple source hostnames.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;

/**
 * Integration test class for command cmd_migrate_live_content, multiple source hostnames.
 *
 * @group integration
 */
class CmdMigrateLiveContentSourceHostnameTest extends IntegrationTestCase {
	/**
	 * @group hostname
	 */
	public function test_should_import_content_independently_for_different_source_hostnames(): void {
		global $wpdb;

		// Create post for first source.
		$post1 = $this->create_post_fixture(
			[
				'ID'         => 12001,
				'post_title' => 'Post from Source 1',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Import from first source.
		$this->run_search_command();
		$this->run_migrate_command();

		$new_post1_id = $this->logic->get_current_post_id_by_old_id( 12001, $this->source_hostname );
		$this->assertNotNull( $new_post1_id, 'Post from source 1 should be imported.' );

		// Create post for second source.
		$post2 = $this->create_post_fixture(
			[
				'ID'         => 12002,
				'post_title' => 'Post from Source 2',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Set up for second source hostname.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname_2 . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Import from second source.
		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		$new_post2_id = $this->logic->get_current_post_id_by_old_id( 12002, $this->source_hostname_2 );
		$this->assertNotNull( $new_post2_id, 'Post from source 2 should be imported.' );

		// Verify both posts exist independently.
		$this->assertNotEquals( $new_post1_id, $new_post2_id, 'Posts from different sources should have different IDs.' );
	}

	/**
	 * @group hostname
	 */
	public function test_should_not_cross_contaminate_old_ids_between_source_hostnames(): void {
		global $wpdb;

		// Import a post from source 1.
		$post = $this->create_post_fixture( [ 'ID' => 12101 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 12101, $this->source_hostname );

		// Try to get the same post using source 2 meta key - should not find it.
		$post_via_source2 = $this->logic->get_current_post_id_by_old_id( 12101, $this->source_hostname_2 );

		$this->assertNotNull( $new_post_id, 'Post should be found via source 1.' );
		$this->assertNull( $post_via_source2, 'Post should NOT be found via source 2 meta key.' );
	}

	/**
	 * @group hostname
	 */
	public function test_should_use_source_specific_meta_key_for_old_ids(): void {
		// Get meta keys for different sources.
		$meta_key_1 = ContentDiffLogic::get_old_id_meta_key( 'source1.example.com' );
		$meta_key_2 = ContentDiffLogic::get_old_id_meta_key( 'source2.example.com' );

		$this->assertNotEquals( $meta_key_1, $meta_key_2, 'Different sources should have different meta keys.' );
		$this->assertStringContainsString( 'source1.example.com', $meta_key_1 );
		$this->assertStringContainsString( 'source2.example.com', $meta_key_2 );
	}

	/**
	 * @group hostname
	 */
	public function test_should_list_all_migrated_source_hostnames(): void {
		global $wpdb;

		// Import from first source.
		$post1 = $this->create_post_fixture( [ 'ID' => 12201 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Import from second source.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname_2 . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$post2 = $this->create_post_fixture( [ 'ID' => 12202 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'source-hostname' => $this->source_hostname_2 ] );
		$this->run_migrate_command( [ 'source-hostname' => $this->source_hostname_2 ] );

		// Get all migrated source hostnames.
		$hostnames = $this->logic->get_migrated_source_hostnames();

		$this->assertContains( $this->source_hostname, $hostnames, 'Source 1 should be listed.' );
		$this->assertContains( $this->source_hostname_2, $hostnames, 'Source 2 should be listed.' );
	}
}
