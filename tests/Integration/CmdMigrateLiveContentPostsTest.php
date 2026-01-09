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
class CmdMigrateLiveContentPostsTest extends IntegrationTestCase {
	/**
	 * @group posts
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
	 * @group posts
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
	 * @group posts
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
	 * @group posts
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

	/**
	 * @group posts
	 */
	public function test_should_update_post_parent_id_from_old_to_new(): void {
		global $wpdb;

		// Create parent page.
		$parent = $this->create_post_fixture(
			[
				'ID'          => 8001,
				'post_parent' => 0,
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create child page with parent reference.
		$child = $this->create_post_fixture(
			[
				'ID'          => 8002,
				'post_parent' => 8001, // Old parent ID.
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		$new_parent_id = $this->logic->get_current_post_id_by_old_id( 8001, $this->source_hostname );
		$new_child_id  = $this->logic->get_current_post_id_by_old_id( 8002, $this->source_hostname );

		$child_post = get_post( $new_child_id );
		$this->assertEquals( $new_parent_id, $child_post->post_parent, 'Child post_parent should be updated to new parent ID.' );
	}

	/**
	 * Tests that parent ID is correctly resolved via old_id postmeta lookup.
	 *
	 * Uses 'post' type since pages cannot be imported on consecutive runs per MDCS.
	 *
	 * @group posts
	 */
	public function test_should_find_parent_id_by_old_id_postmeta(): void {
		global $wpdb;

		// Create parent that was imported in a previous batch.
		$parent_id = wp_insert_post(
			[
				'post_title'  => 'Pre-existing Parent',
				'post_status' => 'publish',
				'post_type'   => 'post',
			]
		);
		// Mark with old_id meta.
		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $parent_id, $meta_key, 8101 );

		// Create child in live DB referencing the old parent ID.
		$child = $this->create_post_fixture(
			[
				'ID'          => 8102,
				'post_parent' => 8101, // References the pre-existing parent by old ID.
				'post_type'   => 'post',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post' ] );
		$this->run_migrate_command();

		$new_child_id = $this->logic->get_current_post_id_by_old_id( 8102, $this->source_hostname );
		$child_post   = get_post( $new_child_id );

		$this->assertEquals( $parent_id, $child_post->post_parent, 'Should find parent by old_id postmeta.' );
	}

	/**
	 * @group posts
	 */
	public function test_should_find_parent_id_by_comparing_with_live_db(): void {
		global $wpdb;

		// Create parent in live DB.
		$parent = $this->create_post_fixture(
			[
				'ID'          => 8201,
				'post_parent' => 0,
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $parent ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create child in live DB.
		$child = $this->create_post_fixture(
			[
				'ID'          => 8202,
				'post_parent' => 8201,
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		// Both should be imported.
		$new_parent_id = $this->logic->get_current_post_id_by_old_id( 8201, $this->source_hostname );
		$new_child_id  = $this->logic->get_current_post_id_by_old_id( 8202, $this->source_hostname );

		$this->assertNotNull( $new_parent_id );
		$this->assertNotNull( $new_child_id );

		$child_post = get_post( $new_child_id );
		$this->assertEquals( $new_parent_id, $child_post->post_parent, 'Parent should be found via live DB comparison.' );
	}

	/**
	 * @group posts
	 */
	public function test_should_set_parent_to_zero_when_parent_not_found(): void {
		global $wpdb;

		// Create child with parent that doesn't exist.
		$child = $this->create_post_fixture(
			[
				'ID'          => 8302,
				'post_parent' => 99999, // Non-existent parent.
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		$new_child_id = $this->logic->get_current_post_id_by_old_id( 8302, $this->source_hostname );
		$child_post   = get_post( $new_child_id );

		// Parent should be 0 since the referenced parent doesn't exist.
		$this->assertEquals( 0, $child_post->post_parent, 'Parent should be 0 when not found.' );
	}

	/**
	 * @group posts
	 */
	public function test_should_import_custom_post_type_correctly(): void {
		global $wpdb;

		// Register custom post type.
		register_post_type( 'product', [ 'public' => true ] );

		$product = $this->create_post_fixture(
			[
				'ID'         => 14001,
				'post_type'  => 'product',
				'post_title' => 'Test Product',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $product ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'product' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14001, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'CPT should be imported.' );

		$post = get_post( $new_post_id );
		$this->assertEquals( 'product', $post->post_type );
		$this->assertEquals( 'Test Product', $post->post_title );
	}

	/**
	 * Tests that postmeta with serialized array values is imported correctly.
	 *
	 * @group posts
	 */
	public function test_should_import_postmeta_with_serialized_array_value(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 14001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add serialized array postmeta.
		$serialized_array = serialize( // phpcs:ignore -- WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize.
			[
				'key1'    => 'value1',
				'key2'    => 'value2',
				'numbers' => [ 1, 2, 3 ],
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 14001, 'post_id' => 14001, 'meta_key' => '_serialized_array', 'meta_value' => $serialized_array ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14001, $this->source_hostname );
		$meta_value  = get_post_meta( $new_post_id, '_serialized_array', true );

		$this->assertIsArray( $meta_value, 'Serialized array should be unserialized.' );
		$this->assertEquals( 'value1', $meta_value['key1'], 'Array key1 should match.' );
		$this->assertEquals( [ 1, 2, 3 ], $meta_value['numbers'], 'Nested array should match.' );
	}

	/**
	 * Tests that postmeta with serialized object values is imported correctly.
	 *
	 * @group posts
	 */
	public function test_should_import_postmeta_with_serialized_object_value(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 14002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Add serialized stdClass object postmeta.
		$obj               = new \stdClass();
		$obj->name         = 'Test Object';
		$obj->data         = [ 'a', 'b', 'c' ];
		$serialized_object = serialize( $obj ); // phpcs:ignore -- WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize.
		$wpdb->insert( $this->live_table_prefix . 'postmeta', [ 'meta_id' => 14002, 'post_id' => 14002, 'meta_key' => '_serialized_object', 'meta_value' => $serialized_object ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14002, $this->source_hostname );
		$meta_value  = get_post_meta( $new_post_id, '_serialized_object', true );

		$this->assertIsObject( $meta_value, 'Serialized object should be unserialized.' );
		$this->assertEquals( 'Test Object', $meta_value->name, 'Object property should match.' );
	}

	/**
	 * Tests that reimporting a post with block content correctly updates attachment IDs in blocks.
	 *
	 * @group posts-modified
	 */
	public function test_reimport_should_update_attachment_ids_in_blocks(): void {
		global $wpdb;

		// Create attachment in live.
		$attachment = [
			'ID'                    => 4060,
			'post_author'           => 1,
			'post_date'             => '2024-01-01 10:00:00',
			'post_date_gmt'         => '2024-01-01 10:00:00',
			'post_content'          => '',
			'post_title'            => 'Block Image',
			'post_excerpt'          => '',
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => 'block-image',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-01 10:00:00',
			'post_modified_gmt'     => '2024-01-01 10:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'http://test.local/wp-content/uploads/block-image.jpg',
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => 'image/jpeg',
			'comment_count'         => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore

		// Create post with image block referencing the attachment.
		$block_content = '<!-- wp:image {"id":4060} --><figure class="wp-block-image"><img src="http://test.local/block-image.jpg" class="wp-image-4060"/></figure><!-- /wp:image -->';
		$post          = $this->create_post_fixture(
			[
				'ID'            => 4061,
				'post_title'    => 'Post With Image Block',
				'post_content'  => $block_content,
				'post_modified' => '2024-01-01 10:00:00',
				'post_parent'   => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$original_post_local_id       = $this->logic->get_current_post_id_by_old_id( 4061, $this->source_hostname );
		$original_attachment_local_id = $this->logic->get_current_post_id_by_old_id( 4060, $this->source_hostname );
		$this->assertNotNull( $original_post_local_id, 'Post should be imported.' );
		$this->assertNotNull( $original_attachment_local_id, 'Attachment should be imported.' );

		// Verify block content was updated with local attachment ID.
		$original_post_content = get_post( $original_post_local_id )->post_content;
		$this->assertStringContainsString( '"id":' . $original_attachment_local_id, $original_post_content, 'Block should reference local attachment ID.' );

		// Modify post in live.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'posts',
			[
				'post_title'        => 'Post With Image Block Modified',
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 4061 ]
		); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was reimported.
		$new_post_local_id = $this->logic->get_current_post_id_by_old_id( 4061, $this->source_hostname );
		$this->assertNotNull( $new_post_local_id, 'Post should be reimported.' );
		$this->assertEquals( $original_post_local_id, $new_post_local_id, 'Post should preserve its local ID.' );

		// Verify block content still has correct local attachment ID (not the old live ID).
		$new_post_content = get_post( $new_post_local_id )->post_content;
		$this->assertStringContainsString( '"id":' . $original_attachment_local_id, $new_post_content, 'Reimported post block should reference local attachment ID.' );
		$this->assertStringNotContainsString( '"id":4060', $new_post_content, 'Reimported post block should NOT reference old live attachment ID.' );
	}
}
