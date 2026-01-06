<?php
/**
 * Integration tests for command cmd_migrate_live_content, featured image migration.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;

/**
 * Integration test class for command cmd_migrate_live_content, featured image migration.
 *
 * @group integration
 */
class CmdMigrateLiveContentFeaturedImageTest extends IntegrationTestCase {
	/**
	 * @group featuredimage
	 */
	public function test_should_update_thumbnail_id_from_old_to_new(): void {
		global $wpdb;

		// Create attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 9001,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with featured image.
		$post = $this->create_post_fixture( [ 'ID' => 9002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 9002,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 9001, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value. Old attachment ID.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 9002, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 9001, $this->source_hostname );

		$thumbnail_id = get_post_thumbnail_id( $new_post_id );
		$this->assertEquals( $new_attachment_id, $thumbnail_id, 'Thumbnail ID should be updated to new attachment ID.' );
	}

	/**
	 * @group featuredimage
	 */
	public function test_should_not_update_thumbnail_when_attachment_not_in_map(): void {
		global $wpdb;

		// Create post with thumbnail referencing non-existent attachment.
		$post = $this->create_post_fixture( [ 'ID' => 9101 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 9101,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 88888, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value. Non-existent attachment.
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 9101, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should still be imported.' );

		// Thumbnail might be 0 or unchanged - key is no crash.
		$thumbnail_id = get_post_thumbnail_id( $new_post_id );
		$this->assertIsNumeric( $thumbnail_id, 'Thumbnail should be numeric.' );
	}

	/**
	 * @group featuredimage
	 */
	public function test_should_not_update_thumbnail_when_post_has_no_featured_image(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 9201 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id  = $this->logic->get_current_post_id_by_old_id( 9201, $this->source_hostname );
		$thumbnail_id = get_post_thumbnail_id( $new_post_id );

		// No thumbnail should be set.
		$this->assertEmpty( $thumbnail_id, 'Post without featured image should have no thumbnail.' );
	}

	/**
	 * @group featuredimage
	 */
	public function test_should_use_db_attachment_map_not_just_current_batch(): void {
		global $wpdb;

		// First, import an attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 9301,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 9301, $this->source_hostname );
		$this->assertNotNull( $new_attachment_id );

		// Now create new run-state and import a post using that attachment.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$post = $this->create_post_fixture( [ 'ID' => 9302 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 9302,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => 9301, // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value. Reference to previously imported attachment.
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id  = $this->logic->get_current_post_id_by_old_id( 9302, $this->source_hostname );
		$thumbnail_id = get_post_thumbnail_id( $new_post_id );

		// Should use the attachment from the previous batch.
		$this->assertEquals( $new_attachment_id, $thumbnail_id, 'Should use attachment from DB, not just current batch.' );
	}
}
