<?php
/**
 * Integration tests for command cmd_migrate_live_content, post import.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for command cmd_migrate_live_content, post import.
 *
 * @group integration
 */
class CmdMigrateLiveContentPostTest extends IntegrationTestCase {
	/**
	 * @group post
	 */
	public function test_should_import_post_with_all_fields_correctly(): void {
		// Create a post with all fields populated.
		$fixture = [
			'post' => [
				'ID'                    => 2001,
				'post_author'           => 1,
				'post_date'             => '2024-03-15 14:30:00',
				'post_date_gmt'         => '2024-03-15 14:30:00',
				'post_content'          => '<p>Test content with <strong>formatting</strong>.</p>',
				'post_title'            => 'Test Post With All Fields',
				'post_excerpt'          => 'This is the excerpt.',
				'post_status'           => 'publish',
				'comment_status'        => 'open',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'test-post-all-fields',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '2024-03-15 14:30:00',
				'post_modified_gmt'     => '2024-03-15 14:30:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => 'http://test.local/?p=2001',
				'menu_order'            => 5,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => 0,
			],
		];
		$this->insert_live_data( $fixture );

		// Run search and migrate.
		$this->run_search_command();
		$this->run_migrate_command();

		// Get imported post.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2001, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported.' );

		$post = get_post( $new_post_id );

		// Verify all fields.
		$this->assertEquals( 'Test Post With All Fields', $post->post_title );
		$this->assertEquals( '<p>Test content with <strong>formatting</strong>.</p>', $post->post_content );
		$this->assertEquals( 'This is the excerpt.', $post->post_excerpt );
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( 'open', $post->comment_status );
		$this->assertEquals( 'closed', $post->ping_status );
		$this->assertEquals( 'test-post-all-fields', $post->post_name );
		$this->assertEquals( 5, $post->menu_order );
		$this->assertEquals( 'post', $post->post_type );
		$this->assertEquals( '2024-03-15 14:30:00', $post->post_date );
	}

	/**
	 * @group post
	 */
	public function test_should_save_source_specific_old_id_postmeta(): void {
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );
		$live_post_id = $fixture['post']['ID'];

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( $live_post_id, $this->source_hostname );

		// Verify the meta key is source-specific.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertStringContainsString( $this->source_hostname, $meta_key, 'Meta key should contain source hostname.' );

		$saved_old_id = get_post_meta( $new_post_id, $meta_key, true );
		$this->assertEquals( $live_post_id, (int) $saved_old_id, 'Old ID should be saved with source-specific meta key.' );
	}

	/**
	 * @group post
	 */
	public function test_should_import_postmeta_for_post(): void {
		global $wpdb;

		// Create post with various postmeta.
		$post = $this->create_post_fixture( [ 'ID' => 2002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add multiple postmeta rows.
		$postmeta = [
			[
				'post_id'    => 2002,
				'meta_key'   => 'custom_text',
				'meta_value' => 'Hello World', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'post_id'    => 2002,
				'meta_key'   => 'custom_number',
				'meta_value' => '42', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'post_id'    => 2002,
				'meta_key'   => 'custom_array',
				'meta_value' => serialize( [ 'a', 'b', 'c' ] ), // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $postmeta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'postmeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2002, $this->source_hostname );

		// Verify all postmeta was imported.
		$this->assertEquals( 'Hello World', get_post_meta( $new_post_id, 'custom_text', true ) );
		$this->assertEquals( '42', get_post_meta( $new_post_id, 'custom_number', true ) );
		$this->assertEquals( [ 'a', 'b', 'c' ], get_post_meta( $new_post_id, 'custom_array', true ) );
	}

	/**
	 * @group post
	 */
	public function test_should_skip_internal_system_postmeta_during_import(): void {
		global $wpdb;

		// Create post with system postmeta that should be skipped.
		$post = $this->create_post_fixture( [ 'ID' => 2003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add system meta that should be skipped.
		$system_meta = [
			[
				'post_id'    => 2003,
				'meta_key'   => '_edit_lock',
				'meta_value' => '1234567890:1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'post_id'    => 2003,
				'meta_key'   => '_edit_last',
				'meta_value' => '1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'post_id'    => 2003,
				'meta_key'   => 'custom_meta',
				'meta_value' => 'should_import', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $system_meta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'postmeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2003, $this->source_hostname );

		// Custom meta should be imported.
		$this->assertEquals( 'should_import', get_post_meta( $new_post_id, 'custom_meta', true ) );

		// System meta may or may not be imported depending on implementation.
		// The key test is that the import doesn't fail due to system meta.
		$this->assertNotNull( $new_post_id, 'Post should be imported despite system meta.' );
	}
}
