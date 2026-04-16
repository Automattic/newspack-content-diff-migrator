<?php
/**
 * Tests for ContentDiffLogic class.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Unit\Logic;

use Newspack\ContentDiffMigrator\Logic\BlockUpdater;
use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;
use Newspack\ContentDiffMigrator\Logic\DataImporter;
use Newspack\ContentDiffMigrator\Utils\DB;
use Newspack\ContentDiffMigrator\Utils\Logger;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Unit tests for ContentDiffLogic.
 */
class ContentDiffLogicTest extends WP_UnitTestCase {

	/**
	 * Instance of ContentDiffLogic.
	 *
	 * @var ContentDiffLogic
	 */
	private ContentDiffLogic $logic;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;

		// Disable logger output during tests.
		Logger::configure( false );

		$this->logic = new ContentDiffLogic( $wpdb );
	}

	/**
	 * Helper to invoke private methods via reflection.
	 *
	 * @param object $test_object The object instance.
	 * @param string $method_name The method name.
	 * @param array  $args        The method arguments.
	 *
	 * @return mixed The method result.
	 */
	private function invoke_private_method( object $test_object, string $method_name, array $args = [] ) {
		$method = new ReflectionMethod( get_class( $test_object ), $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $test_object, $args );
	}

	/**
	 * Helper to create a mock wpdb that fails on insert/update.
	 *
	 * @param string $method         The method to mock ('insert' or 'update').
	 * @param string $table_property The table property name.
	 *
	 * @return \wpdb Mock wpdb object.
	 */
	private function create_failing_wpdb_mock( string $method = 'insert', string $table_property = 'posts' ): \wpdb {
		$mock_wpdb                  = $this->createMock( \wpdb::class );
		$mock_wpdb->$table_property = 'wp_' . $table_property;
		$mock_wpdb->method( $method )->willReturn( 0 );
		$mock_wpdb->last_error = 'Mock DB Error';
		return $mock_wpdb;
	}

	/**
	 * Helper to build a post row for testing.
	 *
	 * @param array $overrides Optional field overrides.
	 *
	 * @return array Post row data.
	 */
	private function build_test_post_row( array $overrides = [] ): array {
		$unique = uniqid();
		return array_merge(
			[
				'ID'            => 0,
				'post_name'     => 'test-post-' . $unique,
				'post_title'    => 'Test Post ' . $unique,
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_date'     => '2025-01-01 00:00:00',
				'post_modified' => '2025-01-01 00:00:00',
				'post_author'   => 1,
				'post_content'  => 'Test content',
				'post_excerpt'  => '',
				'comment_count' => 0,
			],
			$overrides
		);
	}

	/**
	 * =========================================================================
	 * Constructor Tests
	 * =========================================================================
	 */
	public function test_constructor_should_use_default_dependencies_when_none_provided(): void {
		global $wpdb;
		$logic = new ContentDiffLogic( $wpdb );

		$this->assertInstanceOf( ContentDiffLogic::class, $logic );
		$this->assertInstanceOf( DataImporter::class, $logic->get_data_importer() );
	}

	public function test_constructor_should_use_injected_block_updater(): void {
		global $wpdb;
		$mock_block_updater = $this->createMock( BlockUpdater::class );

		$logic = new ContentDiffLogic( $wpdb, $mock_block_updater );

		// Verify by checking that the injected mock is used (indirectly via update_blocks_ids).
		$this->assertInstanceOf( ContentDiffLogic::class, $logic );
	}

	public function test_constructor_should_use_injected_data_importer(): void {
		global $wpdb;
		$mock_data_importer = $this->createMock( DataImporter::class );

		$logic = new ContentDiffLogic( $wpdb, null, $mock_data_importer );

		$this->assertSame( $mock_data_importer, $logic->get_data_importer() );
	}

	public function test_constructor_should_use_injected_db(): void {
		global $wpdb;
		$mock_db = $this->createMock( DB::class );

		$logic = new ContentDiffLogic( $wpdb, null, null, $mock_db );

		$this->assertInstanceOf( ContentDiffLogic::class, $logic );
	}

	/**
	 * =========================================================================
	 * Getter Tests
	 * =========================================================================
	 */
	public function test_get_data_importer_should_return_data_importer_instance(): void {
		$result = $this->logic->get_data_importer();

		$this->assertInstanceOf( DataImporter::class, $result );
	}

	/**
	 * =========================================================================
	 * Static get_old_id_meta_key Tests
	 * =========================================================================
	 */
	public function test_get_old_id_meta_key_should_return_prefixed_hostname(): void {
		$result = ContentDiffLogic::get_old_id_meta_key( 'www.example.com' );

		$this->assertSame( 'newspackcontentdiff_oldid_www.example.com', $result );
	}

	public function test_get_old_id_meta_key_should_sanitize_hostname_with_protocol(): void {
		// The method prepends https:// and parses, so it should handle edge cases.
		$result = ContentDiffLogic::get_old_id_meta_key( 'example.com/path' );

		// wp_parse_url with PHP_URL_HOST extracts just the host.
		$this->assertSame( 'newspackcontentdiff_oldid_example.com', $result );
	}

	public function test_get_old_id_meta_key_should_throw_on_empty_hostname(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Source hostname is required.' );

		ContentDiffLogic::get_old_id_meta_key( '' );
	}

	/**
	 * =========================================================================
	 * get_migrated_source_hostnames Tests
	 * =========================================================================
	 */
	public function test_get_migrated_source_hostnames_should_return_empty_array_when_no_meta_keys(): void {
		$result = $this->logic->get_migrated_source_hostnames();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_get_migrated_source_hostnames_should_return_hostnames_from_postmeta(): void {
		$post_id  = self::factory()->post->create();
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'source1.example.com' );
		update_post_meta( $post_id, $meta_key, 123 );

		$result = $this->logic->get_migrated_source_hostnames();

		$this->assertContains( 'source1.example.com', $result );
	}

	public function test_get_migrated_source_hostnames_should_return_hostnames_from_usermeta(): void {
		$user_id  = self::factory()->user->create();
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'source2.example.com' );
		update_user_meta( $user_id, $meta_key, 456 );

		$result = $this->logic->get_migrated_source_hostnames();

		$this->assertContains( 'source2.example.com', $result );
	}

	public function test_get_migrated_source_hostnames_should_return_unique_hostnames_from_both_tables(): void {
		$post_id  = self::factory()->post->create();
		$user_id  = self::factory()->user->create();
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'shared.example.com' );
		update_post_meta( $post_id, $meta_key, 123 );
		update_user_meta( $user_id, $meta_key, 456 );

		$result = $this->logic->get_migrated_source_hostnames();

		// Should be unique, not duplicated.
		$this->assertCount( 1, array_filter( $result, fn( $h ) => 'shared.example.com' === $h ) );
	}

	/**
	 * =========================================================================
	 * get_posts_rows_for_content_diff Tests
	 * =========================================================================
	 */
	public function test_get_posts_rows_for_content_diff_should_return_posts_matching_types_and_statuses(): void {
		global $wpdb;
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
			] 
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
			] 
		);

		$result = $this->logic->get_posts_rows_for_content_diff(
			$wpdb->posts,
			[ 'post', 'page' ],
			[ 'publish', 'draft' ]
		);

		$this->assertGreaterThanOrEqual( 2, count( $result ) );
	}

	public function test_get_posts_rows_for_content_diff_should_return_empty_array_when_no_matches(): void {
		global $wpdb;

		$result = $this->logic->get_posts_rows_for_content_diff(
			$wpdb->posts,
			[ 'nonexistent_type' ],
			[ 'publish' ]
		);

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_get_posts_rows_for_content_diff_should_return_expected_columns(): void {
		global $wpdb;
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
			] 
		);

		$result = $this->logic->get_posts_rows_for_content_diff(
			$wpdb->posts,
			[ 'post' ],
			[ 'publish' ]
		);

		$this->assertNotEmpty( $result );
		$first = $result[0];
		$this->assertArrayHasKey( 'ID', $first );
		$this->assertArrayHasKey( 'post_name', $first );
		$this->assertArrayHasKey( 'post_title', $first );
		$this->assertArrayHasKey( 'post_status', $first );
		$this->assertArrayHasKey( 'post_type', $first );
		$this->assertArrayHasKey( 'post_date', $first );
		$this->assertArrayHasKey( 'post_modified', $first );
	}

	public function test_get_posts_rows_for_content_diff_with_limit_should_return_limited_results(): void {
		global $wpdb;

		// Create 5 posts.
		for ( $i = 0; $i < 5; $i++ ) {
			self::factory()->post->create(
				[
					'post_type'   => 'post',
					'post_status' => 'publish',
				]
			);
		}

		$result = $this->logic->get_posts_rows_for_content_diff(
			$wpdb->posts,
			[ 'post' ],
			[ 'publish' ],
			2 // Limit to 2.
		);

		$this->assertCount( 2, $result );
	}

	public function test_get_posts_rows_for_content_diff_with_offset_should_skip_rows(): void {
		global $wpdb;

		// Create 5 posts with predictable titles.
		$post_ids = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$post_ids[] = self::factory()->post->create(
				[
					'post_type'   => 'post',
					'post_status' => 'publish',
					'post_title'  => "Post {$i}",
				]
			);
		}
		sort( $post_ids ); // IDs in ascending order.

		// Get posts with offset 2, limit 2.
		$result = $this->logic->get_posts_rows_for_content_diff(
			$wpdb->posts,
			[ 'post' ],
			[ 'publish' ],
			2,
			2
		);

		$this->assertCount( 2, $result );
		// Results should be ordered by ID ASC, so offset 2 should skip first 2 IDs.
		$result_ids = array_column( $result, 'ID' );
		$this->assertContains( (string) $post_ids[2], $result_ids );
		$this->assertContains( (string) $post_ids[3], $result_ids );
	}

	public function test_get_posts_rows_for_content_diff_batching_should_cover_all_rows(): void {
		global $wpdb;

		// Create 5 posts.
		$created_ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$created_ids[] = self::factory()->post->create(
				[
					'post_type'   => 'post',
					'post_status' => 'publish',
				]
			);
		}

		// Fetch in batches of 2.
		$all_results = [];
		$batch_size  = 2;
		$offset      = 0;
		do {
			$batch       = $this->logic->get_posts_rows_for_content_diff(
				$wpdb->posts,
				[ 'post' ],
				[ 'publish' ],
				$batch_size,
				$offset
			);
			$all_results = array_merge( $all_results, $batch );
			$batch_count = count( $batch );
			$offset     += $batch_size;
		} while ( $batch_count === $batch_size );

		// Should have fetched all 5 posts.
		$fetched_ids = array_map( 'intval', array_column( $all_results, 'ID' ) );
		foreach ( $created_ids as $id ) {
			$this->assertContains( $id, $fetched_ids );
		}
	}

	/**
	 * =========================================================================
	 * count_posts_for_content_diff Tests
	 * =========================================================================
	 */
	public function test_count_posts_for_content_diff_should_return_correct_count(): void {
		global $wpdb;

		// Create 3 posts.
		for ( $i = 0; $i < 3; $i++ ) {
			self::factory()->post->create(
				[
					'post_type'   => 'post',
					'post_status' => 'publish',
				]
			);
		}

		$count = $this->logic->count_posts_for_content_diff(
			$wpdb->posts,
			[ 'post' ],
			[ 'publish' ]
		);

		$this->assertGreaterThanOrEqual( 3, $count );
	}

	public function test_count_posts_for_content_diff_should_return_zero_for_no_matches(): void {
		global $wpdb;

		$count = $this->logic->count_posts_for_content_diff(
			$wpdb->posts,
			[ 'nonexistent_type' ],
			[ 'publish' ]
		);

		$this->assertSame( 0, $count );
	}

	public function test_count_posts_for_content_diff_should_match_get_posts_count(): void {
		global $wpdb;

		// Create some posts.
		for ( $i = 0; $i < 4; $i++ ) {
			self::factory()->post->create(
				[
					'post_type'   => 'post',
					'post_status' => 'publish',
				]
			);
		}

		$count  = $this->logic->count_posts_for_content_diff( $wpdb->posts, [ 'post' ], [ 'publish' ] );
		$result = $this->logic->get_posts_rows_for_content_diff( $wpdb->posts, [ 'post' ], [ 'publish' ] );

		$this->assertSame( $count, count( $result ) );
	}

	/**
	 * =========================================================================
	 * get_imported_post_id_mapping_from_db Tests (Attachments)
	 * =========================================================================
	 */
	public function test_get_imported_post_id_mapping_from_db_should_return_attachments_when_requested(): void {
		$attachment_id = self::factory()->attachment->create();
		$meta_key      = ContentDiffLogic::get_old_id_meta_key( 'attach.example.com' );
		$old_id        = 999;
		update_post_meta( $attachment_id, $meta_key, $old_id );

		$result = $this->logic->get_imported_post_id_mapping_from_db( 'attach.example.com', [ 'attachment' ] );

		$this->assertArrayHasKey( (string) $old_id, $result );
		$this->assertEquals( $attachment_id, $result[ (string) $old_id ] );
	}

	public function test_get_imported_post_id_mapping_from_db_should_return_empty_array_when_no_attachments(): void {
		$result = $this->logic->get_imported_post_id_mapping_from_db( 'noattach.example.com', [ 'attachment' ] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_get_imported_post_id_mapping_from_db_should_only_include_requested_post_type(): void {
		// Create a post (not attachment) with the meta.
		$post_id  = self::factory()->post->create();
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'attach2.example.com' );
		update_post_meta( $post_id, $meta_key, 888 );

		$result = $this->logic->get_imported_post_id_mapping_from_db( 'attach2.example.com', [ 'attachment' ] );

		// Should not include the post since it's not an attachment.
		$this->assertEmpty( $result );
	}

	/**
	 * =========================================================================
	 * get_imported_post_id_mapping_from_db Tests (Posts/CPTs)
	 * =========================================================================
	 */
	public function test_get_imported_post_id_mapping_from_db_should_return_posts_when_requested(): void {
		$post_id  = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'postmap.example.com' );
		$old_id   = 777;
		update_post_meta( $post_id, $meta_key, $old_id );

		$result = $this->logic->get_imported_post_id_mapping_from_db( 'postmap.example.com', [ 'post' ] );

		$this->assertArrayHasKey( (string) $old_id, $result );
		$this->assertEquals( $post_id, $result[ (string) $old_id ] );
	}

	public function test_get_imported_post_id_mapping_from_db_should_filter_by_custom_post_types(): void {
		$post_id  = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'customtype.example.com' );
		update_post_meta( $post_id, $meta_key, 111 );
		update_post_meta( $page_id, $meta_key, 222 );

		// Only request 'page' type.
		$result = $this->logic->get_imported_post_id_mapping_from_db( 'customtype.example.com', [ 'page' ] );

		$this->assertArrayHasKey( '222', $result );
		$this->assertArrayNotHasKey( '111', $result );
	}

	public function test_get_imported_post_id_mapping_from_db_should_return_multiple_post_types(): void {
		$post_id       = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$page_id       = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$attachment_id = self::factory()->attachment->create();
		$meta_key      = ContentDiffLogic::get_old_id_meta_key( 'default.example.com' );
		update_post_meta( $post_id, $meta_key, 333 );
		update_post_meta( $page_id, $meta_key, 444 );
		update_post_meta( $attachment_id, $meta_key, 555 );

		// Request both post and page types.
		$result = $this->logic->get_imported_post_id_mapping_from_db( 'default.example.com', [ 'post', 'page' ] );

		// Should include post and page but NOT attachment.
		$this->assertArrayHasKey( '333', $result );
		$this->assertArrayHasKey( '444', $result );
		$this->assertArrayNotHasKey( '555', $result );
	}

	public function test_get_imported_post_id_mapping_from_db_should_return_empty_array_when_no_matches(): void {
		$result = $this->logic->get_imported_post_id_mapping_from_db( 'nomatch.example.com', [ 'post' ] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * =========================================================================
	 * filter_new_live_ids Tests
	 * =========================================================================
	 */
	public function test_filter_new_live_ids_should_return_empty_array_when_all_mapped(): void {
		$live_posts = [
			[
				'ID'            => '1',
				'post_modified' => '2025-01-01',
			],
		];
		// All live IDs are in the mapping.
		$old_id_map = [ 1 => 10 ];

		$result = $this->logic->filter_new_live_ids( $live_posts, $old_id_map );

		$this->assertEmpty( $result );
	}

	public function test_filter_new_live_ids_should_return_all_ids_when_mapping_empty(): void {
		$live_posts = [
			[
				'ID'            => '1',
				'post_modified' => '2025-01-01',
			],
			[
				'ID'            => '2',
				'post_modified' => '2025-01-01',
			],
		];

		$result = $this->logic->filter_new_live_ids( $live_posts, [] );

		$this->assertCount( 2, $result );
		$this->assertContains( 1, $result );
		$this->assertContains( 2, $result );
	}

	public function test_filter_new_live_ids_should_handle_empty_live_array(): void {
		$result = $this->logic->filter_new_live_ids( [], [ 1 => 10 ] );

		$this->assertEmpty( $result );
	}

	public function test_filter_new_live_ids_should_cast_ids_to_int(): void {
		$live_posts = [
			[
				'ID'            => '123',
				'post_modified' => '2025-01-01',
			],
		];

		$result = $this->logic->filter_new_live_ids( $live_posts, [] );

		$this->assertSame( 123, $result[0] );
	}

	/**
	 * =========================================================================
	 * filter_modified_live_ids Tests (uses old_id mapping)
	 * =========================================================================
	 */
	public function test_filter_modified_live_ids_should_return_pairs_with_newer_live_modified_date(): void {
		$live_posts  = [
			[
				'ID'            => '1',
				'post_modified' => '2025-06-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];
		$local_posts = [
			[
				'ID'            => '10',
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];
		// old_id mapping: live_id 1 => local_id 10.
		$old_id_map = [ 1 => 10 ];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertCount( 1, $result );
		$this->assertSame( 1, $result[0]['live_id'] );
		$this->assertSame( 10, $result[0]['local_id'] );
	}

	public function test_filter_modified_live_ids_should_return_empty_when_not_in_mapping(): void {
		$live_posts  = [
			[
				'ID'            => '1',
				'post_modified' => '2025-06-01',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];
		$local_posts = [
			[
				'ID'            => '10',
				'post_modified' => '2025-01-01',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];
		// Live ID 1 is NOT in mapping - it's a new post, not modified.
		$old_id_map = [];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertEmpty( $result );
	}

	public function test_filter_modified_live_ids_should_return_empty_when_live_not_newer(): void {
		$live_posts  = [
			[
				'ID'            => '1',
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];
		$local_posts = [
			[
				'ID'            => '10',
				'post_modified' => '2025-06-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];
		$old_id_map  = [ 1 => 10 ];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertEmpty( $result );
	}

	public function test_filter_modified_live_ids_should_handle_empty_arrays(): void {
		$this->assertEmpty( $this->logic->filter_modified_live_ids( [], [], [] ) );
		$this->assertEmpty(
			$this->logic->filter_modified_live_ids(
				[],
				[
					[
						'ID'            => '1',
						'post_modified' => '2025-01-01',
						'post_status'   => 'publish',
						'post_author'   => 1,
					],
				],
				[]
			) 
		);
	}

	public function test_filter_modified_live_ids_should_detect_status_change(): void {
		$live_posts  = [
			[
				'ID'            => '1',
				'post_modified' => '2025-01-01',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];
		$local_posts = [
			[
				'ID'            => '10',
				'post_modified' => '2025-01-01',
				'post_status'   => 'draft', // Different status.
				'post_author'   => 1,
			],
		];
		$old_id_map  = [ 1 => 10 ];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertCount( 1, $result );
		$this->assertSame( 10, $result[0]['local_id'] );
	}

	public function test_filter_modified_live_ids_should_detect_comment_count_change(): void {
		$live_posts  = [
			[
				'ID'            => '1',
				'post_modified' => '2025-01-01',
				'post_status'   => 'publish',
				'post_author'   => 1,
				'comment_count' => 5,
			],
		];
		$local_posts = [
			[
				'ID'            => '10',
				'post_modified' => '2025-01-01',
				'post_status'   => 'publish',
				'post_author'   => 1,
				'comment_count' => 3,
			],
		];
		$old_id_map  = [ 1 => 10 ];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertCount( 1, $result );
		$this->assertSame( 1, $result[0]['live_id'] );
		$this->assertSame( 10, $result[0]['local_id'] );
		$this->assertArrayHasKey( 'changes', $result[0] );
		$this->assertArrayHasKey( 'comment_count', $result[0]['changes'] );
		$this->assertSame( 5, $result[0]['changes']['comment_count']['live'] );
		$this->assertSame( 3, $result[0]['changes']['comment_count']['local'] );
	}

	public function test_filter_modified_live_ids_should_detect_author_change_with_mapping(): void {
		$live_posts      = [
			[
				'ID'            => '1',
				'post_modified' => '2025-01-01',
				'post_status'   => 'publish',
				'post_author'   => 99, // Live author ID.
				'comment_count' => 0,
			],
		];
		$local_posts     = [
			[
				'ID'            => '10',
				'post_modified' => '2025-01-01',
				'post_status'   => 'publish',
				'post_author'   => 5, // Local author ID.
				'comment_count' => 0,
			],
		];
		$old_id_map      = [ 1 => 10 ];
		$user_old_id_map = [ 50 => 5 ]; // Local user 5 maps to live user 50, but live post has author 99.

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map, '', $user_old_id_map );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'changes', $result[0] );
		$this->assertArrayHasKey( 'post_author', $result[0]['changes'] );
		$this->assertSame( 99, $result[0]['changes']['post_author']['live'] );
		$this->assertSame( 5, $result[0]['changes']['post_author']['local'] );
		$this->assertSame( 50, $result[0]['changes']['post_author']['local_meta_old_id'] );
	}

	public function test_filter_modified_live_ids_should_not_detect_author_change_when_local_not_in_map(): void {
		$live_posts      = [
			[
				'ID'            => '1',
				'post_modified' => '2025-01-01',
				'post_status'   => 'publish',
				'post_author'   => 99,
				'comment_count' => 0,
			],
		];
		$local_posts     = [
			[
				'ID'            => '10',
				'post_modified' => '2025-01-01',
				'post_status'   => 'publish',
				'post_author'   => 5, // Local author not in the map.
				'comment_count' => 0,
			],
		];
		$old_id_map      = [ 1 => 10 ];
		$user_old_id_map = [ 100 => 20 ]; // Different user mapping, local user 5 not in map.

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map, '', $user_old_id_map );

		$this->assertCount( 0, $result, 'Should not detect modification when local author has no mapping.' );
	}

	public function test_filter_modified_live_ids_changes_should_contain_all_detected_changes(): void {
		$live_posts  = [
			[
				'ID'            => '1',
				'post_modified' => '2025-01-02',
				'post_status'   => 'publish',
				'post_author'   => 1,
				'comment_count' => 10,
			],
		];
		$local_posts = [
			[
				'ID'            => '10',
				'post_modified' => '2025-01-01',
				'post_status'   => 'draft',
				'post_author'   => 1,
				'comment_count' => 5,
			],
		];
		$old_id_map  = [ 1 => 10 ];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'changes', $result[0] );
		$changes = $result[0]['changes'];
		$this->assertArrayHasKey( 'post_modified', $changes, 'Should detect post_modified change.' );
		$this->assertArrayHasKey( 'post_status', $changes, 'Should detect post_status change.' );
		$this->assertArrayHasKey( 'comment_count', $changes, 'Should detect comment_count change.' );
	}

	/**
	 * Test that the filter_modified_live_ids() method correctly compares post's taxonomy terms using a direct DB query.
	 *
	 * This test verifies that local terms are correctly fetched for custom taxonomies (e.g., Co-Authors Plus "author")
	 * which may not not registered at WP CLI runtime.
	 */
	public function test_filter_modified_live_ids_should_not_flag_modified_when_taxonomy_terms_match(): void {
		global $wpdb;

		// Create a local post with a term relationship.
		$local_post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$local_term    = wp_insert_term( 'Test Term For Taxonomy Match', 'category' );
		$local_term_id = $local_term['term_id'];
		wp_set_object_terms( $local_post_id, [ $local_term_id ], 'category' );

		// Simulate "live" post and term with different IDs but same relationship.
		$live_post_id = 9001;
		$live_term_id = 9501;

		// Create the "live" tables (cdiff_ prefix).
		$live_prefix = 'cdiff_';

		// Create live tables if they don't exist (mirror structure from local).
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}posts LIKE {$wpdb->posts}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}postmeta LIKE {$wpdb->postmeta}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}terms LIKE {$wpdb->terms}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_taxonomy LIKE {$wpdb->term_taxonomy}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_relationships LIKE {$wpdb->term_relationships}" ); // phpcs:ignore

		// Insert live post.
		$wpdb->insert( // phpcs:ignore
			$live_prefix . 'posts',
			[
				'ID'            => $live_post_id,
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_author'   => 1,
				'post_modified' => '2025-01-01 12:00:00',
				'post_title'    => 'Live Post',
				'post_name'     => 'live-post',
				'post_date'     => '2025-01-01 12:00:00',
			]
		);

		// Insert live term and term_taxonomy.
		$wpdb->insert( $live_prefix . 'terms', [ 'term_id' => $live_term_id, 'name' => 'Test Term For Taxonomy Match', 'slug' => 'test-term-for-taxonomy-match' ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $live_term_id, 'term_id' => $live_term_id, 'taxonomy' => 'category', 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_relationships', [ 'object_id' => $live_post_id, 'term_taxonomy_id' => $live_term_id ] ); // phpcs:ignore

		// Build the input arrays.
		$live_posts  = [
			[
				'ID'            => (string) $live_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];
		$local_posts = [
			[
				'ID'            => (string) $local_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];

		// old_id_map: live_post_id => local_post_id.
		$old_id_map = [ $live_post_id => $local_post_id ];

		// term_old_id_map: live_term_id => local_term_id (method flips it internally).
		$term_old_id_map = [ $live_term_id => $local_term_id ];

		$result = $this->logic->filter_modified_live_ids(
			$live_posts,
			$local_posts,
			$old_id_map,
			$live_prefix,
			[], // user_old_id_map
			[], // attachment_old_id_map
			$term_old_id_map
		);

		// Clean up live tables.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore

		$this->assertEmpty( $result, 'Post should NOT be flagged as modified when taxonomy terms match.' );
	}

	/**
	 * Tests that filter_modified_live_ids detects taxonomy changes when terms differ.
	 *
	 * Scenario: Local post has 1 term, live post has 2 terms (shared + extra).
	 * The extra term on live should trigger a "modified" detection.
	 */
	public function test_filter_modified_live_ids_should_flag_modified_when_taxonomy_terms_differ(): void {
		global $wpdb;

		// Create a local post with ONE term.
		$local_post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$local_term    = wp_insert_term( 'Shared Term For Diff', 'category' );
		$local_term_id = $local_term['term_id'];
		wp_set_object_terms( $local_post_id, [ $local_term_id ], 'category' );

		// Simulate "live" post with TWO terms (shared + extra).
		$live_post_id       = 9002;
		$live_term_id       = 9502; // Maps to local_term_id (shared).
		$live_extra_term_id = 9503; // Extra term on live, not on local.

		$live_prefix = 'cdiff_';

		// Create live tables.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}posts LIKE {$wpdb->posts}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}postmeta LIKE {$wpdb->postmeta}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}terms LIKE {$wpdb->terms}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_taxonomy LIKE {$wpdb->term_taxonomy}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_relationships LIKE {$wpdb->term_relationships}" ); // phpcs:ignore

		// Insert live post.
		$wpdb->insert( // phpcs:ignore
			$live_prefix . 'posts',
			[
				'ID'            => $live_post_id,
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_author'   => 1,
				'post_modified' => '2025-01-01 12:00:00',
				'post_title'    => 'Live Post',
				'post_name'     => 'live-post-2',
				'post_date'     => '2025-01-01 12:00:00',
			]
		);

		// Insert live terms - shared term + extra term.
		$wpdb->insert( $live_prefix . 'terms', [ 'term_id' => $live_term_id, 'name' => 'Shared Term For Diff', 'slug' => 'shared-term-for-diff' ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $live_term_id, 'term_id' => $live_term_id, 'taxonomy' => 'category', 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_relationships', [ 'object_id' => $live_post_id, 'term_taxonomy_id' => $live_term_id ] ); // phpcs:ignore

		$wpdb->insert( $live_prefix . 'terms', [ 'term_id' => $live_extra_term_id, 'name' => 'Extra Live Term', 'slug' => 'extra-live-term' ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $live_extra_term_id, 'term_id' => $live_extra_term_id, 'taxonomy' => 'category', 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_relationships', [ 'object_id' => $live_post_id, 'term_taxonomy_id' => $live_extra_term_id ] ); // phpcs:ignore

		$live_posts  = [
			[
				'ID'            => (string) $live_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];
		$local_posts = [
			[
				'ID'            => (string) $local_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];

		$old_id_map = [ $live_post_id => $local_post_id ];

		// term_old_id_map: live_term_id => local_term_id (method flips it internally).
		// The extra live term (9503) has no local counterpart.
		// Expected: live_term_ids = [9502, 9503], local_term_ids_as_live = [9502]
		// Difference: 9503 is on live but not on local -> should flag as modified.
		$term_old_id_map = [ $live_term_id => $local_term_id ];

		$result = $this->logic->filter_modified_live_ids(
			$live_posts,
			$local_posts,
			$old_id_map,
			$live_prefix,
			[], // user_old_id_map
			[], // attachment_old_id_map
			$term_old_id_map
		);

		// Clean up.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore

		$this->assertCount( 1, $result, 'Post should be flagged as modified when taxonomy terms differ.' );
		$this->assertArrayHasKey( 'changes', $result[0] );
		$this->assertArrayHasKey( 'taxonomies', $result[0]['changes'], 'Changes should include taxonomies.' );
	}

	/**
	 * Tests that terms in taxonomies NOT in the $taxonomies parameter are skipped.
	 * This prevents false positives for non-attributed taxonomies like ef_editorial_meta.
	 */
	public function test_filter_modified_live_ids_should_not_flag_modified_when_term_in_non_specified_taxonomy(): void {
		global $wpdb;

		// Register a custom taxonomy not in DEFAULT_TAXONOMIES.
		register_taxonomy( 'ef_editorial_meta', 'post' );

		// Create a local post with a term in ef_editorial_meta.
		$local_post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$local_term    = wp_insert_term( 'In Progress', 'ef_editorial_meta' );
		$local_term_id = $local_term['term_id'];
		wp_set_object_terms( $local_post_id, [ $local_term_id ], 'ef_editorial_meta' );

		// Simulate "live" post with a DIFFERENT ef_editorial_meta term.
		$live_post_id = 9010;
		$live_term_id = 9510; // Different term, no mapping exists.
		$live_prefix  = 'cdiff_';

		// Create live tables.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}posts LIKE {$wpdb->posts}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}postmeta LIKE {$wpdb->postmeta}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}terms LIKE {$wpdb->terms}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_taxonomy LIKE {$wpdb->term_taxonomy}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_relationships LIKE {$wpdb->term_relationships}" ); // phpcs:ignore

		// Insert live post.
		$wpdb->insert( // phpcs:ignore
			$live_prefix . 'posts',
			[
				'ID'            => $live_post_id,
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_author'   => 1,
				'post_modified' => '2025-01-01 12:00:00',
				'post_title'    => 'Live Post',
				'post_name'     => 'live-post-ef',
				'post_date'     => '2025-01-01 12:00:00',
			]
		);

		// Insert live term in ef_editorial_meta (not in $taxonomies param).
		$wpdb->insert( $live_prefix . 'terms', [ 'term_id' => $live_term_id, 'name' => 'Draft', 'slug' => 'draft' ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $live_term_id, 'term_id' => $live_term_id, 'taxonomy' => 'ef_editorial_meta', 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_relationships', [ 'object_id' => $live_post_id, 'term_taxonomy_id' => $live_term_id ] ); // phpcs:ignore

		$live_posts  = [
			[
				'ID'            => (string) $live_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];
		$local_posts = [
			[
				'ID'            => (string) $local_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];

		$old_id_map      = [ $live_post_id => $local_post_id ];
		$term_old_id_map = []; // No term mapping (simulates non-attributed taxonomy).

		// Pass $taxonomies = ['category'] - ef_editorial_meta should be skipped.
		$result = $this->logic->filter_modified_live_ids(
			$live_posts,
			$local_posts,
			$old_id_map,
			$live_prefix,
			[], // user_old_id_map
			[], // attachment_old_id_map
			$term_old_id_map,
			[ 'category' ] // Only compare 'category', skip ef_editorial_meta.
		);

		// Clean up.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore

		$this->assertCount( 0, $result, 'Post should NOT be flagged as modified when term is in non-specified taxonomy.' );
	}

	/**
	 * Tests backward compatibility when $taxonomies param is empty.
	 * When empty, taxonomy comparison should be skipped entirely.
	 */
	public function test_filter_modified_live_ids_should_skip_taxonomy_comparison_when_taxonomies_param_empty(): void {
		global $wpdb;

		// Create a local post with a category term.
		$local_post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$local_term    = wp_insert_term( 'Test Category Empty', 'category' );
		$local_term_id = $local_term['term_id'];
		wp_set_object_terms( $local_post_id, [ $local_term_id ], 'category' );

		// Simulate "live" post with a DIFFERENT category term (would normally trigger modified).
		$live_post_id = 9020;
		$live_term_id = 9520;
		$live_prefix  = 'cdiff_';

		// Create live tables.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}posts LIKE {$wpdb->posts}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}postmeta LIKE {$wpdb->postmeta}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}terms LIKE {$wpdb->terms}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_taxonomy LIKE {$wpdb->term_taxonomy}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_relationships LIKE {$wpdb->term_relationships}" ); // phpcs:ignore

		// Insert live post.
		$wpdb->insert( // phpcs:ignore
			$live_prefix . 'posts',
			[
				'ID'            => $live_post_id,
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_author'   => 1,
				'post_modified' => '2025-01-01 12:00:00',
				'post_title'    => 'Live Post Empty Tax',
				'post_name'     => 'live-post-empty-tax',
				'post_date'     => '2025-01-01 12:00:00',
			]
		);

		// Insert live term.
		$wpdb->insert( $live_prefix . 'terms', [ 'term_id' => $live_term_id, 'name' => 'Different Cat', 'slug' => 'different-cat' ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $live_term_id, 'term_id' => $live_term_id, 'taxonomy' => 'category', 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_relationships', [ 'object_id' => $live_post_id, 'term_taxonomy_id' => $live_term_id ] ); // phpcs:ignore

		$live_posts  = [
			[
				'ID'            => (string) $live_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];
		$local_posts = [
			[
				'ID'            => (string) $local_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];

		$old_id_map      = [ $live_post_id => $local_post_id ];
		$term_old_id_map = [ $live_term_id => $local_term_id ];

		// Pass $taxonomies = [] - taxonomy comparison should be skipped.
		$result = $this->logic->filter_modified_live_ids(
			$live_posts,
			$local_posts,
			$old_id_map,
			$live_prefix,
			[], // user_old_id_map
			[], // attachment_old_id_map
			$term_old_id_map,
			[] // Empty taxonomies = skip comparison.
		);

		// Clean up.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore

		$this->assertCount( 0, $result, 'Post should NOT be flagged as modified when $taxonomies param is empty (skip comparison).' );
	}

	/**
	 * Tests that only terms in specified taxonomies are compared.
	 * Post has terms in both 'category' and 'post_tag', but only 'category' is in $taxonomies.
	 * Differences in 'post_tag' should be ignored.
	 */
	public function test_filter_modified_live_ids_should_only_compare_terms_in_specified_taxonomies(): void {
		global $wpdb;

		// Create a local post with category and post_tag terms.
		$local_post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$local_cat     = wp_insert_term( 'Same Category', 'category' );
		$local_cat_id  = $local_cat['term_id'];
		$local_tag     = wp_insert_term( 'Local Tag', 'post_tag' );
		$local_tag_id  = $local_tag['term_id'];
		wp_set_object_terms( $local_post_id, [ $local_cat_id ], 'category' );
		wp_set_object_terms( $local_post_id, [ $local_tag_id ], 'post_tag' );

		// Simulate "live" post with same category but DIFFERENT post_tag.
		$live_post_id = 9030;
		$live_cat_id  = 9530; // Maps to local_cat_id.
		$live_tag_id  = 9531; // Different tag, no mapping.
		$live_prefix  = 'cdiff_';

		// Create live tables.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}posts LIKE {$wpdb->posts}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}postmeta LIKE {$wpdb->postmeta}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}terms LIKE {$wpdb->terms}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_taxonomy LIKE {$wpdb->term_taxonomy}" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$live_prefix}term_relationships LIKE {$wpdb->term_relationships}" ); // phpcs:ignore

		// Insert live post.
		$wpdb->insert( // phpcs:ignore
			$live_prefix . 'posts',
			[
				'ID'            => $live_post_id,
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_author'   => 1,
				'post_modified' => '2025-01-01 12:00:00',
				'post_title'    => 'Live Post Selective Tax',
				'post_name'     => 'live-post-selective-tax',
				'post_date'     => '2025-01-01 12:00:00',
			]
		);

		// Insert live category (same as local).
		$wpdb->insert( $live_prefix . 'terms', [ 'term_id' => $live_cat_id, 'name' => 'Same Category', 'slug' => 'same-category' ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $live_cat_id, 'term_id' => $live_cat_id, 'taxonomy' => 'category', 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_relationships', [ 'object_id' => $live_post_id, 'term_taxonomy_id' => $live_cat_id ] ); // phpcs:ignore

		// Insert live tag (different from local).
		$wpdb->insert( $live_prefix . 'terms', [ 'term_id' => $live_tag_id, 'name' => 'Live Tag', 'slug' => 'live-tag' ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $live_tag_id, 'term_id' => $live_tag_id, 'taxonomy' => 'post_tag', 'count' => 1 ] ); // phpcs:ignore
		$wpdb->insert( $live_prefix . 'term_relationships', [ 'object_id' => $live_post_id, 'term_taxonomy_id' => $live_tag_id ] ); // phpcs:ignore

		$live_posts  = [
			[
				'ID'            => (string) $live_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];
		$local_posts = [
			[
				'ID'            => (string) $local_post_id,
				'post_modified' => '2025-01-01 12:00:00',
				'post_status'   => 'publish',
				'post_author'   => '1',
				'comment_count' => '0',
			],
		];

		$old_id_map = [ $live_post_id => $local_post_id ];
		// Only category is mapped, post_tag is not (simulating selective attribution).
		$term_old_id_map = [ $live_cat_id => $local_cat_id ];

		// Pass $taxonomies = ['category'] - post_tag differences should be ignored.
		$result = $this->logic->filter_modified_live_ids(
			$live_posts,
			$local_posts,
			$old_id_map,
			$live_prefix,
			[], // user_old_id_map
			[], // attachment_old_id_map
			$term_old_id_map,
			[ 'category' ] // Only compare 'category'.
		);

		// Clean up.
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}posts" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}postmeta" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}terms" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_taxonomy" ); // phpcs:ignore
		$wpdb->query( "DROP TABLE IF EXISTS {$live_prefix}term_relationships" ); // phpcs:ignore

		$this->assertCount( 0, $result, 'Post should NOT be flagged as modified when only non-specified taxonomy (post_tag) differs.' );
	}

	/**
	 * =========================================================================
	 * match_local_to_live_posts Tests
	 * =========================================================================
	 */
	public function test_match_local_to_live_posts_should_return_matched_pairs(): void {
		$local_posts = [
			[
				'ID'          => '10',
				'post_name'   => 'a',
				'post_title'  => 'A',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];
		$live_posts  = [
			[
				'ID'          => '1',
				'post_name'   => 'a',
				'post_title'  => 'A',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];

		$result = $this->logic->match_local_to_live_posts( $local_posts, $live_posts );

		$this->assertCount( 1, $result );
		$this->assertSame( 10, $result[0]['local_id'] );
		$this->assertSame( 1, $result[0]['live_id'] );
	}

	public function test_match_local_to_live_posts_should_return_empty_when_no_matches(): void {
		$local_posts = [
			[
				'ID'          => '10',
				'post_name'   => 'x',
				'post_title'  => 'X',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];
		$live_posts  = [
			[
				'ID'          => '1',
				'post_name'   => 'y',
				'post_title'  => 'Y',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];

		$result = $this->logic->match_local_to_live_posts( $local_posts, $live_posts );

		$this->assertEmpty( $result );
	}

	public function test_match_local_to_live_posts_should_use_composite_key_for_matching(): void {
		// Same name but different title should NOT match.
		$local_posts = [
			[
				'ID'          => '10',
				'post_name'   => 'same',
				'post_title'  => 'Different Title',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];
		$live_posts  = [
			[
				'ID'          => '1',
				'post_name'   => 'same',
				'post_title'  => 'Original Title',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];

		$result = $this->logic->match_local_to_live_posts( $local_posts, $live_posts );

		$this->assertEmpty( $result );
	}

	public function test_match_local_to_live_posts_should_cast_ids_to_int(): void {
		$local_posts = [
			[
				'ID'          => '10',
				'post_name'   => 'a',
				'post_title'  => 'A',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];
		$live_posts  = [
			[
				'ID'          => '1',
				'post_name'   => 'a',
				'post_title'  => 'A',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];

		$result = $this->logic->match_local_to_live_posts( $local_posts, $live_posts );

		$this->assertSame( 10, $result[0]['local_id'] );
		$this->assertSame( 1, $result[0]['live_id'] );
	}

	public function test_match_local_to_live_posts_with_prebuilt_lookup_should_reuse_lookup(): void {
		$live_posts = [
			[
				'ID'          => '1',
				'post_name'   => 'post-a',
				'post_title'  => 'Post A',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
			[
				'ID'          => '2',
				'post_name'   => 'post-b',
				'post_title'  => 'Post B',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-02',
			],
		];

		// Build lookup from live posts (first call with empty local posts).
		$lookup = null;
		$result = $this->logic->match_local_to_live_posts( [], $live_posts, $lookup );

		// Verify lookup was built.
		$this->assertIsArray( $lookup );
		$this->assertNotEmpty( $lookup );
		$this->assertEmpty( $result, 'No matches expected with empty local posts.' );

		// Now use the pre-built lookup with local posts (empty live posts array).
		$local_posts_batch1 = [
			[
				'ID'          => '10',
				'post_name'   => 'post-a',
				'post_title'  => 'Post A',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];

		$result1 = $this->logic->match_local_to_live_posts( $local_posts_batch1, [], $lookup );

		$this->assertCount( 1, $result1 );
		$this->assertSame( 10, $result1[0]['local_id'] );
		$this->assertSame( 1, $result1[0]['live_id'] );

		// Second batch reusing the same lookup.
		$local_posts_batch2 = [
			[
				'ID'          => '20',
				'post_name'   => 'post-b',
				'post_title'  => 'Post B',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-02',
			],
		];

		$result2 = $this->logic->match_local_to_live_posts( $local_posts_batch2, [], $lookup );

		$this->assertCount( 1, $result2 );
		$this->assertSame( 20, $result2[0]['local_id'] );
		$this->assertSame( 2, $result2[0]['live_id'] );
	}

	public function test_match_local_to_live_posts_with_prebuilt_lookup_should_not_rebuild(): void {
		$live_posts = [
			[
				'ID'          => '1',
				'post_name'   => 'original',
				'post_title'  => 'Original',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];

		// Build lookup.
		$lookup = null;
		$this->logic->match_local_to_live_posts( [], $live_posts, $lookup );
		$lookup_count_after_build = count( $lookup );

		// Call again with different live posts — lookup should NOT be rebuilt.
		$different_live_posts = [
			[
				'ID'          => '999',
				'post_name'   => 'different',
				'post_title'  => 'Different',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];

		$this->logic->match_local_to_live_posts( [], $different_live_posts, $lookup );

		// Lookup should still have original count (not rebuilt with different_live_posts).
		$this->assertCount( $lookup_count_after_build, $lookup );
	}

	public function test_match_local_to_live_posts_with_empty_prebuilt_lookup_finds_no_matches(): void {
		// Pre-built empty lookup (simulating no live posts).
		$lookup = [];

		$local_posts = [
			[
				'ID'          => '10',
				'post_name'   => 'some-post',
				'post_title'  => 'Some Post',
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_date'   => '2025-01-01',
			],
		];

		$result = $this->logic->match_local_to_live_posts( $local_posts, [], $lookup );

		$this->assertEmpty( $result );
	}

	/**
	 * =========================================================================
	 * match_local_to_live_users Tests
	 * =========================================================================
	 */
	public function test_match_local_to_live_users_should_return_matched_pairs_by_login(): void {
		$local_users = [
			[
				'ID'         => '10',
				'user_login' => 'john',
			],
		];
		$live_users  = [
			[
				'ID'         => '1',
				'user_login' => 'john',
			],
		];

		$result = $this->logic->match_local_to_live_users( $local_users, $live_users );

		$this->assertCount( 1, $result );
		$this->assertSame( 10, $result[0]['local_id'] );
		$this->assertSame( 1, $result[0]['live_id'] );
	}

	public function test_match_local_to_live_users_should_return_empty_when_no_matches(): void {
		$local_users = [
			[
				'ID'         => '10',
				'user_login' => 'john',
			],
		];
		$live_users  = [
			[
				'ID'         => '1',
				'user_login' => 'jane',
			],
		];

		$result = $this->logic->match_local_to_live_users( $local_users, $live_users );

		$this->assertEmpty( $result );
	}

	public function test_match_local_to_live_users_should_cast_ids_to_int(): void {
		$local_users = [
			[
				'ID'         => '10',
				'user_login' => 'admin',
			],
		];
		$live_users  = [
			[
				'ID'         => '1',
				'user_login' => 'admin',
			],
		];

		$result = $this->logic->match_local_to_live_users( $local_users, $live_users );

		$this->assertSame( 10, $result[0]['local_id'] );
		$this->assertSame( 1, $result[0]['live_id'] );
	}

	/**
	 * =========================================================================
	 * get_users_rows_for_attribution Tests
	 * =========================================================================
	 */
	public function test_get_users_rows_for_attribution_should_return_id_and_login(): void {
		global $wpdb;
		$user_id = self::factory()->user->create( [ 'user_login' => 'attribution_user_' . uniqid() ] );

		$result = $this->logic->get_users_rows_for_attribution( $wpdb->prefix );

		$found = array_filter( $result, fn( $u ) => (int) $u['ID'] === $user_id );
		$this->assertNotEmpty( $found );
		$user = reset( $found );
		$this->assertArrayHasKey( 'ID', $user );
		$this->assertArrayHasKey( 'user_login', $user );
	}

	public function test_get_users_rows_for_attribution_should_return_empty_array_when_no_users(): void {
		global $wpdb;

		// Suppress printing out error messages to stdout.
		$wpdb->suppress_errors( true );

		// Use a non-existent table prefix.
		$result = $this->logic->get_users_rows_for_attribution( 'nonexistent_prefix_' );

		$wpdb->suppress_errors( false );

		// Will likely fail query, return empty.
		$this->assertIsArray( $result );
	}

	/**
	 * =========================================================================
	 * get_post_data Tests
	 * =========================================================================
	 */
	public function test_get_post_data_should_return_all_data_keys(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$this->assertArrayHasKey( 'post', $result );
		$this->assertArrayHasKey( 'postmeta', $result );
		$this->assertArrayHasKey( 'comments', $result );
		$this->assertArrayHasKey( 'commentmeta', $result );
		$this->assertArrayHasKey( 'users', $result );
		$this->assertArrayHasKey( 'usermeta', $result );
		$this->assertArrayHasKey( 'term_relationships', $result );
		$this->assertArrayHasKey( 'term_taxonomy', $result );
		$this->assertArrayHasKey( 'terms', $result );
		$this->assertArrayHasKey( 'termmeta', $result );
	}

	public function test_get_post_data_should_include_post_row(): void {
		global $wpdb;
		$post_id = self::factory()->post->create( [ 'post_title' => 'Test Post Data' ] );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$this->assertSame( $post_id, (int) $result['post']['ID'] );
		$this->assertSame( 'Test Post Data', $result['post']['post_title'] );
	}

	public function test_get_post_data_should_include_postmeta_rows(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_test_meta_key', 'test_value' );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$meta_keys = array_column( $result['postmeta'], 'meta_key' );
		$this->assertContains( '_test_meta_key', $meta_keys );
	}

	public function test_get_post_data_should_include_author_user(): void {
		global $wpdb;
		$user_id = self::factory()->user->create();
		$post_id = self::factory()->post->create( [ 'post_author' => $user_id ] );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$this->assertNotEmpty( $result['users'] );
		$user_ids = array_column( $result['users'], 'ID' );
		$this->assertContains( (string) $user_id, $user_ids );
	}

	public function test_get_post_data_should_include_author_usermeta(): void {
		global $wpdb;
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_test_user_meta', 'user_value' );
		$post_id = self::factory()->post->create( [ 'post_author' => $user_id ] );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$meta_keys = array_column( $result['usermeta'], 'meta_key' );
		$this->assertContains( '_test_user_meta', $meta_keys );
	}

	public function test_get_post_data_should_include_comments_when_comment_count_positive(): void {
		global $wpdb;
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
			] 
		);
		// Update comment count.
		wp_update_comment_count( $post_id );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$this->assertNotEmpty( $result['comments'] );
		$comment_ids = array_column( $result['comments'], 'comment_ID' );
		$this->assertContains( (string) $comment_id, $comment_ids );
	}

	public function test_get_post_data_should_skip_comments_when_comment_count_zero(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		// No comments created.

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$this->assertEmpty( $result['comments'] );
	}

	public function test_get_post_data_should_include_commentmeta(): void {
		global $wpdb;
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
			] 
		);
		wp_update_comment_count( $post_id );
		update_comment_meta( $comment_id, '_test_comment_meta', 'comment_value' );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$meta_keys = array_column( $result['commentmeta'], 'meta_key' );
		$this->assertContains( '_test_comment_meta', $meta_keys );
	}

	public function test_get_post_data_should_include_comment_user_when_user_id_positive(): void {
		global $wpdb;
		$author_id       = self::factory()->user->create();
		$comment_user_id = self::factory()->user->create();
		$post_id         = self::factory()->post->create( [ 'post_author' => $author_id ] );
		$comment_id      = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
				'user_id'          => $comment_user_id,
			] 
		);
		wp_update_comment_count( $post_id );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$user_ids = array_column( $result['users'], 'ID' );
		$this->assertContains( (string) $comment_user_id, $user_ids );
	}

	public function test_get_post_data_should_not_duplicate_comment_user_already_fetched(): void {
		global $wpdb;
		$user_id = self::factory()->user->create();
		// Author is also commenter.
		$post_id    = self::factory()->post->create( [ 'post_author' => $user_id ] );
		$comment_id = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
				'user_id'          => $user_id,
			] 
		);
		wp_update_comment_count( $post_id );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		// User should appear only once.
		$user_ids = array_column( $result['users'], 'ID' );
		$this->assertCount( 1, array_filter( $user_ids, fn( $id ) => (int) $id === $user_id ) );
	}

	public function test_get_post_data_should_include_term_relationships(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term_id ], 'category' );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$this->assertNotEmpty( $result['term_relationships'] );
	}

	public function test_get_post_data_should_include_term_taxonomy(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term_id ], 'category' );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$this->assertNotEmpty( $result['term_taxonomy'] );
		$taxonomies = array_column( $result['term_taxonomy'], 'taxonomy' );
		$this->assertContains( 'category', $taxonomies );
	}

	public function test_get_post_data_should_include_terms(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test Term',
			] 
		);
		wp_set_object_terms( $post_id, [ $term_id ], 'category' );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$this->assertNotEmpty( $result['terms'] );
		$term_names = array_column( $result['terms'], 'name' );
		$this->assertContains( 'Test Term', $term_names );
	}

	public function test_get_post_data_should_include_termmeta(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term_id ], 'category' );
		update_term_meta( $term_id, '_test_term_meta', 'term_value' );

		$result = $this->logic->get_post_data( $post_id, $wpdb->prefix );

		$meta_keys = array_column( $result['termmeta'], 'meta_key' );
		$this->assertContains( '_test_term_meta', $meta_keys );
	}

	/**
	 * =========================================================================
	 * Select Wrapper Methods Tests
	 * =========================================================================
	 */
	public function test_select_post_row_should_return_row_and_null_when_not_found(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();

		$result = $this->logic->select_post_row( $wpdb->prefix, $post_id );
		$this->assertIsArray( $result );
		$this->assertSame( $post_id, (int) $result['ID'] );

		$result_null = $this->logic->select_post_row( $wpdb->prefix, 999999 );
		$this->assertNull( $result_null );
	}

	public function test_select_postmeta_rows_should_return_rows_and_empty_when_not_found(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_key1', 'val1' );

		$result = $this->logic->select_postmeta_rows( $wpdb->prefix, $post_id );
		$this->assertNotEmpty( $result );

		$result_empty = $this->logic->select_postmeta_rows( $wpdb->prefix, 999999 );
		$this->assertEmpty( $result_empty );
	}

	public function test_select_user_row_should_return_row_and_null_when_not_found(): void {
		global $wpdb;
		$user_id = self::factory()->user->create();

		$result = $this->logic->select_user_row( $wpdb->prefix, $user_id );
		$this->assertIsArray( $result );
		$this->assertSame( $user_id, (int) $result['ID'] );

		$result_null = $this->logic->select_user_row( $wpdb->prefix, 999999 );
		$this->assertNull( $result_null );
	}

	public function test_select_usermeta_rows_should_return_rows_and_empty_when_not_found(): void {
		global $wpdb;
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_user_key', 'user_val' );

		$result = $this->logic->select_usermeta_rows( $wpdb->prefix, $user_id );
		$this->assertNotEmpty( $result );

		$result_empty = $this->logic->select_usermeta_rows( $wpdb->prefix, 999999 );
		$this->assertEmpty( $result_empty );
	}

	public function test_select_comment_rows_should_return_rows_and_empty_when_not_found(): void {
		global $wpdb;
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );

		$result = $this->logic->select_comment_rows( $wpdb->prefix, $post_id );
		$this->assertNotEmpty( $result );

		$result_empty = $this->logic->select_comment_rows( $wpdb->prefix, 999999 );
		$this->assertEmpty( $result_empty );
	}

	public function test_select_commentmeta_rows_should_return_rows_and_empty_when_not_found(): void {
		global $wpdb;
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );
		update_comment_meta( $comment_id, '_cm_key', 'cm_val' );

		$result = $this->logic->select_commentmeta_rows( $wpdb->prefix, $comment_id );
		$this->assertNotEmpty( $result );

		$result_empty = $this->logic->select_commentmeta_rows( $wpdb->prefix, 999999 );
		$this->assertEmpty( $result_empty );
	}

	public function test_select_term_relationships_rows_should_return_rows_and_empty_when_not_found(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_object_terms( $post_id, [ $term_id ], 'category' );

		$result = $this->logic->select_term_relationships_rows( $wpdb->prefix, $post_id );
		$this->assertNotEmpty( $result );

		$result_empty = $this->logic->select_term_relationships_rows( $wpdb->prefix, 999999 );
		$this->assertEmpty( $result_empty );
	}

	public function test_select_term_taxonomy_row_should_return_row_and_null_when_not_found(): void {
		global $wpdb;
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		$tt_id   = $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $term_id ) ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching.

		$result = $this->logic->select_term_taxonomy_row( $wpdb->prefix, (int) $tt_id );
		$this->assertIsArray( $result );

		$result_null = $this->logic->select_term_taxonomy_row( $wpdb->prefix, 999999 );
		$this->assertNull( $result_null );
	}

	public function test_select_term_row_should_return_row_and_null_when_not_found(): void {
		global $wpdb;
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'category' ] );

		$result = $this->logic->select_term_row( $wpdb->prefix, $term_id );
		$this->assertIsArray( $result );
		$this->assertSame( $term_id, (int) $result['term_id'] );

		$result_null = $this->logic->select_term_row( $wpdb->prefix, 999999 );
		$this->assertNull( $result_null );
	}

	public function test_select_termmeta_rows_should_return_rows_and_empty_when_not_found(): void {
		global $wpdb;
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		update_term_meta( $term_id, '_tm_key', 'tm_val' );

		$result = $this->logic->select_termmeta_rows( $wpdb->prefix, $term_id );
		$this->assertNotEmpty( $result );

		$result_empty = $this->logic->select_termmeta_rows( $wpdb->prefix, 999999 );
		$this->assertEmpty( $result_empty );
	}

	/**
	 * =========================================================================
	 * Insert Methods Tests
	 * =========================================================================
	 */
	public function test_insert_post_should_insert_and_return_new_id(): void {
		$post_row = $this->build_test_post_row( [ 'ID' => 12345 ] );

		$new_id = $this->logic->insert_post( $post_row );

		$this->assertIsInt( $new_id );
		$this->assertGreaterThan( 0, $new_id );
		$this->assertNotEquals( 12345, $new_id ); // Original ID should be removed.

		// Verify post exists.
		$post = get_post( $new_id );
		$this->assertNotNull( $post );
	}

	public function test_insert_post_should_throw_on_insert_failure(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert', 'posts' );
		$logic     = new ContentDiffLogic( $mock_wpdb );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Error inserting post' );

		$post_row = $this->build_test_post_row();
		$logic->insert_post( $post_row );
	}

	public function test_insert_term_should_insert_and_return_new_id(): void {
		$term_row = [
			'term_id' => 99999,
			'name'    => 'Test Term ' . uniqid(),
			'slug'    => 'test-term-' . uniqid(),
		];

		$new_id = $this->logic->insert_term( $term_row );

		$this->assertIsInt( $new_id );
		$this->assertGreaterThan( 0, $new_id );
		$this->assertNotEquals( 99999, $new_id );
	}

	public function test_insert_term_should_throw_on_insert_failure(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert', 'terms' );
		$logic     = new ContentDiffLogic( $mock_wpdb );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Error inserting term' );

		$term_row = [
			'name' => 'Fail Term',
			'slug' => 'fail-term',
		];
		$logic->insert_term( $term_row );
	}

	/**
	 * =========================================================================
	 * get_existing_term_taxonomy Tests
	 * =========================================================================
	 */
	public function test_get_existing_term_taxonomy_should_return_term_taxonomy_id(): void {
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'category' ] );

		$result = $this->logic->get_existing_term_taxonomy( $term_id, 'category' );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_get_existing_term_taxonomy_should_return_null_when_not_found(): void {
		$result = $this->logic->get_existing_term_taxonomy( 999999, 'category' );

		$this->assertNull( $result );
	}

	/**
	 * =========================================================================
	 * Post Lookup Methods Tests
	 * =========================================================================
	 */
	public function test_get_current_post_id_by_comparing_with_live_db_should_return_post_id(): void {
		global $wpdb;
		// Create a local post.
		$post_id = self::factory()->post->create(
			[
				'post_name'   => 'compare-test-' . uniqid(),
				'post_title'  => 'Compare Test',
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_date'   => '2025-01-15 10:00:00',
			] 
		);

		// Use the same table prefix (local DB simulates live DB).
		$result = $this->logic->get_current_post_id_by_comparing_with_live_db( $post_id, $wpdb->prefix );

		$this->assertEquals( $post_id, (int) $result );
	}

	public function test_get_current_post_id_by_comparing_with_live_db_should_return_null_when_not_found(): void {
		global $wpdb;

		$result = $this->logic->get_current_post_id_by_comparing_with_live_db( 999999, $wpdb->prefix );

		$this->assertNull( $result );
	}

	/**
	 * =========================================================================
	 * update_post_parent Tests
	 * =========================================================================
	 */
	public function test_update_post_parent_should_update_post_parent_id(): void {
		$parent_id = self::factory()->post->create();
		$child_id  = self::factory()->post->create( [ 'post_parent' => 0 ] );

		$this->logic->update_post_parent( $child_id, $parent_id );

		clean_post_cache( $child_id );
		$child = get_post( $child_id );
		$this->assertEquals( $parent_id, $child->post_parent );
	}

	public function test_update_post_parent_should_log_error_on_failure(): void {
		// Mock wpdb to simulate failure.
		$mock_wpdb        = $this->createMock( \wpdb::class );
		$mock_wpdb->posts = 'wp_posts';
		$mock_wpdb->method( 'update' )->willReturn( false );
		$mock_wpdb->last_error = 'Test error';

		$logic = new ContentDiffLogic( $mock_wpdb );

		// Should not throw, just log.
		$logic->update_post_parent( 1, 2 );

		// If we get here without exception, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * =========================================================================
	 * update_featured_image Tests
	 * =========================================================================
	 */
	public function test_update_featured_image_should_update_thumbnail_id(): void {
		$attachment_old = self::factory()->attachment->create();
		$attachment_new = self::factory()->attachment->create();
		$post_id        = self::factory()->post->create();
		update_post_meta( $post_id, '_thumbnail_id', $attachment_old );

		$map = [ (string) $attachment_old => $attachment_new ];
		$this->logic->update_featured_image( $post_id, $map );

		$result = get_post_meta( $post_id, '_thumbnail_id', true );
		$this->assertEquals( $attachment_new, (int) $result );
	}

	public function test_update_featured_image_should_return_early_when_map_empty(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create();
		update_post_meta( $post_id, '_thumbnail_id', $attachment_id );

		$this->logic->update_featured_image( $post_id, [] );

		// Should remain unchanged.
		$result = get_post_meta( $post_id, '_thumbnail_id', true );
		$this->assertEquals( $attachment_id, (int) $result );
	}

	public function test_update_featured_image_should_return_when_no_current_thumbnail(): void {
		$post_id = self::factory()->post->create();
		// No thumbnail set.

		$map = [ '123' => 456 ];
		$this->logic->update_featured_image( $post_id, $map );

		// Should not throw, just return.
		$this->assertEmpty( get_post_meta( $post_id, '_thumbnail_id', true ) );
	}

	public function test_update_featured_image_should_return_when_new_thumbnail_not_in_map(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create();
		update_post_meta( $post_id, '_thumbnail_id', $attachment_id );

		// Map doesn't contain the current thumbnail ID.
		$map = [ '999' => 888 ];
		$this->logic->update_featured_image( $post_id, $map );

		// Should remain unchanged.
		$result = get_post_meta( $post_id, '_thumbnail_id', true );
		$this->assertEquals( $attachment_id, (int) $result );
	}

	public function test_update_featured_image_should_log_on_successful_update(): void {
		$attachment_old = self::factory()->attachment->create();
		$attachment_new = self::factory()->attachment->create();
		$post_id        = self::factory()->post->create();
		update_post_meta( $post_id, '_thumbnail_id', $attachment_old );

		$map = [ (string) $attachment_old => $attachment_new ];

		// Logger is disabled, but method should complete without error.
		$this->logic->update_featured_image( $post_id, $map );

		$result = get_post_meta( $post_id, '_thumbnail_id', true );
		$this->assertEquals( $attachment_new, (int) $result );
	}

	public function test_update_featured_image_should_skip_when_thumbnail_is_already_local_id(): void {
		$attachment_old = self::factory()->attachment->create();
		$attachment_new = self::factory()->attachment->create();
		$post_id        = self::factory()->post->create();
		// Set thumbnail to a value that's already a local ID (from previous run).
		update_post_meta( $post_id, '_thumbnail_id', $attachment_new );

		// Map that could cause collision: current thumbnail matches a live_id in the map.
		$map = [
			(string) $attachment_old => $attachment_new,
			(string) $attachment_new => 99999, // Collision risk: current thumb matches this live_id.
		];

		$this->logic->update_featured_image( $post_id, $map );

		// Should remain unchanged because attachment_new is in local_ids_set.
		$result = get_post_meta( $post_id, '_thumbnail_id', true );
		$this->assertEquals( $attachment_new, (int) $result );
	}

	/**
	 * =========================================================================
	 * update_blocks_ids Tests
	 * =========================================================================
	 */
	public function test_update_blocks_ids_should_return_early_when_map_empty(): void {
		$post_id = self::factory()->post->create( [ 'post_content' => '<!-- wp:image {"id":123} -->' ] );

		$this->logic->update_blocks_ids( $post_id, [] );

		// Content should be unchanged.
		$post = get_post( $post_id );
		$this->assertStringContainsString( '"id":123', $post->post_content );
	}

	public function test_update_blocks_ids_should_return_when_post_not_found(): void {
		// Should not throw for non-existent post.
		$map = [ '123' => 456 ];
		$this->logic->update_blocks_ids( 999999, $map );
		$this->assertTrue( true );
	}

	public function test_update_blocks_ids_should_call_block_updater_for_content_and_excerpt(): void {
		global $wpdb;
		$mock_block_updater = $this->createMock( BlockUpdater::class );
		$mock_block_updater->expects( $this->exactly( 2 ) )
			->method( 'update_all_blocks_ids' )
			->willReturnArgument( 0 ); // Return content unchanged.

		$logic   = new ContentDiffLogic( $wpdb, $mock_block_updater );
		$post_id = self::factory()->post->create(
			[
				'post_content' => 'content',
				'post_excerpt' => 'excerpt',
			] 
		);

		$map = [ '1' => 2 ];
		$logic->update_blocks_ids( $post_id, $map );

		// Assertion is via mock expectation.
	}

	public function test_update_blocks_ids_should_persist_when_content_changed(): void {
		global $wpdb;
		$mock_block_updater = $this->createMock( BlockUpdater::class );
		$mock_block_updater->method( 'update_all_blocks_ids' )
			->willReturnCallback(
				function( $content ) {
					return str_replace( 'old', 'new', $content );
				} 
			);

		$logic   = new ContentDiffLogic( $wpdb, $mock_block_updater );
		$post_id = self::factory()->post->create( [ 'post_content' => 'old content' ] );

		$map = [ '1' => 2 ];
		$logic->update_blocks_ids( $post_id, $map );

		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		$this->assertStringContainsString( 'new', $post->post_content );
	}

	public function test_update_blocks_ids_should_not_persist_when_no_changes(): void {
		global $wpdb;
		$mock_block_updater = $this->createMock( BlockUpdater::class );
		$mock_block_updater->method( 'update_all_blocks_ids' )
			->willReturnArgument( 0 ); // Return unchanged.

		$logic            = new ContentDiffLogic( $wpdb, $mock_block_updater );
		$original_content = 'unchanged content ' . uniqid();
		$post_id          = self::factory()->post->create( [ 'post_content' => $original_content ] );

		$map = [ '1' => 2 ];
		$logic->update_blocks_ids( $post_id, $map );

		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		$this->assertSame( $original_content, $post->post_content );
	}

	public function test_update_blocks_ids_should_filter_local_hostname_from_aliases(): void {
		global $wpdb;
		$local_host = wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST );

		// Mock should receive aliases WITHOUT the local hostname.
		$mock_block_updater = $this->createMock( BlockUpdater::class );
		$mock_block_updater->expects( $this->exactly( 2 ) )
			->method( 'update_all_blocks_ids' )
			->with(
				$this->anything(),
				$this->anything(),
				// Third argument should NOT contain local hostname.
				$this->callback(
					function( $aliases ) use ( $local_host ) {
						return ! in_array( $local_host, $aliases, true );
					} 
				)
			)
			->willReturnArgument( 0 );

		$logic   = new ContentDiffLogic( $wpdb, $mock_block_updater );
		$post_id = self::factory()->post->create( [ 'post_content' => 'test' ] );

		// Pass local hostname in aliases - should be filtered out before passing to block_updater.
		$aliases = [ $local_host, 'other.example.com' ];
		$map     = [ '1' => 2 ];
		$logic->update_blocks_ids( $post_id, $map, $aliases );
	}

	/**
	 * =========================================================================
	 * attachment_url_to_postid_resolver Tests
	 * =========================================================================
	 */
	public function test_attachment_url_to_postid_resolver_should_return_post_id(): void {
		$filename      = DIR_TESTDATA . '/images/canola.jpg';
		$attachment_id = self::factory()->attachment->create_upload_object( $filename );
		$url           = wp_get_attachment_url( $attachment_id );

		$result = $this->logic->attachment_url_to_postid_resolver( $url );

		$this->assertEquals( $attachment_id, $result );
	}

	public function test_attachment_url_to_postid_resolver_should_return_zero_when_not_found(): void {
		$result = $this->logic->attachment_url_to_postid_resolver( 'https://example.com/nonexistent.jpg' );

		$this->assertSame( 0, $result );
	}

	public function test_attachment_url_to_postid_resolver_should_substitute_alias_hostname(): void {
		$filename      = DIR_TESTDATA . '/images/canola.jpg';
		$attachment_id = self::factory()->attachment->create_upload_object( $filename );
		$url           = wp_get_attachment_url( $attachment_id );

		// Replace local hostname with alias.
		$local_host = wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST );
		$alias_url  = str_replace( $local_host, 'alias.example.com', $url );

		$result = $this->logic->attachment_url_to_postid_resolver( $alias_url, [ 'alias.example.com' ] );

		$this->assertEquals( $attachment_id, $result );
	}

	/**
	 * =========================================================================
	 * filter_array_elements Tests
	 * =========================================================================
	 */
	public function test_filter_array_elements_should_return_matching_subarrays(): void {
		$data = [
			[
				'ID'     => 1,
				'status' => 'active',
			],
			[
				'ID'     => 2,
				'status' => 'inactive',
			],
			[
				'ID'     => 3,
				'status' => 'active',
			],
		];

		$result = $this->logic->filter_array_elements( $data, 'status', 'active' );

		$this->assertCount( 2, $result );
		$this->assertSame( 1, $result[0]['ID'] );
		$this->assertSame( 3, $result[1]['ID'] );
	}

	public function test_filter_array_elements_should_return_empty_array_when_no_matches(): void {
		$data = [
			[
				'ID'     => 1,
				'status' => 'active',
			],
		];

		$result = $this->logic->filter_array_elements( $data, 'status', 'deleted' );

		$this->assertEmpty( $result );
	}

	public function test_filter_array_elements_should_use_loose_comparison(): void {
		$data = [
			[
				'ID'    => '1',
				'value' => 1,
			],
		];

		// Loose comparison: string '1' == int 1.
		$result = $this->logic->filter_array_elements( $data, 'ID', 1 );

		$this->assertCount( 1, $result );
	}

	/**
	 * =========================================================================
	 * Private Method Tests via Reflection
	 * =========================================================================
	 */
	public function test_get_empty_data_array_should_return_all_keys_with_empty_arrays(): void {
		$result = $this->invoke_private_method( $this->logic, 'get_empty_data_array' );

		$expected_keys = [
			'post',
			'postmeta',
			'comments',
			'commentmeta',
			'users',
			'usermeta',
			'term_relationships',
			'term_taxonomy',
			'terms',
			'termmeta',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $result );
			$this->assertIsArray( $result[ $key ] );
			$this->assertEmpty( $result[ $key ] );
		}
	}

	public function test_select_should_query_with_where_conditions(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();

		$result = $this->invoke_private_method( $this->logic, 'select', [ $wpdb->posts, [ 'ID' => $post_id ], true ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, (int) $result['ID'] );
	}

	public function test_select_should_query_without_where_conditions(): void {
		global $wpdb;
		self::factory()->post->create();

		$result = $this->invoke_private_method( $this->logic, 'select', [ $wpdb->posts, [], false ] );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_select_should_return_single_row_when_flag_true(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();

		$result = $this->invoke_private_method( $this->logic, 'select', [ $wpdb->posts, [ 'ID' => $post_id ], true ] );

		$this->assertArrayHasKey( 'ID', $result ); // Single row, not array of rows.
	}

	public function test_select_should_return_multiple_rows_when_flag_false(): void {
		global $wpdb;
		self::factory()->post->create();
		self::factory()->post->create();

		$result = $this->invoke_private_method( $this->logic, 'select', [ $wpdb->posts, [], false ] );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 2, count( $result ) );
		$this->assertArrayHasKey( 0, $result ); // Indexed array.
	}

	public function test_build_post_composite_key_should_normalize_values(): void {
		$post = [
			'post_name'  => '  Test Name  ',
			'post_title' => 'UPPERCASE',
		];

		$result1 = $this->invoke_private_method( $this->logic, 'build_post_composite_key', [ $post, [ 'post_name', 'post_title' ] ] );

		$post_normalized = [
			'post_name'  => 'test name',
			'post_title' => 'uppercase',
		];
		$result2         = $this->invoke_private_method( $this->logic, 'build_post_composite_key', [ $post_normalized, [ 'post_name', 'post_title' ] ] );

		$this->assertSame( $result1, $result2 );
	}

	public function test_build_post_composite_key_should_handle_missing_fields(): void {
		$post = [ 'post_name' => 'test' ];

		$result = $this->invoke_private_method( $this->logic, 'build_post_composite_key', [ $post, [ 'post_name', 'missing_field' ] ] );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_build_post_composite_key_should_return_md5_hash(): void {
		$post = [ 'post_name' => 'test' ];

		$result = $this->invoke_private_method( $this->logic, 'build_post_composite_key', [ $post, [ 'post_name' ] ] );

		$this->assertSame( 32, strlen( $result ) ); // MD5 hash is 32 chars.
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $result );
	}

	public function test_build_post_composite_key_for_post_should_use_correct_fields(): void {
		$post = [
			'post_name'   => 'test',
			'post_title'  => 'Test',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_date'   => '2025-01-01',
		];

		$result = $this->invoke_private_method( $this->logic, 'build_post_composite_key_for_post', [ $post ] );

		// Verify it returns a valid hash.
		$this->assertSame( 32, strlen( $result ) );
	}

	/**
	 * =========================================================================
	 * import_single_post Tests
	 * =========================================================================
	 */
	public function test_import_single_post_should_insert_post_and_return_result(): void {
		global $wpdb;
		// Create a post in the local DB to act as "live" source.
		$live_post_id = self::factory()->post->create(
			[
				'post_title'  => 'Import Test ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			] 
		);

		// Mock DataImporter to prevent actual import side effects. Use callback instead of willReturn for void method.
		$mock_data_importer = $this->createMock( DataImporter::class );
		$mock_data_importer->method( 'import_post_data' )->willReturnCallback( function() {} );

		$logic = new ContentDiffLogic( $wpdb, null, $mock_data_importer );

		$result = $logic->import_single_post( $live_post_id, $wpdb->prefix, [ 'category' ], 'test.example.com' );

		$this->assertArrayHasKey( 'post_type', $result );
		$this->assertArrayHasKey( 'id_old', $result );
		$this->assertArrayHasKey( 'id_new', $result );
		$this->assertSame( $live_post_id, $result['id_old'] );
		$this->assertNotEquals( $live_post_id, $result['id_new'] );
	}

	public function test_import_single_post_should_call_data_importer_import_post_data(): void {
		global $wpdb;
		$live_post_id = self::factory()->post->create();

		$mock_data_importer = $this->createMock( DataImporter::class );
		$mock_data_importer->expects( $this->once() )
			->method( 'import_post_data' );

		$logic = new ContentDiffLogic( $wpdb, null, $mock_data_importer );

		$logic->import_single_post( $live_post_id, $wpdb->prefix, [ 'category' ], 'test2.example.com' );
	}

	public function test_import_single_post_should_save_old_id_meta(): void {
		global $wpdb;
		$live_post_id = self::factory()->post->create();

		$mock_data_importer = $this->createMock( DataImporter::class );
		$logic              = new ContentDiffLogic( $wpdb, null, $mock_data_importer );

		$result = $logic->import_single_post( $live_post_id, $wpdb->prefix, [], 'meta.example.com' );

		$meta_key     = ContentDiffLogic::get_old_id_meta_key( 'meta.example.com' );
		$saved_old_id = get_post_meta( $result['id_new'], $meta_key, true );
		$this->assertEquals( $live_post_id, (int) $saved_old_id );
	}

	/**
	 * =========================================================================
	 * migrate_all_users Tests
	 * =========================================================================
	 */
	public function test_migrate_all_users_should_return_old_to_new_user_id_map(): void {
		global $wpdb;
		// Create a user in the DB.
		$user_id = self::factory()->user->create( [ 'user_login' => 'migrate_test_' . uniqid() ] );

		$mock_data_importer = $this->createMock( DataImporter::class );
		$mock_data_importer->method( 'get_or_create_user' )->willReturn(
			[
				'user_id'      => 999,
				'user_existed' => false,
			] 
		);

		$logic = new ContentDiffLogic( $wpdb, null, $mock_data_importer );

		$result = $logic->migrate_all_users( $wpdb->prefix, 'users.example.com' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( $user_id, $result );
		$this->assertEquals( 999, $result[ $user_id ] );
	}

	public function test_migrate_all_users_should_log_error_when_user_row_invalid(): void {
		global $wpdb;
		self::factory()->user->create();

		$mock_data_importer = $this->createMock( DataImporter::class );
		$mock_data_importer->method( 'get_or_create_user' )->willReturn( null );

		$logic = new ContentDiffLogic( $wpdb, null, $mock_data_importer );

		// Should not throw, should log and continue.
		$result = $logic->migrate_all_users( $wpdb->prefix, 'invalid.example.com' );

		$this->assertIsArray( $result );
	}

	public function test_migrate_all_users_should_catch_exception_and_continue(): void {
		global $wpdb;
		self::factory()->user->create();
		self::factory()->user->create();

		$mock_data_importer = $this->createMock( DataImporter::class );
		$call_count         = 0;
		$mock_data_importer->method( 'get_or_create_user' )
			->willReturnCallback(
				function() use ( &$call_count ) {
					$call_count++;
					if ( 1 === $call_count ) {
							throw new \Exception( 'Test exception' );
					}
					return [
						'user_id'      => 888,
						'user_existed' => false,
					];
				} 
			);

		$logic = new ContentDiffLogic( $wpdb, null, $mock_data_importer );

		$result = $logic->migrate_all_users( $wpdb->prefix, 'exception.example.com' );

		// Should have processed multiple users despite exception on first.
		$this->assertIsArray( $result );
	}

	public function test_migrate_all_users_should_return_empty_map_when_no_users(): void {
		global $wpdb;
		$mock_data_importer = $this->createMock( DataImporter::class );
		$logic              = new ContentDiffLogic( $wpdb, null, $mock_data_importer );

		// Suppress printing out error messages to stdout.
		$wpdb->suppress_errors( true );

		// Use non-existent prefix.
		$result = $logic->migrate_all_users( 'nonexistent_', 'nousers.example.com' );

		$wpdb->suppress_errors( false );

		$this->assertIsArray( $result );
	}

	/**
	 * =========================================================================
	 * filter_new_live_ids with old_id mapping Tests
	 * =========================================================================
	 */
	public function test_filter_new_live_ids_should_return_ids_not_in_mapping(): void {
		global $wpdb;

		$live_posts = [
			[
				'ID'            => 100,
				'post_modified' => '2025-01-01 00:00:00',
			],
			[
				'ID'            => 101,
				'post_modified' => '2025-01-01 00:00:00',
			],
			[
				'ID'            => 102,
				'post_modified' => '2025-01-01 00:00:00',
			],
		];

		// Only 100 and 101 are in the mapping, 102 is new.
		$old_id_map = [
			100 => 1,
			101 => 2,
		];

		$result = $this->logic->filter_new_live_ids( $live_posts, $old_id_map );

		$this->assertEquals( [ 102 ], $result );
	}

	public function test_filter_new_live_ids_should_return_empty_when_all_mapped(): void {
		global $wpdb;

		$live_posts = [
			[
				'ID'            => 100,
				'post_modified' => '2025-01-01 00:00:00',
			],
			[
				'ID'            => 101,
				'post_modified' => '2025-01-01 00:00:00',
			],
		];

		$old_id_map = [
			100 => 1,
			101 => 2,
		];

		$result = $this->logic->filter_new_live_ids( $live_posts, $old_id_map );

		$this->assertEmpty( $result );
	}

	public function test_filter_new_live_ids_should_return_all_when_mapping_empty(): void {
		global $wpdb;

		$live_posts = [
			[
				'ID'            => 100,
				'post_modified' => '2025-01-01 00:00:00',
			],
			[
				'ID'            => 101,
				'post_modified' => '2025-01-01 00:00:00',
			],
		];

		$result = $this->logic->filter_new_live_ids( $live_posts, [] );

		$this->assertEquals( [ 100, 101 ], $result );
	}

	/**
	 * =========================================================================
	 * filter_modified_live_ids with old_id mapping Tests
	 * =========================================================================
	 */
	public function test_filter_modified_live_ids_should_detect_post_modified_change(): void {
		global $wpdb;

		$live_posts = [
			[
				'ID'            => 100,
				'post_modified' => '2025-01-02 00:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];

		$local_posts = [
			[
				'ID'            => 1,
				'post_modified' => '2025-01-01 00:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];

		$old_id_map = [ 100 => 1 ];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertCount( 1, $result );
		$this->assertEquals( 100, $result[0]['live_id'] );
		$this->assertEquals( 1, $result[0]['local_id'] );
	}

	public function test_filter_modified_live_ids_should_detect_post_status_change(): void {
		global $wpdb;

		$live_posts = [
			[
				'ID'            => 100,
				'post_modified' => '2025-01-01 00:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];

		$local_posts = [
			[
				'ID'            => 1,
				'post_modified' => '2025-01-01 00:00:00',
				'post_status'   => 'draft',
				'post_author'   => 1,
			],
		];

		$old_id_map = [ 100 => 1 ];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertCount( 1, $result );
	}

	public function test_filter_modified_live_ids_should_skip_unimported_posts(): void {
		global $wpdb;

		$live_posts = [
			[
				'ID'            => 100,
				'post_modified' => '2025-01-02 00:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
			[
				'ID'            => 101,
				'post_modified' => '2025-01-02 00:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];

		$local_posts = [
			[
				'ID'            => 1,
				'post_modified' => '2025-01-01 00:00:00',
				'post_status'   => 'publish',
				'post_author'   => 1,
			],
		];

		// Only 100 is mapped, 101 is not imported yet.
		$old_id_map = [ 100 => 1 ];

		$result = $this->logic->filter_modified_live_ids( $live_posts, $local_posts, $old_id_map );

		$this->assertCount( 1, $result );
		$this->assertEquals( 100, $result[0]['live_id'] );
	}

	/**
	 * =========================================================================
	 * get_imported_user_id_mapping_from_db Tests
	 * =========================================================================
	 */
	public function test_get_imported_user_id_mapping_from_db_should_return_mapping(): void {
		global $wpdb;
		$source_hostname = 'user-mapping-test.example.com';
		$meta_key        = ContentDiffLogic::get_old_id_meta_key( $source_hostname );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, $meta_key, 999 );

		$result = $this->logic->get_imported_user_id_mapping_from_db( $source_hostname );

		$this->assertArrayHasKey( 999, $result );
		$this->assertEquals( $user_id, $result[999] );
	}

	public function test_get_imported_user_id_mapping_from_db_should_return_empty_for_no_matches(): void {
		global $wpdb;

		$result = $this->logic->get_imported_user_id_mapping_from_db( 'nonexistent-source.example.com' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * =========================================================================
	 * get_imported_term_id_mapping_from_db Tests
	 * =========================================================================
	 */
	public function test_get_imported_term_id_mapping_from_db_should_return_mapping(): void {
		global $wpdb;
		$source_hostname = 'term-mapping-test.example.com';
		$meta_key        = ContentDiffLogic::get_old_id_meta_key( $source_hostname );

		$term    = wp_insert_term( 'Test Term ' . uniqid(), 'category' );
		$term_id = $term['term_id'];
		update_term_meta( $term_id, $meta_key, 888 );

		$result = $this->logic->get_imported_term_id_mapping_from_db( $source_hostname );

		$this->assertArrayHasKey( 888, $result );
		$this->assertEquals( $term_id, $result[888] );
	}

	/**
	 * =========================================================================
	 * get_unattributed_*_ids Methods Tests
	 * =========================================================================
	 */
	public function test_get_unattributed_post_ids_should_return_ids_array(): void {
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'any-source.example.com' );

		// Create posts - one attributed to any source, one not.
		$post1 = self::factory()->post->create();
		$post2 = self::factory()->post->create();
		update_post_meta( $post1, $meta_key, 100 );

		$result = $this->logic->get_unattributed_post_ids( [ 'post' ] );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 1, count( $result ) );
		// Result is now an array of ['ID' => ..., 'post_type' => ...] arrays.
		$result_ids = array_column( $result, 'ID' );
		$this->assertContains( (string) $post2, $result_ids );
		$this->assertNotContains( (string) $post1, $result_ids );
	}

	public function test_get_unattributed_post_ids_should_return_empty_for_empty_post_types(): void {
		$result = $this->logic->get_unattributed_post_ids( [] );
		$this->assertEquals( [], $result );
	}

	public function test_get_unattributed_attachment_ids_should_return_ids_array(): void {
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'any-source.example.com' );

		// Create attachments - one attributed to any source, one not.
		$attachment1 = self::factory()->attachment->create();
		$attachment2 = self::factory()->attachment->create();
		update_post_meta( $attachment1, $meta_key, 200 );

		$result = $this->logic->get_unattributed_attachment_ids();

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 1, count( $result ) );
		$this->assertContains( $attachment2, $result );
		$this->assertNotContains( $attachment1, $result );
	}

	public function test_get_unattributed_user_ids_should_return_ids_array(): void {
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'any-source.example.com' );

		// Create users - one attributed to any source, one not.
		$user1 = self::factory()->user->create();
		$user2 = self::factory()->user->create();
		update_user_meta( $user1, $meta_key, 300 );

		$result = $this->logic->get_unattributed_user_ids();

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 1, count( $result ) );
		$this->assertContains( $user2, $result );
		$this->assertNotContains( $user1, $result );
	}

	public function test_get_unattributed_term_ids_should_return_ids_array(): void {
		$meta_key = ContentDiffLogic::get_old_id_meta_key( 'any-source.example.com' );

		// Create terms - one attributed to any source, one not.
		$term1 = wp_insert_term( 'Unattributed Test Term 1 ' . uniqid(), 'category' );
		$term2 = wp_insert_term( 'Unattributed Test Term 2 ' . uniqid(), 'category' );
		update_term_meta( $term1['term_id'], $meta_key, 400 );

		$result = $this->logic->get_unattributed_term_ids();

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 1, count( $result ) );
		$this->assertContains( $term2['term_id'], $result );
		$this->assertNotContains( $term1['term_id'], $result );
	}

	public function test_get_unattributed_post_ids_excludes_posts_attributed_to_any_source(): void {
		// Create a post attributed to some hostname.
		$post_attributed = self::factory()->post->create();
		update_post_meta( $post_attributed, ContentDiffLogic::get_old_id_meta_key( 'some-host.com' ), 999 );

		// Create a post with no attribution at all.
		$post_unattributed = self::factory()->post->create();

		$result = $this->logic->get_unattributed_post_ids( [ 'post' ] );

		$this->assertIsArray( $result );
		// Result is now an array of ['ID' => ..., 'post_type' => ...] arrays.
		$result_ids = array_column( $result, 'ID' );
		$this->assertContains( (string) $post_unattributed, $result_ids );
		$this->assertNotContains( (string) $post_attributed, $result_ids );
	}

	/**
	 * =========================================================================
	 * get_attributed_post_ids Tests
	 * =========================================================================
	 */
	public function test_get_attributed_post_ids_should_return_attributed_ids(): void {
		$source_hostname = 'test-source.example.com';
		$meta_key        = ContentDiffLogic::get_old_id_meta_key( $source_hostname );

		// Create posts - one attributed to test source, one to different source, one unattributed.
		$post_attributed   = self::factory()->post->create();
		$post_other_source = self::factory()->post->create();
		$post_unattributed = self::factory()->post->create();

		update_post_meta( $post_attributed, $meta_key, 999 );
		update_post_meta( $post_other_source, ContentDiffLogic::get_old_id_meta_key( 'other.com' ), 888 );

		$result = $this->logic->get_attributed_post_ids( $source_hostname, [ $post_attributed, $post_other_source, $post_unattributed ] );

		$this->assertIsArray( $result );
		$this->assertContains( $post_attributed, $result );
		$this->assertNotContains( $post_other_source, $result );
		$this->assertNotContains( $post_unattributed, $result );
	}

	public function test_get_attributed_post_ids_should_return_empty_for_empty_input(): void {
		$result = $this->logic->get_attributed_post_ids( 'test.example.com', [] );
		$this->assertEquals( [], $result );
	}

	public function test_get_attributed_post_ids_should_filter_by_source_hostname(): void {
		$source_hostname_1 = 'source1.example.com';
		$source_hostname_2 = 'source2.example.com';

		$post1 = self::factory()->post->create();
		$post2 = self::factory()->post->create();

		update_post_meta( $post1, ContentDiffLogic::get_old_id_meta_key( $source_hostname_1 ), 100 );
		update_post_meta( $post2, ContentDiffLogic::get_old_id_meta_key( $source_hostname_2 ), 200 );

		$result = $this->logic->get_attributed_post_ids( $source_hostname_1, [ $post1, $post2 ] );

		$this->assertIsArray( $result );
		$this->assertContains( $post1, $result );
		$this->assertNotContains( $post2, $result );
	}

	/**
	 * =========================================================================
	 * get_attributed_attachment_ids Tests
	 * =========================================================================
	 */
	public function test_get_attributed_attachment_ids_should_return_attributed_ids(): void {
		$source_hostname = 'test-source.example.com';
		$meta_key        = ContentDiffLogic::get_old_id_meta_key( $source_hostname );

		// Create attachments - one attributed to test source, one to different source, one unattributed.
		$attachment_attributed   = self::factory()->attachment->create();
		$attachment_other_source = self::factory()->attachment->create();
		$attachment_unattributed = self::factory()->attachment->create();

		update_post_meta( $attachment_attributed, $meta_key, 999 );
		update_post_meta( $attachment_other_source, ContentDiffLogic::get_old_id_meta_key( 'other.com' ), 888 );

		$result = $this->logic->get_attributed_attachment_ids( $source_hostname, [ $attachment_attributed, $attachment_other_source, $attachment_unattributed ] );

		$this->assertIsArray( $result );
		$this->assertContains( $attachment_attributed, $result );
		$this->assertNotContains( $attachment_other_source, $result );
		$this->assertNotContains( $attachment_unattributed, $result );
	}

	public function test_get_attributed_attachment_ids_should_return_empty_for_empty_input(): void {
		$result = $this->logic->get_attributed_attachment_ids( 'test.example.com', [] );
		$this->assertEquals( [], $result );
	}

	public function test_get_attributed_attachment_ids_should_filter_by_source_hostname(): void {
		$source_hostname_1 = 'source1.example.com';
		$source_hostname_2 = 'source2.example.com';

		$attachment1 = self::factory()->attachment->create();
		$attachment2 = self::factory()->attachment->create();

		update_post_meta( $attachment1, ContentDiffLogic::get_old_id_meta_key( $source_hostname_1 ), 100 );
		update_post_meta( $attachment2, ContentDiffLogic::get_old_id_meta_key( $source_hostname_2 ), 200 );

		$result = $this->logic->get_attributed_attachment_ids( $source_hostname_1, [ $attachment1, $attachment2 ] );

		$this->assertIsArray( $result );
		$this->assertContains( $attachment1, $result );
		$this->assertNotContains( $attachment2, $result );
	}

	/**
	 * =========================================================================
	 * get_attributed_user_ids Tests
	 * =========================================================================
	 */
	public function test_get_attributed_user_ids_should_return_attributed_ids(): void {
		$source_hostname = 'test-source.example.com';
		$meta_key        = ContentDiffLogic::get_old_id_meta_key( $source_hostname );

		// Create users - one attributed to test source, one to different source, one unattributed.
		$user_attributed   = self::factory()->user->create();
		$user_other_source = self::factory()->user->create();
		$user_unattributed = self::factory()->user->create();

		update_user_meta( $user_attributed, $meta_key, 999 );
		update_user_meta( $user_other_source, ContentDiffLogic::get_old_id_meta_key( 'other.com' ), 888 );

		$result = $this->logic->get_attributed_user_ids( $source_hostname, [ $user_attributed, $user_other_source, $user_unattributed ] );

		$this->assertIsArray( $result );
		$this->assertContains( $user_attributed, $result );
		$this->assertNotContains( $user_other_source, $result );
		$this->assertNotContains( $user_unattributed, $result );
	}

	public function test_get_attributed_user_ids_should_return_empty_for_empty_input(): void {
		$result = $this->logic->get_attributed_user_ids( 'test.example.com', [] );
		$this->assertEquals( [], $result );
	}

	public function test_get_attributed_user_ids_should_filter_by_source_hostname(): void {
		$source_hostname_1 = 'source1.example.com';
		$source_hostname_2 = 'source2.example.com';

		$user1 = self::factory()->user->create();
		$user2 = self::factory()->user->create();

		update_user_meta( $user1, ContentDiffLogic::get_old_id_meta_key( $source_hostname_1 ), 100 );
		update_user_meta( $user2, ContentDiffLogic::get_old_id_meta_key( $source_hostname_2 ), 200 );

		$result = $this->logic->get_attributed_user_ids( $source_hostname_1, [ $user1, $user2 ] );

		$this->assertIsArray( $result );
		$this->assertContains( $user1, $result );
		$this->assertNotContains( $user2, $result );
	}

	/**
	 * =========================================================================
	 * get_attributed_term_ids Tests
	 * =========================================================================
	 */
	public function test_get_attributed_term_ids_should_return_attributed_ids(): void {
		$source_hostname = 'test-source.example.com';
		$meta_key        = ContentDiffLogic::get_old_id_meta_key( $source_hostname );

		// Create terms - one attributed to test source, one to different source, one unattributed.
		$term_attributed   = wp_insert_term( 'Attributed Term ' . uniqid(), 'category' );
		$term_other_source = wp_insert_term( 'Other Source Term ' . uniqid(), 'category' );
		$term_unattributed = wp_insert_term( 'Unattributed Term ' . uniqid(), 'category' );

		update_term_meta( $term_attributed['term_id'], $meta_key, 999 );
		update_term_meta( $term_other_source['term_id'], ContentDiffLogic::get_old_id_meta_key( 'other.com' ), 888 );

		$result = $this->logic->get_attributed_term_ids( $source_hostname, [ $term_attributed['term_id'], $term_other_source['term_id'], $term_unattributed['term_id'] ] );

		$this->assertIsArray( $result );
		$this->assertContains( $term_attributed['term_id'], $result );
		$this->assertNotContains( $term_other_source['term_id'], $result );
		$this->assertNotContains( $term_unattributed['term_id'], $result );
	}

	public function test_get_attributed_term_ids_should_return_empty_for_empty_input(): void {
		$result = $this->logic->get_attributed_term_ids( 'test.example.com', [] );
		$this->assertEquals( [], $result );
	}

	public function test_get_attributed_term_ids_should_filter_by_source_hostname(): void {
		$source_hostname_1 = 'source1.example.com';
		$source_hostname_2 = 'source2.example.com';

		$term1 = wp_insert_term( 'Term Source 1 ' . uniqid(), 'category' );
		$term2 = wp_insert_term( 'Term Source 2 ' . uniqid(), 'category' );

		update_term_meta( $term1['term_id'], ContentDiffLogic::get_old_id_meta_key( $source_hostname_1 ), 100 );
		update_term_meta( $term2['term_id'], ContentDiffLogic::get_old_id_meta_key( $source_hostname_2 ), 200 );

		$result = $this->logic->get_attributed_term_ids( $source_hostname_1, [ $term1['term_id'], $term2['term_id'] ] );

		$this->assertIsArray( $result );
		$this->assertContains( $term1['term_id'], $result );
		$this->assertNotContains( $term2['term_id'], $result );
	}

	/**
	 * =========================================================================
	 * get_terms_rows_for_attribution Tests
	 * =========================================================================
	 */
	public function test_get_terms_rows_for_attribution_should_return_terms_with_taxonomy(): void {
		global $wpdb;

		$term = wp_insert_term( 'Attribution Term ' . uniqid(), 'category' );

		$result = $this->logic->get_terms_rows_for_attribution( $wpdb->prefix );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$found = false;
		foreach ( $result as $row ) {
			if ( (int) $row['term_id'] === $term['term_id'] ) {
				$found = true;
				$this->assertEquals( 'category', $row['taxonomy'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Created term should be in results' );
	}

	/**
	 * =========================================================================
	 * match_local_to_live_terms Tests
	 * =========================================================================
	 */
	public function test_match_local_to_live_terms_should_match_by_slug_and_taxonomy(): void {
		global $wpdb;

		$local_terms = [
			[
				'term_id'  => 1,
				'slug'     => 'news',
				'name'     => 'News',
				'taxonomy' => 'category',
			],
			[
				'term_id'  => 2,
				'slug'     => 'tech',
				'name'     => 'Tech',
				'taxonomy' => 'category',
			],
		];

		$live_terms = [
			[
				'term_id'  => 100,
				'slug'     => 'news',
				'name'     => 'News',
				'taxonomy' => 'category',
			],
			[
				'term_id'  => 101,
				'slug'     => 'sports',
				'name'     => 'Sports',
				'taxonomy' => 'category',
			],
		];

		$result = $this->logic->match_local_to_live_terms( $local_terms, $live_terms );

		$this->assertCount( 1, $result );
		$this->assertEquals( 1, $result[0]['local_id'] );
		$this->assertEquals( 100, $result[0]['live_id'] );
	}

	public function test_match_local_to_live_terms_should_not_match_different_taxonomies(): void {
		global $wpdb;

		$local_terms = [
			[
				'term_id'  => 1,
				'slug'     => 'news',
				'name'     => 'News',
				'taxonomy' => 'category',
			],
		];

		$live_terms = [
			[
				'term_id'  => 100,
				'slug'     => 'news',
				'name'     => 'News',
				'taxonomy' => 'post_tag',
			],
		];

		$result = $this->logic->match_local_to_live_terms( $local_terms, $live_terms );

		$this->assertEmpty( $result );
	}

	/**
	 * =========================================================================
	 * update_modified_users Tests
	 * =========================================================================
	 */
	public function test_update_modified_users_should_return_empty_when_no_mapping(): void {
		global $wpdb;

		$result = $this->logic->update_modified_users( $wpdb->prefix, 'no-users.example.com' );

		$this->assertEquals( 0, $result['checked'] );
		$this->assertEquals( 0, $result['updated'] );
	}

	/**
	 * =========================================================================
	 * update_modified_attachments Tests
	 * =========================================================================
	 */
	public function test_update_modified_attachments_should_return_empty_when_no_mapping(): void {
		global $wpdb;

		$result = $this->logic->update_modified_attachments( $wpdb->prefix, 'no-attachments.example.com' );

		$this->assertEquals( 0, $result['checked'] );
		$this->assertEquals( 0, $result['updated'] );
	}

	/**
	 * =========================================================================
	 * update_modified_terms Tests
	 * =========================================================================
	 */
	public function test_update_modified_terms_should_return_empty_when_no_mapping(): void {
		global $wpdb;

		$result = $this->logic->update_modified_terms( $wpdb->prefix, 'no-terms.example.com' );

		$this->assertEquals( 0, $result['checked'] );
		$this->assertEquals( 0, $result['updated'] );
	}
}
