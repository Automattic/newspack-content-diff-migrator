<?php
/**
 * Integration tests for command cmd_migrate_live_content, taxonomy migration.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for command cmd_migrate_live_content, taxonomy migration.
 *
 * @group integration
 */
class CmdMigrateLiveContentTaxonomyTest extends IntegrationTestCase {
	/**
	 * @group taxonomy
	 */
	public function test_should_import_category_term_relationships(): void {
		global $wpdb;

		// Create post.
		$post = $this->create_post_fixture( [ 'ID' => 6001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create category term.
		$term = [
			'term_id'    => 101,
			'name'       => 'Test Category',
			'slug'       => 'test-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 101,
			'term_id'          => 101,
			'taxonomy'         => 'category',
			'description'      => 'Test category description.',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create relationship.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6001,
				'term_taxonomy_id' => 101,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6001, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );

		$this->assertContains( 'Test Category', $categories, 'Category should be assigned to post.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_import_post_tag_term_relationships(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create tag.
		$term = [
			'term_id'    => 201,
			'name'       => 'Test Tag',
			'slug'       => 'test-tag',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 201,
			'term_id'          => 201,
			'taxonomy'         => 'post_tag',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6002,
				'term_taxonomy_id' => 201,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6002, $this->source_hostname );
		$tags        = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );

		$this->assertContains( 'Test Tag', $tags, 'Tag should be assigned to post.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_import_custom_taxonomy_term_relationships(): void {
		global $wpdb;

		// Register custom taxonomy for test.
		register_taxonomy( 'brand', 'post', [ 'public' => true ] );

		$post = $this->create_post_fixture( [ 'ID' => 6003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create custom taxonomy term.
		$term = [
			'term_id'    => 301,
			'name'       => 'Acme Corp',
			'slug'       => 'acme-corp',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 301,
			'term_id'          => 301,
			'taxonomy'         => 'brand',
			'description'      => 'Acme Corporation',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6003,
				'term_taxonomy_id' => 301,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag,author,brand' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6003, $this->source_hostname );
		$brands      = wp_get_post_terms( $new_post_id, 'brand', [ 'fields' => 'names' ] );

		$this->assertContains( 'Acme Corp', $brands, 'Custom taxonomy term should be assigned.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_create_term_on_the_fly_when_not_exists_locally(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create a new category that doesn't exist locally.
		$term = [
			'term_id'    => 401,
			'name'       => 'Brand New Category',
			'slug'       => 'brand-new-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 401,
			'term_id'          => 401,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6004,
				'term_taxonomy_id' => 401,
			]
		);

		// Verify term doesn't exist locally.
		$this->assertNull( term_exists( 'Brand New Category', 'category' ), 'Term should not exist before migration.' );

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify term was created.
		$this->assertNotNull( term_exists( 'Brand New Category', 'category' ), 'Term should be created during migration.' );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6004, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Brand New Category', $categories );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_reuse_existing_term_when_already_exists_locally(): void {
		global $wpdb;

		// Create term locally first.
		$existing_term = wp_insert_term( 'Existing Category', 'category', [ 'slug' => 'existing-category' ] );
		$local_term_id = $existing_term['term_id'];

		$post = $this->create_post_fixture( [ 'ID' => 6005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create same term in live DB.
		$term = [
			'term_id'    => 501,
			'name'       => 'Existing Category',
			'slug'       => 'existing-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 501,
			'term_id'          => 501,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6005,
				'term_taxonomy_id' => 501,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify existing term was used (not duplicated).
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'name'       => 'Existing Category',
				'hide_empty' => false,
			] 
		);
		$this->assertCount( 1, $terms, 'Should not duplicate existing term.' );
		$this->assertEquals( $local_term_id, $terms[0]->term_id, 'Should use existing term ID.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_import_hierarchical_taxonomy_with_parent_term(): void {
		$fixture = $this->load_fixture( 'post-with-hierarchical-taxonomy' );
		$this->insert_live_data( $fixture );

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( $fixture['post']['ID'], $this->source_hostname );

		// Get categories.
		$parent_category     = get_category_by_slug( 'parent-category' );
		$child_category      = get_category_by_slug( 'child-category' );
		$grandchild_category = get_category_by_slug( 'grandchild-category' );
		
		// Verify category hierarchy.
		$this->assertEquals( $child_category->term_id, $grandchild_category->parent, 'Parent should be correct.' );
		$this->assertEquals( $parent_category->term_id, $child_category->parent, 'Parent should be correct.' );
		$this->assertEquals( 0, $parent_category->parent, 'Parent should be correct.' );
		
		// Post should have grandchild category assigned.
		$post_categories = wp_get_post_terms( $new_post_id, 'category' );
		$this->assertCount( 1, $post_categories, 'Should have 1 category assigned to post.' );
		$post_category = $post_categories[0];
		$this->assertEquals( $grandchild_category->term_id, $post_category->term_id, 'Grandchild Category should be assigned.' );
	}

	/**
	 * Tests that when importing a child term whose parent already exists locally,
	 * the child's parent ID is correctly set to the local parent's ID.
	 *
	 * @group taxonomy
	 */
	public function test_should_set_correct_parent_when_parent_term_already_exists_locally(): void {
		global $wpdb;

		// Create parent category locally first (not imported).
		$local_parent    = wp_insert_term( 'News', 'category', [ 'slug' => 'news' ] );
		$local_parent_id = $local_parent['term_id'];

		// Create post in live.
		$post = $this->create_post_fixture( [ 'ID' => 6050 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create parent category in live with DIFFERENT ID than local.
		$wpdb->insert( $this->live_table_prefix . 'terms', // phpcs:ignore
			[
				'term_id'    => 5001,
				'name'       => 'News',
				'slug'       => 'news',
				'term_group' => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', // phpcs:ignore
			[
				'term_taxonomy_id' => 5001,
				'term_id'          => 5001,
				'taxonomy'         => 'category',
				'description'      => 'News category',
				'parent'           => 0,
				'count'            => 1,
			]
		);

		// Create child category in live with parent = 5001 (live parent ID).
		$wpdb->insert( $this->live_table_prefix . 'terms', // phpcs:ignore
			[
				'term_id'    => 5002,
				'name'       => 'Local News',
				'slug'       => 'local-news',
				'term_group' => 0,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', // phpcs:ignore
			[
				'term_taxonomy_id' => 5002,
				'term_id'          => 5002,
				'taxonomy'         => 'category',
				'description'      => 'Local news category',
				'parent'           => 5001, // References live parent ID.
				'count'            => 1,
			]
		);

		// Assign child category to post.
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', // phpcs:ignore
			[
				'object_id'        => 6050,
				'term_taxonomy_id' => 5002,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was imported.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6050, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported.' );

		// Verify parent was reused (not duplicated).
		$news_terms = get_terms(
			[
				'taxonomy'   => 'category',
				'slug'       => 'news',
				'hide_empty' => false,
			]
		);
		$this->assertCount( 1, $news_terms, 'Parent category should not be duplicated.' );
		$this->assertEquals( $local_parent_id, $news_terms[0]->term_id, 'Should reuse existing local parent.' );

		// Verify child was imported with correct parent ID.
		$child_category = get_category_by_slug( 'local-news' );
		$this->assertNotNull( $child_category, 'Child category should be imported.' );
		$this->assertEquals( $local_parent_id, $child_category->parent, 'Child parent should reference local parent ID, not live ID.' );

		// Verify post has child category assigned.
		$post_categories = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'slugs' ] );
		$this->assertContains( 'local-news', $post_categories, 'Post should have child category assigned.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_import_termmeta_for_term(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 601,
			'name'       => 'Term With Meta',
			'slug'       => 'term-with-meta',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 601,
			'term_id'          => 601,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add termmeta.
		$termmeta = [
			[
				'term_id'    => 601,
				'meta_key'   => 'term_icon',
				'meta_value' => 'icon-star', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'term_id'    => 601,
				'meta_key'   => 'term_color',
				'meta_value' => '#ff0000', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $termmeta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'termmeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6006,
				'term_taxonomy_id' => 601,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Find the imported term.
		$term = get_term_by( 'slug', 'term-with-meta', 'category' );
		$this->assertNotFalse( $term, 'Term should be imported.' );

		// Verify termmeta.
		$this->assertEquals( 'icon-star', get_term_meta( $term->term_id, 'term_icon', true ) );
		$this->assertEquals( '#ff0000', get_term_meta( $term->term_id, 'term_color', true ) );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_save_old_id_termmeta_for_term(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6007 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 701,
			'name'       => 'Old ID Term',
			'slug'       => 'old-id-term',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 701,
			'term_id'          => 701,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6007,
				'term_taxonomy_id' => 701,
			]
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$term = get_term_by( 'slug', 'old-id-term', 'category' );
		$this->assertNotFalse( $term, 'Term should be imported.' );

		// Verify old_id termmeta.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_term_meta( $term->term_id, $meta_key, true );
		$this->assertEquals( 701, (int) $old_id, 'Term should have old_id termmeta.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_not_duplicate_term_when_same_term_imported_twice(): void {
		global $wpdb;

		// Create two posts with the same term.
		$post1 = $this->create_post_fixture( [ 'ID' => 6008 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 6009 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 801,
			'name'       => 'Shared Category',
			'slug'       => 'shared-category',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 801,
			'term_id'          => 801,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 2,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Both posts use same term.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6008,
				'term_taxonomy_id' => 801,
			] 
		);
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6009,
				'term_taxonomy_id' => 801,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify only one term exists.
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'slug'       => 'shared-category',
				'hide_empty' => false,
			] 
		);
		$this->assertCount( 1, $terms, 'Term should not be duplicated.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_handle_term_with_missing_term_taxonomy_record_in_live_db(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6010 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create term without term_taxonomy record.
		$term = [
			'term_id'    => 901,
			'name'       => 'Orphan Term',
			'slug'       => 'orphan-term',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Relationship references non-existent term_taxonomy.
		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6010,
				'term_taxonomy_id' => 901,
			] 
		);

		// Should not throw, just skip the invalid term.
		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6010, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should still be imported despite orphan term.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_merge_terms_with_same_name_different_case(): void {
		global $wpdb;

		// Create term locally with specific case.
		wp_insert_term( 'UPPERCASE', 'category', [ 'slug' => 'uppercase' ] );

		$post = $this->create_post_fixture( [ 'ID' => 6011 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create same term with different case in live.
		$term = [
			'term_id'    => 1001,
			'name'       => 'uppercase',
			'slug'       => 'uppercase',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 1001,
			'term_id'          => 1001,
			'taxonomy'         => 'category',
			'description'      => '',
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6011,
				'term_taxonomy_id' => 1001,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify only one term exists (merged by slug).
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'slug'       => 'uppercase',
				'hide_empty' => false,
			] 
		);
		$this->assertCount( 1, $terms, 'Terms with same slug should be merged.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_handle_term_with_empty_description(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 6012 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term = [
			'term_id'    => 1101,
			'name'       => 'No Description',
			'slug'       => 'no-description',
			'term_group' => 0,
		];
		$wpdb->insert( $this->live_table_prefix . 'terms', $term ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$term_taxonomy = [
			'term_taxonomy_id' => 1101,
			'term_id'          => 1101,
			'taxonomy'         => 'category',
			'description'      => '', // Empty description.
			'parent'           => 0,
			'count'            => 1,
		];
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', $term_taxonomy ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$wpdb->insert( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$this->live_table_prefix . 'term_relationships',
			[
				'object_id'        => 6012,
				'term_taxonomy_id' => 1101,
			] 
		);

		$this->run_search_command();
		$this->run_migrate_command();

		$term = get_term_by( 'slug', 'no-description', 'category' );
		$this->assertNotFalse( $term, 'Term with empty description should be imported.' );
		$this->assertEmpty( $term->description, 'Description should be empty.' );
	}

	/**
	 * @group taxonomy
	 */
	public function test_should_handle_taxonomy_with_no_terms(): void {
		global $wpdb;

		// Create post with no term relationships.
		$post = $this->create_post_fixture( [ 'ID' => 6013 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 6013, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post should be imported even without terms.' );

		$categories = wp_get_post_terms( $new_post_id, 'category' );
		// Post might have default category or none.
		$this->assertIsArray( $categories, 'Should return array even if empty.' );
	}

	/**
	 * Tests that unknown taxonomies are registered on-the-fly during migration.
	 * The DataImporter calls register_taxonomy() for unknown taxonomies.
	 *
	 * @group taxonomy
	 */
	public function test_should_register_unknown_taxonomy_on_the_fly(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 8001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create a term with a completely unknown taxonomy 'custom_flavor'.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 8101, 'name' => 'Spicy', 'slug' => 'spicy', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 8101, 'term_id' => 8101, 'taxonomy' => 'custom_flavor', 'description' => 'Spicy flavor', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 8001, 'term_taxonomy_id' => 8101 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag,author,custom_flavor' ] );

		// Verify taxonomy was registered and term was assigned.
		$this->assertTrue( taxonomy_exists( 'custom_flavor' ), 'Unknown taxonomy should be registered.' );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 8001, $this->source_hostname );
		$flavors     = wp_get_post_terms( $new_post_id, 'custom_flavor', [ 'fields' => 'names' ] );
		$this->assertContains( 'Spicy', $flavors, 'Term should be assigned after taxonomy registration.' );
	}

	/**
	 * Tests that terms with the same slug in different taxonomies are handled correctly.
	 * Each taxonomy should have its own term, not sharing.
	 *
	 * @group taxonomy
	 */
	public function test_should_handle_term_with_same_slug_in_different_taxonomies(): void {
		global $wpdb;

		// Register custom taxonomy.
		register_taxonomy( 'product_cat', 'post', [ 'public' => true ] );

		$post = $this->create_post_fixture( [ 'ID' => 8002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category with slug 'featured'.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 8201, 'name' => 'Featured Category', 'slug' => 'featured', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 8201, 'term_id' => 8201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 8002, 'term_taxonomy_id' => 8201 ] ); // phpcs:ignore

		// Create product_cat with same slug 'featured'.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 8202, 'name' => 'Featured Product', 'slug' => 'featured', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 8202, 'term_id' => 8202, 'taxonomy' => 'product_cat', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 8002, 'term_taxonomy_id' => 8202 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag,author,product_cat' ] );

		$new_post_id  = $this->logic->get_current_post_id_by_old_id( 8002, $this->source_hostname );
		$categories   = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'all' ] );
		$product_cats = wp_get_post_terms( $new_post_id, 'product_cat', [ 'fields' => 'all' ] );

		// Both taxonomies should have their own term.
		$cat_names  = wp_list_pluck( $categories, 'name' );
		$prod_names = wp_list_pluck( $product_cats, 'name' );

		$this->assertContains( 'Featured Category', $cat_names, 'Category term should be migrated.' );
		$this->assertContains( 'Featured Product', $prod_names, 'Product category term should be migrated.' );
	}

	/**
	 * Tests that term counts are recalculated after migration.
	 *
	 * @group taxonomy
	 */
	public function test_should_recalculate_term_counts_after_migration(): void {
		global $wpdb;

		// Create multiple posts with same category.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 11001, 'name' => 'Count Test Cat', 'slug' => 'count-test-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 11001, 'term_id' => 11001, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 3 ] ); // phpcs:ignore

		for ( $i = 1; $i <= 3; $i++ ) {
			$post = $this->create_post_fixture( [ 'ID' => 11000 + $i ] );
			$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
			$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11000 + $i, 'term_taxonomy_id' => 11001 ] ); // phpcs:ignore
		}

		$this->run_search_command();
		$this->run_migrate_command();

		// Get the local term and check its count.
		$local_term = get_term_by( 'slug', 'count-test-cat', 'category' );
		$this->assertEquals( 3, $local_term->count, 'Term count should be recalculated to 3.' );
	}

	/**
	 * Tests that category term counts are set correctly.
	 *
	 * @group taxonomy
	 */
	public function test_should_set_correct_count_for_category_terms(): void {
		global $wpdb;

		// Create category with 2 posts.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 11101, 'name' => 'Cat Count Test', 'slug' => 'cat-count-test', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 11101, 'term_id' => 11101, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		$post1 = $this->create_post_fixture( [ 'ID' => 11102 ] );
		$post2 = $this->create_post_fixture( [ 'ID' => 11103 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post1 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11102, 'term_taxonomy_id' => 11101 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11103, 'term_taxonomy_id' => 11101 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_term = get_term_by( 'slug', 'cat-count-test', 'category' );
		$this->assertEquals( 2, $local_term->count, 'Category count should be 2.' );
	}

	/**
	 * Tests that post_tag term counts are set correctly.
	 *
	 * @group taxonomy
	 */
	public function test_should_set_correct_count_for_post_tag_terms(): void {
		global $wpdb;

		// Create tag with 1 post.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 11201, 'name' => 'Tag Count Test', 'slug' => 'tag-count-test', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 11201, 'term_id' => 11201, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		$post = $this->create_post_fixture( [ 'ID' => 11202 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11202, 'term_taxonomy_id' => 11201 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$local_term = get_term_by( 'slug', 'tag-count-test', 'post_tag' );
		$this->assertEquals( 1, $local_term->count, 'Post tag count should be 1.' );
	}

	/**
	 * Tests that custom taxonomy term counts are set correctly.
	 *
	 * @group taxonomy
	 */
	public function test_should_set_correct_count_for_custom_taxonomy_terms(): void {
		global $wpdb;

		// Register custom taxonomy.
		register_taxonomy( 'region', 'post', [ 'public' => true ] );

		// Create custom taxonomy term with 4 posts.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 11301, 'name' => 'Europe', 'slug' => 'europe', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 11301, 'term_id' => 11301, 'taxonomy' => 'region', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		for ( $i = 1; $i <= 4; $i++ ) {
			$post = $this->create_post_fixture( [ 'ID' => 11300 + $i ] );
			$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore
			$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 11300 + $i, 'term_taxonomy_id' => 11301 ] ); // phpcs:ignore
		}

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag,author,region' ] );

		$local_term = get_term_by( 'slug', 'europe', 'region' );
		$this->assertEquals( 4, $local_term->count, 'Custom taxonomy count should be 4.' );
	}

	/**
	 * Tests that default taxonomies (category, post_tag, author) are migrated when no --custom-taxonomies-csv is provided.
	 *
	 * @group taxonomy
	 */
	public function test_should_migrate_default_taxonomies_when_no_custom_taxonomies_csv_provided(): void {
		global $wpdb;

		// Create post with category and tag.
		$post = $this->create_post_fixture( [ 'ID' => 1001 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 101, 'name' => 'Default Category', 'slug' => 'default-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 101, 'term_id' => 101, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1001, 'term_taxonomy_id' => 101 ] ); // phpcs:ignore

		// Create tag term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 102, 'name' => 'Default Tag', 'slug' => 'default-tag', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 102, 'term_id' => 102, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1001, 'term_taxonomy_id' => 102 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command(); // No custom-taxonomies-csv = defaults.

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1001, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$tags        = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );

		$this->assertContains( 'Default Category', $categories, 'Category should be migrated by default.' );
		$this->assertContains( 'Default Tag', $tags, 'Post tag should be migrated by default.' );
	}

	/**
	 * Tests that only specified taxonomies are migrated when --custom-taxonomies-csv is provided.
	 *
	 * @group taxonomy
	 */
	public function test_should_migrate_only_specified_taxonomies_when_custom_taxonomies_csv_provided(): void {
		global $wpdb;

		// Register custom taxonomy.
		register_taxonomy( 'brand', 'post', [ 'public' => true ] );

		$post = $this->create_post_fixture( [ 'ID' => 1002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category (should NOT be migrated).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 201, 'name' => 'Skip Category', 'slug' => 'skip-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 201, 'term_id' => 201, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1002, 'term_taxonomy_id' => 201 ] ); // phpcs:ignore

		// Create brand (should be migrated).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 202, 'name' => 'Include Brand', 'slug' => 'include-brand', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 202, 'term_id' => 202, 'taxonomy' => 'brand', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1002, 'term_taxonomy_id' => 202 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'brand' ] ); // Only migrate 'brand'.

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1002, $this->source_hostname );
		$brands      = wp_get_post_terms( $new_post_id, 'brand', [ 'fields' => 'names' ] );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );

		$this->assertContains( 'Include Brand', $brands, 'Brand should be migrated.' );
		$this->assertNotContains( 'Skip Category', $categories, 'Category should NOT be migrated when not in custom-taxonomies-csv.' );
	}

	/**
	 * Tests that non-existent taxonomies in live DB are unset from migration.
	 *
	 * @group taxonomy
	 */
	public function test_should_unset_taxonomy_from_migration_when_taxonomy_does_not_exist_in_live_db(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 1003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 301, 'name' => 'Existing Category', 'slug' => 'existing-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 301, 'term_id' => 301, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1003, 'term_taxonomy_id' => 301 ] ); // phpcs:ignore

		$this->run_search_command();
		// Provide a taxonomy that doesn't exist in live DB - 'nonexistent_taxonomy'.
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,nonexistent_taxonomy' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1003, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );

		// The valid taxonomy (category) should still be migrated.
		$this->assertContains( 'Existing Category', $categories, 'Category should be migrated despite nonexistent taxonomy in CSV.' );
	}

	/**
	 * Tests that a warning is shown when category is not in custom-taxonomies-csv.
	 * Note: In test_env mode, WP_CLI::confirm() is bypassed, so we just verify migration proceeds.
	 *
	 * @group taxonomy
	 */
	public function test_should_warn_when_category_not_in_custom_taxonomies_csv(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 1004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 401, 'name' => 'Warning Cat', 'slug' => 'warning-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 401, 'term_id' => 401, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1004, 'term_taxonomy_id' => 401 ] ); // phpcs:ignore

		$this->run_search_command();
		// Omit category from CSV - in test_env this proceeds without confirmation.
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'post_tag,author' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1004, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );

		// Category should NOT be migrated.
		$this->assertNotContains( 'Warning Cat', $categories, 'Category should not be migrated when omitted from custom-taxonomies-csv.' );
	}

	/**
	 * Tests that a warning is shown when post_tag is not in custom-taxonomies-csv.
	 *
	 * @group taxonomy
	 */
	public function test_should_warn_when_post_tag_not_in_custom_taxonomies_csv(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 1005 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create post_tag.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 501, 'name' => 'Warning Tag', 'slug' => 'warning-tag', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 501, 'term_id' => 501, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1005, 'term_taxonomy_id' => 501 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,author' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1005, $this->source_hostname );
		$tags        = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );

		// Post tag should NOT be migrated.
		$this->assertNotContains( 'Warning Tag', $tags, 'Post tag should not be migrated when omitted from custom-taxonomies-csv.' );
	}

	/**
	 * Tests that a warning is shown when author taxonomy is not in custom-taxonomies-csv.
	 *
	 * @group taxonomy
	 */
	public function test_should_warn_when_author_not_in_custom_taxonomies_csv(): void {
		global $wpdb;

		// Register 'author' taxonomy (used by Co-Authors Plus).
		register_taxonomy( 'author', 'post', [ 'public' => true ] );

		$post = $this->create_post_fixture( [ 'ID' => 1006 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create author term.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 601, 'name' => 'co-author-john', 'slug' => 'co-author-john', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 601, 'term_id' => 601, 'taxonomy' => 'author', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 1006, 'term_taxonomy_id' => 601 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command( [ 'custom-taxonomies-csv' => 'category,post_tag' ] );

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 1006, $this->source_hostname );
		$authors     = wp_get_post_terms( $new_post_id, 'author', [ 'fields' => 'names' ] );

		// Author term should NOT be migrated.
		$this->assertNotContains( 'co-author-john', $authors, 'Author term should not be migrated when omitted from custom-taxonomies-csv.' );
	}

	/**
	 * Tests that fix_hierarchical_taxonomies_parents fixes invalid parent IDs by setting them to 0.
	 * This tests the DataImporter method directly since the full command flow has PHPUnit isolation.
	 *
	 * @group taxonomy
	 */
	public function test_should_fix_local_hierarchical_taxonomy_with_invalid_parent_by_setting_to_zero(): void {
		global $wpdb;

		// Use a high number that definitely doesn't exist as a term.
		$invalid_parent_id = 9999999;

		// Create a local category with invalid parent.
		$local_term    = wp_insert_term( 'Local Broken Parent Cat', 'category', [ 'slug' => 'local-broken-parent' ] );
		$local_term_id = is_array( $local_term ) ? $local_term['term_id'] : $local_term;

		// Manually set invalid parent.
		$wpdb->update( // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
			$wpdb->term_taxonomy,
			[ 'parent' => $invalid_parent_id ],
			[
				'term_id'  => $local_term_id,
				'taxonomy' => 'category',
			]
		); // phpcs:ignore

		// Verify the invalid parent was set.
		$before = $wpdb->get_var( $wpdb->prepare( "SELECT parent FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'category'", $local_term_id ) ); // phpcs:ignore
		$this->assertEquals( $invalid_parent_id, (int) $before, 'Invalid parent should be set before test.' );

		// Call fix method directly (since the full command flow has PHPUnit transaction isolation issues).
		$fixed = $this->logic->get_data_importer()->fix_hierarchical_taxonomies_parents( $wpdb->prefix, [ 'category', 'post_tag', 'author' ] );
		$this->assertNotEmpty( $fixed, 'Fix should return non-empty array of fixed terms.' );

		// Verify local category parent was fixed to 0.
		$after = $wpdb->get_var( $wpdb->prepare( "SELECT parent FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'category'", $local_term_id ) ); // phpcs:ignore
		$this->assertEquals( 0, (int) $after, 'Invalid parent should be fixed to 0.' );
	}

	/**
	 * Tests that live hierarchical taxonomies with invalid parent IDs are fixed by setting parent to 0.
	 *
	 * @group taxonomy
	 */
	public function test_should_fix_live_hierarchical_taxonomy_with_invalid_parent_by_setting_to_zero(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 2002 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create live category with invalid parent (pointing to non-existent term_id 88888).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 701, 'name' => 'Live Broken Parent', 'slug' => 'live-broken-parent', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 701, 'term_id' => 701, 'taxonomy' => 'category', 'description' => '', 'parent' => 88888, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 2002, 'term_taxonomy_id' => 701 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify live table category parent was fixed.
		$live_term_taxonomy = $wpdb->get_row( $wpdb->prepare( "SELECT parent FROM {$this->live_table_prefix}term_taxonomy WHERE term_id = %d", 701 ), ARRAY_A ); // phpcs:ignore
		$this->assertEquals( 0, (int) $live_term_taxonomy['parent'], 'Invalid parent in live DB should be fixed to 0.' );

		// Verify the post was migrated and category was assigned.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2002, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Live Broken Parent', $categories, 'Category should still be migrated after parent fix.' );
	}

	/**
	 * Tests that hierarchical taxonomies with valid parents are NOT modified.
	 *
	 * @group taxonomy
	 */
	public function test_should_not_modify_hierarchical_taxonomy_with_valid_parent(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 2003 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create parent category first.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 801, 'name' => 'Parent Cat', 'slug' => 'parent-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 801, 'term_id' => 801, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		// Create child category with valid parent.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 802, 'name' => 'Child Cat', 'slug' => 'child-cat', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 802, 'term_id' => 802, 'taxonomy' => 'category', 'description' => '', 'parent' => 801, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 2003, 'term_taxonomy_id' => 802 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify parent was NOT changed.
		$live_term_taxonomy = $wpdb->get_row( $wpdb->prepare( "SELECT parent FROM {$this->live_table_prefix}term_taxonomy WHERE term_id = %d", 802 ), ARRAY_A ); // phpcs:ignore
		$this->assertEquals( 801, (int) $live_term_taxonomy['parent'], 'Valid parent should NOT be modified.' );

		// Verify category hierarchy was imported correctly.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2003, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'all' ] );
		$child_cat   = null;
		foreach ( $categories as $cat ) {
			if ( 'Child Cat' === $cat->name ) {
				$child_cat = $cat;
				break;
			}
		}
		$this->assertNotNull( $child_cat, 'Child category should be migrated.' );
		$this->assertNotEquals( 0, $child_cat->parent, 'Child category should have a parent in local DB.' );
	}

	/**
	 * Tests that deeply nested hierarchical taxonomy with a broken chain is handled.
	 * A "broken chain" means one middle parent is missing/invalid.
	 *
	 * @group taxonomy
	 */
	public function test_should_handle_deeply_nested_hierarchical_taxonomy_with_broken_chain(): void {
		global $wpdb;

		$post = $this->create_post_fixture( [ 'ID' => 2004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create grandparent (valid).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 901, 'name' => 'Grandparent', 'slug' => 'grandparent', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 901, 'term_id' => 901, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore

		// Intentionally skip creating parent (term_id 902) - it's missing/deleted.

		// Create child pointing to missing parent 902 (broken chain).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 903, 'name' => 'Orphan Child', 'slug' => 'orphan-child', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 903, 'term_id' => 903, 'taxonomy' => 'category', 'description' => '', 'parent' => 902, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 2004, 'term_taxonomy_id' => 903 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify orphan child's parent was fixed to 0.
		$live_term_taxonomy = $wpdb->get_row( $wpdb->prepare( "SELECT parent FROM {$this->live_table_prefix}term_taxonomy WHERE term_id = %d", 903 ), ARRAY_A ); // phpcs:ignore
		$this->assertEquals( 0, (int) $live_term_taxonomy['parent'], 'Orphan child parent should be fixed to 0.' );

		// Verify category was still migrated.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 2004, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Orphan Child', $categories, 'Orphan child category should be migrated.' );
	}

	/**
	 * Tests that when a post is reimported after a tag is removed on live,
	 * the reimported post no longer has that tag.
	 *
	 * @group taxonomy
	 */
	public function test_should_not_have_the_tag_assigned_when_reimporting_modified_post_with_tag_removed_on_live(): void {
		global $wpdb;

		// Create post with a tag.
		$post = $this->create_post_fixture(
			[
				'ID'            => 7001,
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create tag.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 7101, 'name' => 'Tag To Remove', 'slug' => 'tag-to-remove', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 7101, 'term_id' => 7101, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7001, 'term_taxonomy_id' => 7101 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 7001, $this->source_hostname );
		$tags        = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );
		$this->assertContains( 'Tag To Remove', $tags, 'Tag should be assigned after initial import.' );

		// Remove tag relationship on live and modify post.
		$wpdb->delete( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7001, 'term_taxonomy_id' => 7101 ] ); // phpcs:ignore
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 7001 ]
		);

		// Fresh run-state for new migration cycle.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify tag was removed after reimport.
		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( 7001, $this->source_hostname );
		$tags_after         = wp_get_post_terms( $reimported_post_id, 'post_tag', [ 'fields' => 'names' ] );
		$this->assertNotContains( 'Tag To Remove', $tags_after, 'Tag should be removed after reimport.' );
	}

	/**
	 * Tests that when a post has multiple tags and one is removed on live,
	 * the reimported post has only the remaining tags.
	 *
	 * @group taxonomy
	 */
	public function test_should_not_have_the_tag_assigned_when_post_has_multiple_tags_and_one_removed(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 7002,
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create three tags.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 7201, 'name' => 'Tag Alpha', 'slug' => 'tag-alpha', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 7201, 'term_id' => 7201, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7002, 'term_taxonomy_id' => 7201 ] ); // phpcs:ignore

		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 7202, 'name' => 'Tag Beta', 'slug' => 'tag-beta', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 7202, 'term_id' => 7202, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7002, 'term_taxonomy_id' => 7202 ] ); // phpcs:ignore

		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 7203, 'name' => 'Tag Gamma', 'slug' => 'tag-gamma', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 7203, 'term_id' => 7203, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7002, 'term_taxonomy_id' => 7203 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 7002, $this->source_hostname );
		$tags        = wp_get_post_terms( $new_post_id, 'post_tag', [ 'fields' => 'names' ] );
		$this->assertCount( 3, $tags, 'Post should have 3 tags after initial import.' );

		// Remove Tag Beta on live and modify post.
		$wpdb->delete( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7002, 'term_taxonomy_id' => 7202 ] ); // phpcs:ignore
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 7002 ]
		);

		// Fresh run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( 7002, $this->source_hostname );
		$tags_after         = wp_get_post_terms( $reimported_post_id, 'post_tag', [ 'fields' => 'names' ] );

		$this->assertCount( 2, $tags_after, 'Post should have 2 tags after reimport.' );
		$this->assertContains( 'Tag Alpha', $tags_after, 'Tag Alpha should remain.' );
		$this->assertNotContains( 'Tag Beta', $tags_after, 'Tag Beta should be removed.' );
		$this->assertContains( 'Tag Gamma', $tags_after, 'Tag Gamma should remain.' );
	}

	/**
	 * Tests that when a post is reimported after a category is removed on live,
	 * the reimported post no longer has that category.
	 *
	 * @group taxonomy
	 */
	public function test_should_not_have_the_category_assigned_when_reimporting_modified_post_with_category_removed_on_live(): void {
		global $wpdb;

		$post = $this->create_post_fixture(
			[
				'ID'            => 7003,
				'post_modified' => '2024-01-01 10:00:00',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 7301, 'name' => 'Category To Remove', 'slug' => 'category-to-remove', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 7301, 'term_id' => 7301, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7003, 'term_taxonomy_id' => 7301 ] ); // phpcs:ignore

		$this->run_search_command();
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 7003, $this->source_hostname );
		$categories  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertContains( 'Category To Remove', $categories, 'Category should be assigned after initial import.' );

		// Remove category relationship on live and modify post.
		$wpdb->delete( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7003, 'term_taxonomy_id' => 7301 ] ); // phpcs:ignore
		$wpdb->update( // phpcs:ignore
			$this->live_table_prefix . 'posts',
			[
				'post_modified'     => '2024-06-01 10:00:00',
				'post_modified_gmt' => '2024-06-01 10:00:00',
			],
			[ 'ID' => 7003 ]
		);

		// Fresh run-state.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new \Newspack\ContentDiffMigrator\Logic\RunState( $this->temp_data_dir . '/' . $this->source_hostname . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		$this->run_search_command();
		$this->run_migrate_command();

		$reimported_post_id = $this->logic->get_current_post_id_by_old_id( 7003, $this->source_hostname );
		$categories_after   = wp_get_post_terms( $reimported_post_id, 'category', [ 'fields' => 'names' ] );
		$this->assertNotContains( 'Category To Remove', $categories_after, 'Category should be removed after reimport.' );
	}

	/**
	 * Tests that categories that exist in live but are not assigned to any imported post
	 * are not created locally.
	 *
	 * @group taxonomy
	 */
	public function test_should_not_import_category_when_not_assigned_to_any_post(): void {
		global $wpdb;

		// Create a post that DOES get imported.
		$post = $this->create_post_fixture( [ 'ID' => 7004 ] );
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore

		// Create category assigned to the post.
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 7401, 'name' => 'Used Category', 'slug' => 'used-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 7401, 'term_id' => 7401, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_relationships', [ 'object_id' => 7004, 'term_taxonomy_id' => 7401 ] ); // phpcs:ignore

		// Create unused category (exists in live but NOT assigned to any post).
		$wpdb->insert( $this->live_table_prefix . 'terms', [ 'term_id' => 7402, 'name' => 'Unused Category', 'slug' => 'unused-category', 'term_group' => 0 ] ); // phpcs:ignore
		$wpdb->insert( $this->live_table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => 7402, 'term_id' => 7402, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ] ); // phpcs:ignore
		// No term_relationships for 7402 - it's not used.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify used category was imported.
		$used_term = get_term_by( 'slug', 'used-category', 'category' );
		$this->assertNotFalse( $used_term, 'Used category should be imported.' );

		// Verify unused category was NOT imported.
		$unused_term = get_term_by( 'slug', 'unused-category', 'category' );
		$this->assertFalse( $unused_term, 'Unused category should NOT be imported.' );
	}
}
