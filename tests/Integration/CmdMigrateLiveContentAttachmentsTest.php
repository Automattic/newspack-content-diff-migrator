<?php
/**
 * Integration tests for command cmd_migrate_live_content, attachment migration.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for command cmd_migrate_live_content, attachment migration.
 *
 * @group integration
 */
class CmdMigrateLiveContentAttachmentsTest extends IntegrationTestCase {
	/**
	 * @group attachments
	 */
	public function test_should_import_attachment_post_type_correctly(): void {
		$fixture = $this->load_fixture( 'attachment-basic' );
		$this->insert_live_data( $fixture );
		$live_attachment_id = $fixture['post']['ID'];

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( $live_attachment_id, $this->source_hostname );
		$this->assertNotNull( $new_attachment_id, 'Attachment should be imported.' );

		$attachment = get_post( $new_attachment_id );
		$this->assertEquals( 'attachment', $attachment->post_type );
		$this->assertEquals( 'image/jpeg', $attachment->post_mime_type );
	}

	/**
	 * @group attachments
	 */
	public function test_should_preserve_attachment_metadata(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'             => 14101,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add attachment metadata.
		$metadata = [
			'width'  => 1920,
			'height' => 1080,
			'file'   => '2024/01/test-image.jpg',
			'sizes'  => [
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'file'   => 'test-image-150x150.jpg',
				],
				'medium'    => [
					'width'  => 300,
					'height' => 169,
					'file'   => 'test-image-300x169.jpg',
				],
			],
		];
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 14101,
				'meta_key'   => '_wp_attachment_metadata',
				'meta_value' => serialize( $metadata ), // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 14101, $this->source_hostname );
		$imported_metadata = get_post_meta( $new_attachment_id, '_wp_attachment_metadata', true );

		$this->assertEquals( 1920, $imported_metadata['width'] );
		$this->assertEquals( 1080, $imported_metadata['height'] );
		$this->assertArrayHasKey( 'thumbnail', $imported_metadata['sizes'] );
	}

	/**
	 * @group attachments
	 */
	public function test_should_handle_attachment_with_parent_post(): void {
		global $wpdb;

		// Create parent post.
		$post = $this->create_post_fixture( [ 'ID' => 14201 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create attachment with parent.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 14202,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 14201,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 14201, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 14202, $this->source_hostname );

		$attachment = get_post( $new_attachment_id );
		$this->assertEquals( $new_post_id, $attachment->post_parent, 'Attachment parent should be updated.' );
	}

	/**
	 * @group attachments
	 */
	public function test_should_preserve_wp_attachment_metadata_postmeta(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 14301,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$metadata = [
			'width'  => 800,
			'height' => 600,
			'file'   => 'uploads/image.jpg',
		];
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 14301,
				'meta_key'   => '_wp_attachment_metadata',
				'meta_value' => serialize( $metadata ), // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_id   = $this->logic->get_current_post_id_by_old_id( 14301, $this->source_hostname );
		$imported = get_post_meta( $new_id, '_wp_attachment_metadata', true );

		$this->assertEquals( 800, $imported['width'] );
		$this->assertEquals( 600, $imported['height'] );
	}

	/**
	 * @group attachments
	 */
	public function test_should_preserve_wp_attached_file_postmeta(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 14401,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'postmeta',
			[
				'post_id'    => 14401,
				'meta_key'   => '_wp_attached_file',
				'meta_value' => '2024/01/my-file.pdf', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			]
		);

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_id = $this->logic->get_current_post_id_by_old_id( 14401, $this->source_hostname );
		$file   = get_post_meta( $new_id, '_wp_attached_file', true );

		$this->assertEquals( '2024/01/my-file.pdf', $file );
	}

	/**
	 * @group attachments
	 */
	public function test_should_import_attachment_with_no_parent_post(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 14501,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 0, // No parent.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'attachment' ] );
		$this->run_migrate_command();

		$new_id     = $this->logic->get_current_post_id_by_old_id( 14501, $this->source_hostname );
		$attachment = get_post( $new_id );

		$this->assertNotNull( $new_id, 'Attachment without parent should be imported.' );
		$this->assertEquals( 0, $attachment->post_parent, 'Parent should remain 0.' );
	}

	/**
	 * Tests that attachment post_parent is updated to the new local post ID.
	 *
	 * @group attachments
	 */
	public function test_should_update_attachment_parent_post_id(): void {
		global $wpdb;

		// Create parent post.
		$post = $this->create_post_fixture( [ 'ID' => 10001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create attachment with parent.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10002,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 10001, // Points to live post ID.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10001, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10002, $this->source_hostname );

		$attachment_post = get_post( $new_attachment_id );
		$this->assertEquals( (int) $new_post_id, $attachment_post->post_parent, 'Attachment post_parent should be updated to new local post ID.' );
	}
}
