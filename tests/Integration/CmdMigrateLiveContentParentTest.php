<?php
/**
 * Integration tests for command cmd_migrate_live_content, post parent update.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for command cmd_migrate_live_content, post parent update.
 *
 * @group integration
 */
class CmdMigrateLiveContentParentTest extends IntegrationTestCase {
	/**
	 * @group parent
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
	 * @group parent
	 */
	public function test_should_find_parent_id_by_old_id_postmeta(): void {
		global $wpdb;

		// Create parent that was imported in a previous batch.
		$parent_id = wp_insert_post(
			[
				'post_title'  => 'Pre-existing Parent',
				'post_status' => 'publish',
				'post_type'   => 'page',
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
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $child ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		$new_child_id = $this->logic->get_current_post_id_by_old_id( 8102, $this->source_hostname );
		$child_post   = get_post( $new_child_id );

		$this->assertEquals( $parent_id, $child_post->post_parent, 'Should find parent by old_id postmeta.' );
	}

	/**
	 * @group parent
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
	 * @group parent
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
	 * @group parent
	 */
	public function test_should_not_update_post_parent_when_already_zero(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'          => 8401,
				'post_parent' => 0,
				'post_type'   => 'page',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'page' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 8401, $this->source_hostname );
		$new_post    = get_post( $new_post_id );

		$this->assertEquals( 0, $new_post->post_parent, 'Post parent should remain 0.' );
	}
}
