<?php
/**
 * Integration tests for cmd_migrate_live_content command, content migration.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for command cmd_migrate_live_content, content migration.
 *
 * @group integration
 */
class CmdMigrateLiveContentContentTest extends IntegrationTestCase {
	/**
	 * Tests that postmeta with serialized array values is imported correctly.
	 *
	 * @group content
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
	 * @group content
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
	 * Tests that post with empty content is imported correctly.
	 *
	 * @group content
	 */
	public function test_should_import_post_with_empty_content(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'           => 14003,
				'post_title'   => 'Post With No Content',
				'post_content' => '',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14003, $this->source_hostname );
		$local_post  = get_post( $new_post_id );

		$this->assertEquals( 'Post With No Content', $local_post->post_title, 'Title should be imported.' );
		$this->assertEquals( '', $local_post->post_content, 'Empty content should remain empty.' );
	}

	/**
	 * Tests that post with empty title is imported correctly.
	 *
	 * @group content
	 */
	public function test_should_import_post_with_empty_title(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'           => 14004,
				'post_title'   => '',
				'post_content' => '<p>Content without title.</p>',
				'post_name'    => 'no-title-post',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14004, $this->source_hostname );
		$local_post  = get_post( $new_post_id );

		$this->assertEquals( '', $local_post->post_title, 'Empty title should remain empty.' );
		$this->assertStringContainsString( 'Content without title', $local_post->post_content, 'Content should be imported.' );
	}

	/**
	 * Tests that post with very long content is imported correctly.
	 *
	 * @group content
	 */
	public function test_should_import_post_with_very_long_content(): void {
		global $wpdb;

		// Create content with 100KB of text.
		$long_content = str_repeat( '<p>' . str_repeat( 'Lorem ipsum dolor sit amet. ', 100 ) . '</p>', 50 );

		$post = $this->create_post_fixture(
			[
				'ID'           => 14005,
				'post_title'   => 'Very Long Post',
				'post_content' => $long_content,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 14005, $this->source_hostname );
		$local_post  = get_post( $new_post_id );

		$this->assertEquals( strlen( $long_content ), strlen( $local_post->post_content ), 'Long content should be fully imported.' );
	}
}
