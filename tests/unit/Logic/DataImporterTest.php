<?php
/**
 * Unit tests for DataImporter.
 *
 * Tests data import operations for posts, meta, users, comments, and taxonomies.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Unit\Logic;

use WP_UnitTestCase;
use Newspack\ContentDiffMigrator\Logic\DataImporter;
use Newspack\ContentDiffMigrator\Logic\ContentDiffLogic;
use Newspack\ContentDiffMigrator\Utils\Logger;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit test class for DataImporter.
 */
class DataImporterTest extends WP_UnitTestCase {

	/**
	 * DataImporter instance.
	 *
	 * @var DataImporter
	 */
	private DataImporter $importer;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Disable logging for tests.
		Logger::configure( false );

		global $wpdb;
		$this->importer = new DataImporter( $wpdb );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Reset internal maps between tests.
		$this->reset_private_property( $this->importer, 'taxonomy_term_id_map', [] );
		$this->reset_private_property( $this->importer, 'termmeta_imported', [] );
		parent::tearDown();
	}

	/**
	 * Invoke a private method on an object.
	 *
	 * @param object $test_object Object instance.
	 * @param string $method_name Method name.
	 * @param array  $args        Method arguments.
	 *
	 * @return mixed Method return value.
	 */
	private function invoke_private_method( object $test_object, string $method_name, array $args = [] ) {
		$reflection = new ReflectionMethod( get_class( $test_object ), $method_name );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $test_object, $args );
	}

	/**
	 * Get a private property value.
	 *
	 * @param object $test_object   Object instance.
	 * @param string $property_name Property name.
	 *
	 * @return mixed Property value.
	 */
	private function get_private_property( object $test_object, string $property_name ) {
		$reflection = new ReflectionProperty( get_class( $test_object ), $property_name );
		$reflection->setAccessible( true );
		return $reflection->getValue( $test_object );
	}

	/**
	 * Set a private property value.
	 *
	 * @param object $test_object   Object instance.
	 * @param string $property_name Property name.
	 * @param mixed  $value         New value.
	 */
	private function reset_private_property( object $test_object, string $property_name, $value ): void {
		$reflection = new ReflectionProperty( get_class( $test_object ), $property_name );
		$reflection->setAccessible( true );
		$reflection->setValue( $test_object, $value );
	}

	/**
	 * Creates a test post and returns its ID.
	 *
	 * @param array $args Post args.
	 *
	 * @return int Post ID.
	 */
	private function create_test_post( array $args = [] ): int {
		return $this->factory->post->create( $args );
	}

	/**
	 * Creates a test user and returns its ID.
	 *
	 * @param array $args User args.
	 *
	 * @return int User ID.
	 */
	private function create_test_user( array $args = [] ): int {
		return $this->factory->user->create( $args );
	}

	/**
	 * Creates a test comment and returns its ID.
	 *
	 * @param array $args Comment args.
	 *
	 * @return int Comment ID.
	 */
	private function create_test_comment( array $args = [] ): int {
		return $this->factory->comment->create( $args );
	}

	/**
	 * Creates a mock wpdb object that fails on insert/update operations.
	 *
	 * @param string $fail_on    Which operation to fail: 'insert', 'update', or 'both'.
	 * @param array  $tables     Table name properties to set on the mock.
	 *
	 * @return \wpdb Mock wpdb object.
	 */
	private function create_failing_wpdb_mock( string $fail_on = 'insert', array $tables = [] ): \wpdb {
		$mock_wpdb = $this->createMock( \wpdb::class );

		// Set table name properties.
		$default_tables = [
			'postmeta'           => 'wp_postmeta',
			'usermeta'           => 'wp_usermeta',
			'comments'           => 'wp_comments',
			'commentmeta'        => 'wp_commentmeta',
			'termmeta'           => 'wp_termmeta',
			'term_relationships' => 'wp_term_relationships',
			'posts'              => 'wp_posts',
			'users'              => 'wp_users',
			'prefix'             => 'wp_',
		];
		$tables         = array_merge( $default_tables, $tables );
		foreach ( $tables as $property => $value ) {
			$mock_wpdb->$property = $value;
		}

		// Configure failures.
		if ( 'insert' === $fail_on || 'both' === $fail_on ) {
			$mock_wpdb->method( 'insert' )->willReturn( 0 );
		}
		if ( 'update' === $fail_on || 'both' === $fail_on ) {
			$mock_wpdb->method( 'update' )->willReturn( 0 );
		}

		return $mock_wpdb;
	}

	/**
	 * Builds a minimal post data array for testing.
	 *
	 * @param int   $post_id       Post ID.
	 * @param array $postmeta      Postmeta rows.
	 * @param array $comments      Comments rows.
	 * @param array $commentmeta   Commentmeta rows.
	 * @param array $users         Users rows.
	 * @param array $usermeta      Usermeta rows.
	 * @param array $relationships Term relationships rows.
	 * @param array $taxonomies    Term taxonomy rows.
	 * @param array $terms         Terms rows.
	 * @param array $termmeta      Termmeta rows.
	 *
	 * @return array Post data array.
	 */
	private function build_post_data(
		int $post_id,
		array $postmeta = [],
		array $comments = [],
		array $commentmeta = [],
		array $users = [],
		array $usermeta = [],
		array $relationships = [],
		array $taxonomies = [],
		array $terms = [],
		array $termmeta = []
	): array {
		return [
			ContentDiffLogic::DATAKEY_POST              => [
				'ID'          => $post_id,
				'post_author' => 1,
			],
			ContentDiffLogic::DATAKEY_POSTMETA          => $postmeta,
			ContentDiffLogic::DATAKEY_COMMENTS          => $comments,
			ContentDiffLogic::DATAKEY_COMMENTMETA       => $commentmeta,
			ContentDiffLogic::DATAKEY_USERS             => $users,
			ContentDiffLogic::DATAKEY_USERMETA          => $usermeta,
			ContentDiffLogic::DATAKEY_TERMRELATIONSHIPS => $relationships,
			ContentDiffLogic::DATAKEY_TERMTAXONOMY      => $taxonomies,
			ContentDiffLogic::DATAKEY_TERMS             => $terms,
			ContentDiffLogic::DATAKEY_TERMMETA          => $termmeta,
		];
	}

	// =========================================================================
	// CONSTRUCTOR TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::__construct
	 */
	public function test_constructor_should_set_wpdb_property(): void {
		global $wpdb;
		$importer = new DataImporter( $wpdb );

		$wpdb_property = $this->get_private_property( $importer, 'wpdb' );

		$this->assertSame( $wpdb, $wpdb_property );
	}

	/**
	 * @test
	 * @covers DataImporter::__construct
	 */
	public function test_constructor_should_initialize_empty_maps(): void {
		global $wpdb;
		$importer = new DataImporter( $wpdb );

		$taxonomy_map = $this->get_private_property( $importer, 'taxonomy_term_id_map' );
		$termmeta_map = $this->get_private_property( $importer, 'termmeta_imported' );

		$this->assertEquals( [], $taxonomy_map );
		$this->assertEquals( [], $termmeta_map );
	}

	// =========================================================================
	// FILTER_ARRAY_ELEMENT TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::filter_array_element
	 */
	public function test_filter_array_element_should_return_matching_subarray(): void {
		$data = [
			[
				'id'   => 1,
				'name' => 'first',
			],
			[
				'id'   => 2,
				'name' => 'second',
			],
			[
				'id'   => 3,
				'name' => 'third',
			],
		];

		$result = $this->invoke_private_method( $this->importer, 'filter_array_element', [ $data, 'id', 2 ] );

		$this->assertEquals(
			[
				'id'   => 2,
				'name' => 'second',
			],
			$result 
		);
	}

	/**
	 * @test
	 * @covers DataImporter::filter_array_element
	 */
	public function test_filter_array_element_should_return_null_when_no_match(): void {
		$data = [
			[
				'id'   => 1,
				'name' => 'first',
			],
			[
				'id'   => 2,
				'name' => 'second',
			],
		];

		$result = $this->invoke_private_method( $this->importer, 'filter_array_element', [ $data, 'id', 999 ] );

		$this->assertNull( $result );
	}

	/**
	 * @test
	 * @covers DataImporter::filter_array_element
	 */
	public function test_filter_array_element_should_return_first_match_when_multiple_exist(): void {
		$data = [
			[
				'id'   => 1,
				'type' => 'post',
			],
			[
				'id'   => 2,
				'type' => 'post',
			],
			[
				'id'   => 3,
				'type' => 'page',
			],
		];

		$result = $this->invoke_private_method( $this->importer, 'filter_array_element', [ $data, 'type', 'post' ] );

		$this->assertEquals(
			[
				'id'   => 1,
				'type' => 'post',
			],
			$result 
		);
	}

	/**
	 * @test
	 * @covers DataImporter::filter_array_element
	 */
	public function test_filter_array_element_should_handle_empty_array(): void {
		$result = $this->invoke_private_method( $this->importer, 'filter_array_element', [ [], 'id', 1 ] );

		$this->assertNull( $result );
	}

	// =========================================================================
	// FILTER_ARRAY_ELEMENTS TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::filter_array_elements
	 */
	public function test_filter_array_elements_should_return_all_matching_subarrays(): void {
		$data = [
			[
				'id'   => 1,
				'type' => 'post',
			],
			[
				'id'   => 2,
				'type' => 'post',
			],
			[
				'id'   => 3,
				'type' => 'page',
			],
		];

		$result = $this->invoke_private_method( $this->importer, 'filter_array_elements', [ $data, 'type', 'post' ] );

		$this->assertCount( 2, $result );
		$this->assertEquals(
			[
				'id'   => 1,
				'type' => 'post',
			],
			$result[0] 
		);
		$this->assertEquals(
			[
				'id'   => 2,
				'type' => 'post',
			],
			$result[1] 
		);
	}

	/**
	 * @test
	 * @covers DataImporter::filter_array_elements
	 */
	public function test_filter_array_elements_should_return_empty_array_when_no_match(): void {
		$data = [
			[
				'id'   => 1,
				'type' => 'post',
			],
			[
				'id'   => 2,
				'type' => 'page',
			],
		];

		$result = $this->invoke_private_method( $this->importer, 'filter_array_elements', [ $data, 'type', 'attachment' ] );

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers DataImporter::filter_array_elements
	 */
	public function test_filter_array_elements_should_handle_empty_array(): void {
		$result = $this->invoke_private_method( $this->importer, 'filter_array_elements', [ [], 'id', 1 ] );

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers DataImporter::filter_array_elements
	 */
	public function test_filter_array_elements_should_return_multiple_matches(): void {
		$data = [
			[
				'comment_id' => 100,
				'meta_key'   => 'key1',
			],
			[
				'comment_id' => 100,
				'meta_key'   => 'key2',
			],
			[
				'comment_id' => 100,
				'meta_key'   => 'key3',
			],
			[
				'comment_id' => 200,
				'meta_key'   => 'key4',
			],
		];

		$result = $this->invoke_private_method( $this->importer, 'filter_array_elements', [ $data, 'comment_id', 100 ] );

		$this->assertCount( 3, $result );
	}

	// =========================================================================
	// INSERT_POSTMETA_ROW TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::insert_postmeta_row
	 */
	public function test_insert_postmeta_row_should_insert_and_return_meta_id(): void {
		$post_id      = $this->create_test_post();
		$postmeta_row = [
			'meta_id'    => 999,
			'post_id'    => 888,
			'meta_key'   => 'test_key',
			'meta_value' => 'test_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$result = $this->invoke_private_method( $this->importer, 'insert_postmeta_row', [ $postmeta_row, $post_id ] );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertEquals( 'test_value', get_post_meta( $post_id, 'test_key', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_postmeta_row
	 */
	public function test_insert_postmeta_row_should_replace_post_id_and_remove_meta_id(): void {
		global $wpdb;
		$post_id      = $this->create_test_post();
		$postmeta_row = [
			'meta_id'    => 12345,
			'post_id'    => 99999,
			'meta_key'   => 'replaced_key',
			'meta_value' => 'replaced_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$meta_id = $this->invoke_private_method( $this->importer, 'insert_postmeta_row', [ $postmeta_row, $post_id ] );

		// Verify the meta was inserted with the correct post_id.
		$inserted = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE meta_id = %d", $meta_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertEquals( $post_id, $inserted['post_id'] );
		$this->assertNotEquals( 12345, $meta_id ); // Original meta_id should not be used.
	}

	/**
	 * @test
	 * @covers DataImporter::insert_postmeta_row
	 */
	public function test_insert_postmeta_row_should_throw_on_insert_failure(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$postmeta_row = [
			'meta_id'    => 1,
			'post_id'    => 1,
			'meta_key'   => 'test_key',
			'meta_value' => 'test_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Error in insert_postmeta_row' );

		$this->invoke_private_method( $importer, 'insert_postmeta_row', [ $postmeta_row, 123 ] );
	}

	// =========================================================================
	// INSERT_USERMETA_ROW TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::insert_usermeta_row
	 */
	public function test_insert_usermeta_row_should_insert_and_return_umeta_id(): void {
		$user_id      = $this->create_test_user();
		$usermeta_row = [
			'umeta_id'   => 999,
			'user_id'    => 888,
			'meta_key'   => 'test_user_key',
			'meta_value' => 'test_user_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$result = $this->importer->insert_usermeta_row( $usermeta_row, $user_id );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertEquals( 'test_user_value', get_user_meta( $user_id, 'test_user_key', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_usermeta_row
	 */
	public function test_insert_usermeta_row_should_replace_user_id_and_remove_umeta_id(): void {
		global $wpdb;
		$user_id      = $this->create_test_user();
		$usermeta_row = [
			'umeta_id'   => 12345,
			'user_id'    => 99999,
			'meta_key'   => 'replaced_user_key',
			'meta_value' => 'replaced_user_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$umeta_id = $this->importer->insert_usermeta_row( $usermeta_row, $user_id );

		$inserted = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->usermeta} WHERE umeta_id = %d", $umeta_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertEquals( $user_id, (int) $inserted['user_id'] );
		$this->assertNotEquals( 12345, $umeta_id );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_usermeta_row
	 */
	public function test_insert_usermeta_row_should_throw_on_insert_failure(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$usermeta_row = [
			'umeta_id'   => 1,
			'user_id'    => 1,
			'meta_key'   => 'test_key',
			'meta_value' => 'test_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Error inserting user meta' );

		$importer->insert_usermeta_row( $usermeta_row, 123 );
	}

	// =========================================================================
	// GET_OR_CREATE_USER TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::get_or_create_user
	 */
	public function test_get_or_create_user_should_return_existing_user_id(): void {
		$user_id  = $this->create_test_user( [ 'user_login' => 'existing_user_login' ] );
		$user_row = [
			'ID'         => 999,
			'user_login' => 'existing_user_login',
			'user_email' => 'test@example.com',
		];

		$result = $this->importer->get_or_create_user( $user_row, [], 'example.com' );

		$this->assertIsArray( $result );
		$this->assertEquals( $user_id, $result['user_id'] );
		$this->assertTrue( $result['user_existed'] );
	}

	/**
	 * @test
	 * @covers DataImporter::get_or_create_user
	 */
	public function test_get_or_create_user_should_create_new_user_when_not_exists(): void {
		$user_row = [
			'ID'            => 999,
			'user_login'    => 'brand_new_user_' . uniqid(),
			'user_email'    => 'newuser_' . uniqid() . '@example.com',
			'user_pass'     => 'hashedpass',
			'user_nicename' => 'new-user',
			'display_name'  => 'New User',
		];

		$result = $this->importer->get_or_create_user( $user_row, [], 'example.com' );

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['user_id'] );
		$this->assertGreaterThan( 0, $result['user_id'] );
		$this->assertFalse( $result['user_existed'] );

		$user = get_user_by( 'id', $result['user_id'] );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertEquals( $user_row['user_login'], $user->user_login );
	}

	/**
	 * @test
	 * @covers DataImporter::get_or_create_user
	 */
	public function test_get_or_create_user_should_insert_usermeta_for_new_user(): void {
		$user_row      = [
			'ID'            => 999,
			'user_login'    => 'user_with_meta_' . uniqid(),
			'user_email'    => 'userwithmeta_' . uniqid() . '@example.com',
			'user_pass'     => 'hashedpass',
			'user_nicename' => 'user-with-meta',
			'display_name'  => 'User With Meta',
		];
		$usermeta_rows = [
			[
				'umeta_id'   => 1,
				'user_id'    => 999,
				'meta_key'   => 'custom_meta',
				'meta_value' => 'custom_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];

		$result = $this->importer->get_or_create_user( $user_row, $usermeta_rows, 'example.com' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'custom_value', get_user_meta( $result['user_id'], 'custom_meta', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::get_or_create_user
	 */
	public function test_get_or_create_user_should_return_null_for_empty_user_row(): void {
		$result = $this->importer->get_or_create_user( [], [], 'example.com' );

		$this->assertNull( $result );
	}

	/**
	 * @test
	 * @covers DataImporter::get_or_create_user
	 */
	public function test_get_or_create_user_should_return_null_when_user_login_missing(): void {
		$user_row = [
			'ID'         => 999,
			'user_email' => 'nologin@example.com',
		];

		$result = $this->importer->get_or_create_user( $user_row, [], 'example.com' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// INSERT_COMMENT TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::insert_comment
	 */
	public function test_insert_comment_should_insert_and_return_comment_id(): void {
		$post_id     = $this->create_test_post();
		$comment_row = [
			'comment_ID'       => 999,
			'comment_post_ID'  => 888,
			'user_id'          => 777,
			'comment_content'  => 'Test comment content',
			'comment_author'   => 'Test Author',
			'comment_date'     => '2024-01-01 12:00:00',
			'comment_date_gmt' => '2024-01-01 12:00:00',
		];

		$result = $this->invoke_private_method( $this->importer, 'insert_comment', [ $comment_row, $post_id, 0 ] );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );

		$comment = get_comment( $result );
		$this->assertEquals( 'Test comment content', $comment->comment_content );
		$this->assertEquals( $post_id, $comment->comment_post_ID );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_comment
	 */
	public function test_insert_comment_should_replace_post_id_user_id_and_remove_comment_id(): void {
		global $wpdb;
		$post_id     = $this->create_test_post();
		$user_id     = $this->create_test_user();
		$comment_row = [
			'comment_ID'       => 99999,
			'comment_post_ID'  => 88888,
			'user_id'          => 77777,
			'comment_content'  => 'Replaced IDs test',
			'comment_author'   => 'Author',
			'comment_date'     => '2024-01-01 12:00:00',
			'comment_date_gmt' => '2024-01-01 12:00:00',
		];

		$comment_id = $this->invoke_private_method( $this->importer, 'insert_comment', [ $comment_row, $post_id, $user_id ] );

		$inserted = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", $comment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertEquals( $post_id, (int) $inserted['comment_post_ID'] );
		$this->assertEquals( $user_id, (int) $inserted['user_id'] );
		$this->assertNotEquals( 99999, $comment_id );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_comment
	 */
	public function test_insert_comment_should_throw_on_insert_failure(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$comment_row = [
			'comment_ID'       => 1,
			'comment_post_ID'  => 1,
			'user_id'          => 0,
			'comment_content'  => 'Test comment',
			'comment_author'   => 'Author',
			'comment_date'     => '2024-01-01 12:00:00',
			'comment_date_gmt' => '2024-01-01 12:00:00',
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Error inserting comment' );

		$this->invoke_private_method( $importer, 'insert_comment', [ $comment_row, 123, 0 ] );
	}

	// =========================================================================
	// INSERT_COMMENTMETA_ROW TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::insert_commentmeta_row
	 */
	public function test_insert_commentmeta_row_should_insert_and_return_meta_id(): void {
		$post_id         = $this->create_test_post();
		$comment_id      = $this->create_test_comment( [ 'comment_post_ID' => $post_id ] );
		$commentmeta_row = [
			'meta_id'    => 999,
			'comment_id' => 888,
			'meta_key'   => 'test_comment_meta',
			'meta_value' => 'test_comment_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$result = $this->invoke_private_method( $this->importer, 'insert_commentmeta_row', [ $commentmeta_row, $comment_id ] );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertEquals( 'test_comment_value', get_comment_meta( $comment_id, 'test_comment_meta', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_commentmeta_row
	 */
	public function test_insert_commentmeta_row_should_replace_comment_id_and_remove_meta_id(): void {
		global $wpdb;
		$post_id         = $this->create_test_post();
		$comment_id      = $this->create_test_comment( [ 'comment_post_ID' => $post_id ] );
		$commentmeta_row = [
			'meta_id'    => 12345,
			'comment_id' => 99999,
			'meta_key'   => 'replaced_comment_key',
			'meta_value' => 'replaced_comment_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$meta_id = $this->invoke_private_method( $this->importer, 'insert_commentmeta_row', [ $commentmeta_row, $comment_id ] );

		$inserted = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->commentmeta} WHERE meta_id = %d", $meta_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertEquals( $comment_id, (int) $inserted['comment_id'] );
		$this->assertNotEquals( 12345, $meta_id );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_commentmeta_row
	 */
	public function test_insert_commentmeta_row_should_throw_on_insert_failure(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$commentmeta_row = [
			'meta_id'    => 1,
			'comment_id' => 1,
			'meta_key'   => 'test_key',
			'meta_value' => 'test_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Error inserting comment meta' );

		$this->invoke_private_method( $importer, 'insert_commentmeta_row', [ $commentmeta_row, 123 ] );
	}

	// =========================================================================
	// INSERT_TERMMETA_ROW TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::insert_termmeta_row
	 */
	public function test_insert_termmeta_row_should_insert_and_return_meta_id(): void {
		$term         = wp_insert_term( 'Test Term', 'category' );
		$term_id      = $term['term_id'];
		$termmeta_row = [
			'meta_id'    => 999,
			'term_id'    => 888,
			'meta_key'   => 'test_term_meta',
			'meta_value' => 'test_term_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$result = $this->invoke_private_method( $this->importer, 'insert_termmeta_row', [ $termmeta_row, $term_id ] );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertEquals( 'test_term_value', get_term_meta( $term_id, 'test_term_meta', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_termmeta_row
	 */
	public function test_insert_termmeta_row_should_replace_term_id_and_remove_meta_id(): void {
		global $wpdb;
		$term         = wp_insert_term( 'Test Term 2', 'category' );
		$term_id      = $term['term_id'];
		$termmeta_row = [
			'meta_id'    => 12345,
			'term_id'    => 99999,
			'meta_key'   => 'replaced_term_key',
			'meta_value' => 'replaced_term_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$meta_id = $this->invoke_private_method( $this->importer, 'insert_termmeta_row', [ $termmeta_row, $term_id ] );

		$inserted = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->termmeta} WHERE meta_id = %d", $meta_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertEquals( $term_id, (int) $inserted['term_id'] );
		$this->assertNotEquals( 12345, $meta_id );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_termmeta_row
	 */
	public function test_insert_termmeta_row_should_throw_on_insert_failure(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$termmeta_row = [
			'meta_id'    => 1,
			'term_id'    => 1,
			'meta_key'   => 'test_key',
			'meta_value' => 'test_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
		];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Error inserting term meta' );

		$this->invoke_private_method( $importer, 'insert_termmeta_row', [ $termmeta_row, 123 ] );
	}

	// =========================================================================
	// UPDATE_COMMENT_PARENT TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::update_comment_parent
	 */
	public function test_update_comment_parent_should_update_and_return_rows_affected(): void {
		global $wpdb;
		$post_id        = $this->create_test_post();
		$parent_comment = $this->create_test_comment( [ 'comment_post_ID' => $post_id ] );
		$child_comment  = $this->create_test_comment(
			[
				'comment_post_ID' => $post_id,
				'comment_parent'  => 0,
			] 
		);

		$result = $this->invoke_private_method( $this->importer, 'update_comment_parent', [ $child_comment, $parent_comment ] );

		// Verify update was successful (rows affected >= 1 or actual value).
		$this->assertGreaterThanOrEqual( 1, $result );

		// Query directly to bypass cache which get_comment() uses.
		$updated_parent = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT comment_parent FROM {$wpdb->comments} WHERE comment_ID = %d", $child_comment )
		);
		$this->assertEquals( $parent_comment, (int) $updated_parent );
	}

	/**
	 * @test
	 * @covers DataImporter::update_comment_parent
	 */
	public function test_update_comment_parent_should_throw_on_update_failure(): void {
		$this->expectException( \RuntimeException::class );

		// Non-existent comment ID should fail.
		$this->invoke_private_method( $this->importer, 'update_comment_parent', [ 999999999, 1 ] );
	}

	// =========================================================================
	// UPDATE_POST_AUTHOR TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::update_post_author
	 */
	public function test_update_post_author_should_update_and_return_rows_affected(): void {
		$post_id    = $this->create_test_post();
		$new_author = $this->create_test_user();

		$result = $this->invoke_private_method( $this->importer, 'update_post_author', [ $post_id, $new_author ] );

		// Verify update was successful (rows affected >= 1).
		$this->assertGreaterThanOrEqual( 1, $result );

		// Clear cache and verify author was updated.
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		$this->assertEquals( $new_author, (int) $post->post_author );
	}

	/**
	 * @test
	 * @covers DataImporter::update_post_author
	 */
	public function test_update_post_author_should_throw_on_update_failure(): void {
		$this->expectException( \RuntimeException::class );

		// Non-existent post ID should fail.
		$this->invoke_private_method( $this->importer, 'update_post_author', [ 999999999, 1 ] );
	}

	// =========================================================================
	// INSERT_TERM_RELATIONSHIP TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::insert_term_relationship
	 */
	public function test_insert_term_relationship_should_insert_and_return_id(): void {
		global $wpdb;
		$post_id = $this->create_test_post();
		$term    = wp_insert_term( 'Relationship Term', 'category' );

		$result = $this->invoke_private_method( $this->importer, 'insert_term_relationship', [ $post_id, $term['term_taxonomy_id'] ] );

		$this->assertIsInt( $result );

		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d",
				$post_id,
				$term['term_taxonomy_id']
			)
		);
		$this->assertEquals( 1, (int) $exists );
	}

	/**
	 * @test
	 * @covers DataImporter::insert_term_relationship
	 */
	public function test_insert_term_relationship_should_throw_on_insert_failure(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Error inserting term relationship' );

		$this->invoke_private_method( $importer, 'insert_term_relationship', [ 123, 456 ] );
	}

	// =========================================================================
	// GET_TERM_AND_TAXONOMY_ARRAY TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::get_term_and_taxonomy_array
	 */
	public function test_get_term_and_taxonomy_array_should_return_data_by_term_id(): void {
		global $wpdb;
		$term = wp_insert_term( 'Term By ID', 'category' );

		$result = $this->invoke_private_method(
			$this->importer,
			'get_term_and_taxonomy_array',
			[ $wpdb->prefix, [ 'term_id' => $term['term_id'] ], 'category' ]
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $term['term_id'], $result['term_id'] );
		$this->assertEquals( 'Term By ID', $result['name'] );
	}

	/**
	 * @test
	 * @covers DataImporter::get_term_and_taxonomy_array
	 */
	public function test_get_term_and_taxonomy_array_should_return_data_by_term_name(): void {
		global $wpdb;
		$term = wp_insert_term( 'Term By Name', 'category' );

		$result = $this->invoke_private_method(
			$this->importer,
			'get_term_and_taxonomy_array',
			[ $wpdb->prefix, [ 'term_name' => 'Term By Name' ], 'category' ]
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $term['term_id'], $result['term_id'] );
	}

	/**
	 * @test
	 * @covers DataImporter::get_term_and_taxonomy_array
	 */
	public function test_get_term_and_taxonomy_array_should_return_null_when_not_found(): void {
		global $wpdb;

		$result = $this->invoke_private_method(
			$this->importer,
			'get_term_and_taxonomy_array',
			[ $wpdb->prefix, [ 'term_id' => 999999999 ], 'category' ]
		);

		$this->assertNull( $result );
	}

	/**
	 * @test
	 * @covers DataImporter::get_term_and_taxonomy_array
	 */
	public function test_get_term_and_taxonomy_array_should_return_null_when_where_clause_empty(): void {
		global $wpdb;

		$result = $this->invoke_private_method(
			$this->importer,
			'get_term_and_taxonomy_array',
			[ $wpdb->prefix, [], 'category' ]
		);

		$this->assertNull( $result );
	}

	// =========================================================================
	// GET_COMMENT_USER_ID TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::get_comment_user_id
	 */
	public function test_get_comment_user_id_should_return_zero_when_user_id_is_zero(): void {
		$comment_row = [
			'comment_ID' => 1,
			'user_id'    => 0,
		];
		$data        = $this->build_post_data( 100 );

		$result = $this->invoke_private_method(
			$this->importer,
			'get_comment_user_id',
			[ $comment_row, $data, 1, 100, 'example.com' ]
		);

		$this->assertEquals( 0, $result );
	}

	/**
	 * @test
	 * @covers DataImporter::get_comment_user_id
	 */
	public function test_get_comment_user_id_should_return_existing_user_id(): void {
		$existing_user = $this->create_test_user( [ 'user_login' => 'comment_user_existing' ] );
		$comment_row   = [
			'comment_ID' => 1,
			'user_id'    => 999,
		];
		$data          = $this->build_post_data(
			100,
			[],
			[],
			[],
			[
				[
					'ID'         => 999,
					'user_login' => 'comment_user_existing',
					'user_email' => 'test@test.com',
				],
			]
		);

		$result = $this->invoke_private_method(
			$this->importer,
			'get_comment_user_id',
			[ $comment_row, $data, 1, 100, 'example.com' ]
		);

		$this->assertEquals( $existing_user, $result );
	}

	/**
	 * @test
	 * @covers DataImporter::get_comment_user_id
	 */
	public function test_get_comment_user_id_should_create_user_when_not_exists(): void {
		$unique_login = 'new_comment_user_' . uniqid();
		$comment_row  = [
			'comment_ID' => 1,
			'user_id'    => 999,
		];
		$data         = $this->build_post_data(
			100,
			[],
			[],
			[],
			[
				[
					'ID'            => 999,
					'user_login'    => $unique_login,
					'user_email'    => $unique_login . '@test.com',
					'user_pass'     => 'pass',
					'user_nicename' => 'test',
					'display_name'  => 'Test',
				],
			]
		);

		$result = $this->invoke_private_method(
			$this->importer,
			'get_comment_user_id',
			[ $comment_row, $data, 1, 100, 'example.com' ]
		);

		$this->assertGreaterThan( 0, $result );
		$user = get_user_by( 'id', $result );
		$this->assertEquals( $unique_login, $user->user_login );
	}

	/**
	 * @test
	 * @covers DataImporter::get_comment_user_id
	 */
	public function test_get_comment_user_id_should_return_zero_when_user_not_in_data(): void {
		$comment_row = [
			'comment_ID' => 1,
			'user_id'    => 999,
		];
		$data        = $this->build_post_data( 100 ); // No users in data.

		$result = $this->invoke_private_method(
			$this->importer,
			'get_comment_user_id',
			[ $comment_row, $data, 1, 100, 'example.com' ]
		);

		$this->assertEquals( 0, $result );
	}

	// =========================================================================
	// IMPORT_SINGLE_COMMENT TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::import_single_comment
	 */
	public function test_import_single_comment_should_insert_comment_and_return_id(): void {
		$post_id     = $this->create_test_post();
		$comment_row = [
			'comment_ID'       => 999,
			'comment_post_ID'  => 888,
			'user_id'          => 0,
			'comment_content'  => 'Single comment test',
			'comment_author'   => 'Author',
			'comment_date'     => '2024-01-01 12:00:00',
			'comment_date_gmt' => '2024-01-01 12:00:00',
			'comment_parent'   => 0,
		];
		$data        = $this->build_post_data( 100 );

		$result = $this->invoke_private_method(
			$this->importer,
			'import_single_comment',
			[ $comment_row, $data, $post_id, 100, 'example.com' ]
		);

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );

		$comment = get_comment( $result );
		$this->assertEquals( 'Single comment test', $comment->comment_content );
	}

	/**
	 * @test
	 * @covers DataImporter::import_single_comment
	 */
	public function test_import_single_comment_should_insert_all_commentmeta(): void {
		$post_id     = $this->create_test_post();
		$comment_row = [
			'comment_ID'       => 999,
			'comment_post_ID'  => 888,
			'user_id'          => 0,
			'comment_content'  => 'Comment with meta',
			'comment_author'   => 'Author',
			'comment_date'     => '2024-01-01 12:00:00',
			'comment_date_gmt' => '2024-01-01 12:00:00',
			'comment_parent'   => 0,
		];
		$data        = $this->build_post_data(
			100,
			[],
			[],
			[
				[
					'meta_id'    => 1,
					'comment_id' => 999,
					'meta_key'   => 'meta1',
					'meta_value' => 'value1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
				[
					'meta_id'    => 2,
					'comment_id' => 999,
					'meta_key'   => 'meta2',
					'meta_value' => 'value2', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
			]
		);

		$result = $this->invoke_private_method(
			$this->importer,
			'import_single_comment',
			[ $comment_row, $data, $post_id, 100, 'example.com' ]
		);

		$this->assertEquals( 'value1', get_comment_meta( $result, 'meta1', true ) );
		$this->assertEquals( 'value2', get_comment_meta( $result, 'meta2', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::import_single_comment
	 */
	public function test_import_single_comment_should_get_or_create_user(): void {
		$post_id       = $this->create_test_post();
		$existing_user = $this->create_test_user( [ 'user_login' => 'single_comment_user' ] );
		$comment_row   = [
			'comment_ID'       => 999,
			'comment_post_ID'  => 888,
			'user_id'          => 555,
			'comment_content'  => 'Comment with user',
			'comment_author'   => 'Author',
			'comment_date'     => '2024-01-01 12:00:00',
			'comment_date_gmt' => '2024-01-01 12:00:00',
			'comment_parent'   => 0,
		];
		$data          = $this->build_post_data(
			100,
			[],
			[],
			[],
			[
				[
					'ID'         => 555,
					'user_login' => 'single_comment_user',
					'user_email' => 'test@test.com',
				],
			]
		);

		$result = $this->invoke_private_method(
			$this->importer,
			'import_single_comment',
			[ $comment_row, $data, $post_id, 100, 'example.com' ]
		);

		$comment = get_comment( $result );
		$this->assertEquals( $existing_user, (int) $comment->user_id );
	}

	/**
	 * @test
	 * @covers DataImporter::import_single_comment
	 */
	public function test_import_single_comment_should_return_null_on_error(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$comment_row = [
			'comment_ID'       => 999,
			'comment_post_ID'  => 888,
			'user_id'          => 0,
			'comment_content'  => 'Test comment',
			'comment_author'   => 'Author',
			'comment_date'     => '2024-01-01 12:00:00',
			'comment_date_gmt' => '2024-01-01 12:00:00',
			'comment_parent'   => 0,
		];
		$data        = $this->build_post_data( 100 );

		$result = $this->invoke_private_method(
			$importer,
			'import_single_comment',
			[ $comment_row, $data, 1, 100, 'example.com' ]
		);

		$this->assertNull( $result );
	}

	// =========================================================================
	// UPDATE_IMPORTED_COMMENT_PARENTS TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::update_imported_comment_parents
	 */
	public function test_update_imported_comment_parents_should_update_parent_ids(): void {
		$post_id = $this->create_test_post();
		$parent  = $this->create_test_comment( [ 'comment_post_ID' => $post_id ] );
		$child   = $this->create_test_comment(
			[
				'comment_post_ID' => $post_id,
				'comment_parent'  => 0,
			] 
		);

		$comment_ids_map = [
			100 => $parent, // old parent => new parent.
			200 => $child,  // old child => new child.
		];
		$comments_data   = [
			[
				'comment_ID'     => 100,
				'comment_parent' => 0,
			],
			[
				'comment_ID'     => 200,
				'comment_parent' => 100,
			], // Child points to old parent.
		];

		$this->invoke_private_method(
			$this->importer,
			'update_imported_comment_parents',
			[ $comment_ids_map, $comments_data, 1, $post_id ]
		);

		// Clear cache and verify.
		clean_comment_cache( $child );
		$updated_child = get_comment( $child );
		$this->assertEquals( $parent, (int) $updated_child->comment_parent );
	}

	/**
	 * @test
	 * @covers DataImporter::update_imported_comment_parents
	 */
	public function test_update_imported_comment_parents_should_skip_when_parent_is_zero(): void {
		$post_id = $this->create_test_post();
		$comment = $this->create_test_comment(
			[
				'comment_post_ID' => $post_id,
				'comment_parent'  => 0,
			] 
		);

		$comment_ids_map = [ 100 => $comment ];
		$comments_data   = [
			[
				'comment_ID'     => 100,
				'comment_parent' => 0,
			],
		];

		$this->invoke_private_method(
			$this->importer,
			'update_imported_comment_parents',
			[ $comment_ids_map, $comments_data, 1, $post_id ]
		);

		// Should not throw, and parent should remain 0.
		$updated = get_comment( $comment );
		$this->assertEquals( 0, (int) $updated->comment_parent );
	}

	/**
	 * @test
	 * @covers DataImporter::update_imported_comment_parents
	 */
	public function test_update_imported_comment_parents_should_skip_when_parent_not_in_map(): void {
		$post_id = $this->create_test_post();
		$comment = $this->create_test_comment(
			[
				'comment_post_ID' => $post_id,
				'comment_parent'  => 0,
			] 
		);

		$comment_ids_map = [ 100 => $comment ];
		$comments_data   = [
			[
				'comment_ID'     => 100,
				'comment_parent' => 999,
			],
		]; // Parent 999 not in map.

		$this->invoke_private_method(
			$this->importer,
			'update_imported_comment_parents',
			[ $comment_ids_map, $comments_data, 1, $post_id ]
		);

		// Should not throw, and parent should remain 0.
		$updated = get_comment( $comment );
		$this->assertEquals( 0, (int) $updated->comment_parent );
	}

	/**
	 * @test
	 * @covers DataImporter::update_imported_comment_parents
	 */
	public function test_update_imported_comment_parents_should_continue_on_error(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'update' );
		$importer  = new DataImporter( $mock_wpdb );

		// Map with two comments that need parent updates.
		$comment_ids_map = [
			100 => 1, // old_id => new_id
			200 => 2,
			300 => 3,
		];
		$comments_data   = [
			[
				'comment_ID'     => 100,
				'comment_parent' => 0,
			],
			[
				'comment_ID'     => 200,
				'comment_parent' => 100,
			], // Parent needs update.
			[
				'comment_ID'     => 300,
				'comment_parent' => 200,
			], // Parent needs update.
		];

		// Should NOT throw - errors are logged and processing continues.
		$this->invoke_private_method(
			$importer,
			'update_imported_comment_parents',
			[ $comment_ids_map, $comments_data, 1, 1 ]
		);

		// If we get here without exception, the test passes.
		$this->assertTrue( true );
	}

	// =========================================================================
	// ENSURE_TAXONOMY_REGISTERED TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::ensure_taxonomy_registered
	 */
	public function test_ensure_taxonomy_registered_should_skip_if_already_exists(): void {
		// 'category' is always registered.
		$live_term_taxonomy_row = [ 'parent' => 0 ];

		$this->invoke_private_method(
			$this->importer,
			'ensure_taxonomy_registered',
			[ 'category', $live_term_taxonomy_row ]
		);

		$this->assertTrue( taxonomy_exists( 'category' ) );
	}

	/**
	 * @test
	 * @covers DataImporter::ensure_taxonomy_registered
	 */
	public function test_ensure_taxonomy_registered_should_register_new_taxonomy(): void {
		// Use a short taxonomy name (max 32 chars for WP).
		$new_taxonomy           = 'test_tax_' . substr( uniqid(), 0, 8 );
		$live_term_taxonomy_row = [ 'parent' => 0 ];

		$this->invoke_private_method(
			$this->importer,
			'ensure_taxonomy_registered',
			[ $new_taxonomy, $live_term_taxonomy_row ]
		);

		$this->assertTrue( taxonomy_exists( $new_taxonomy ) );
	}

	/**
	 * @test
	 * @covers DataImporter::ensure_taxonomy_registered
	 */
	public function test_ensure_taxonomy_registered_should_detect_hierarchical_from_parent(): void {
		// Use a short taxonomy name (max 32 chars for WP).
		$hierarchical_taxonomy  = 'hier_tax_' . substr( uniqid(), 0, 8 );
		$live_term_taxonomy_row = [ 'parent' => 5 ]; // Non-zero parent indicates hierarchical.

		$this->invoke_private_method(
			$this->importer,
			'ensure_taxonomy_registered',
			[ $hierarchical_taxonomy, $live_term_taxonomy_row ]
		);

		$taxonomy_obj = get_taxonomy( $hierarchical_taxonomy );
		$this->assertTrue( $taxonomy_obj->hierarchical );
	}

	// =========================================================================
	// IMPORT_SINGLE_TERM_RELATIONSHIP TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::import_single_term_relationship
	 */
	public function test_import_single_term_relationship_should_return_unchanged_array_when_taxonomy_not_allowed(): void {
		$term_relationship_row = [ 'term_taxonomy_id' => 1 ];
		$data                  = $this->build_post_data(
			100,
			[],
			[],
			[],
			[],
			[],
			[],
			[
				[
					'term_taxonomy_id' => 1,
					'term_id'          => 1,
					'taxonomy'         => 'not_allowed_taxonomy',
				],
			],
			[
				[
					'term_id' => 1,
					'name'    => 'Test',
					'slug'    => 'test',
				],
			]
		);
		$inserted              = [ 5, 6, 7 ];

		$result = $this->invoke_private_method(
			$this->importer,
			'import_single_term_relationship',
			[ $term_relationship_row, $data, 1, 100, 'cdiff_', [ 'category', 'post_tag' ], $inserted, 'example.com' ]
		);

		$this->assertEquals( [ 5, 6, 7 ], $result ); // Unchanged.
	}

	/**
	 * @test
	 * @covers DataImporter::import_single_term_relationship
	 */
	public function test_import_single_term_relationship_should_return_unchanged_array_when_term_row_invalid(): void {
		$term_relationship_row = [ 'term_taxonomy_id' => 1 ];
		$data                  = $this->build_post_data(
			100,
			[],
			[],
			[],
			[],
			[],
			[],
			[
				[
					'term_taxonomy_id' => 1,
					'term_id'          => 999,
					'taxonomy'         => 'category',
				],
			],
			[] // No terms - term_id 999 won't be found.
		);
		$inserted              = [];

		$result = $this->invoke_private_method(
			$this->importer,
			'import_single_term_relationship',
			[ $term_relationship_row, $data, 1, 100, 'cdiff_', [ 'category' ], $inserted, 'example.com' ]
		);

		$this->assertEquals( [], $result ); // Unchanged.
	}

	/**
	 * @test
	 * @covers DataImporter::import_single_term_relationship
	 */
	public function test_import_single_term_relationship_should_skip_duplicate_term_taxonomy_ids(): void {
		global $wpdb;

		// Create a real term.
		$term = wp_insert_term( 'Duplicate Test Term', 'category' );

		$term_relationship_row = [ 'term_taxonomy_id' => 1 ];
		$data                  = $this->build_post_data(
			100,
			[],
			[],
			[],
			[],
			[],
			[],
			[
				[
					'term_taxonomy_id' => 1,
					'term_id'          => 1,
					'taxonomy'         => 'category',
					'parent'           => 0,
					'description'      => '',
				],
			],
			[
				[
					'term_id' => 1,
					'name'    => 'Duplicate Test Term',
					'slug'    => 'duplicate-test-term',
				],
			]
		);

		// Pre-populate the map to simulate already processed.
		$this->reset_private_property( $this->importer, 'taxonomy_term_id_map', [ 1 => $term['term_id'] ] );

		$post_id  = $this->create_test_post();
		$inserted = [ $term['term_taxonomy_id'] ]; // Already inserted.

		$result = $this->invoke_private_method(
			$this->importer,
			'import_single_term_relationship',
			[ $term_relationship_row, $data, $post_id, 100, 'cdiff_', [ 'category' ], $inserted, 'example.com' ]
		);

		// Should be unchanged because it's a duplicate.
		$this->assertEquals( $inserted, $result );
	}

	/**
	 * @test
	 * @covers DataImporter::import_single_term_relationship
	 *
	 * This test verifies the method works when a term is already mapped.
	 * Full term creation is tested via integration in import_taxonomies tests.
	 */
	public function test_import_single_term_relationship_should_insert_relationship_for_mapped_term(): void {
		global $wpdb;
		$post_id = $this->create_test_post();

		// Create a real term that we'll pre-map.
		$term_name   = 'MappedTerm' . substr( uniqid(), 0, 6 );
		$local_term  = wp_insert_term( $term_name, 'category' );
		$local_t_id  = $local_term['term_id'];
		$local_tt_id = $local_term['term_taxonomy_id'];

		// Pre-populate the map so we bypass term creation.
		$live_term_id = 9999;
		$this->reset_private_property( $this->importer, 'taxonomy_term_id_map', [ $live_term_id => $local_t_id ] );

		$term_relationship_row = [ 'term_taxonomy_id' => 1 ];
		$data                  = $this->build_post_data(
			100,
			[],   // postmeta
			[],   // comments
			[],   // commentmeta
			[],   // users
			[],   // usermeta
			[],   // relationships
			[     // term_taxonomy
				[
					'term_taxonomy_id' => 1,
					'term_id'          => $live_term_id,
					'taxonomy'         => 'category',
					'parent'           => 0,
					'description'      => 'Mapped term description',
				],
			],
			[     // terms
				[
					'term_id' => $live_term_id,
					'name'    => $term_name,
					'slug'    => sanitize_title( $term_name ),
				],
			],
			[]    // termmeta
		);
		$inserted              = [];

		$result = $this->invoke_private_method(
			$this->importer,
			'import_single_term_relationship',
			[ $term_relationship_row, $data, $post_id, 100, 'cdiff_', [ 'category' ], $inserted, 'example.com' ]
		);

		// Should have one term_taxonomy_id added.
		$this->assertCount( 1, $result );
		$this->assertEquals( $local_tt_id, $result[0] );

		// Verify the relationship was actually inserted.
		$has_term = has_term( $local_t_id, 'category', $post_id );
		$this->assertTrue( $has_term );
	}

	/**
	 * @test
	 * @covers DataImporter::import_single_term_relationship
	 */
	public function test_import_single_term_relationship_should_reuse_cached_term_from_map(): void {
		global $wpdb;

		$term    = wp_insert_term( 'Cached Term', 'category' );
		$post_id = $this->create_test_post();

		// Pre-populate the map.
		$this->reset_private_property( $this->importer, 'taxonomy_term_id_map', [ 555 => $term['term_id'] ] );

		$term_relationship_row = [ 'term_taxonomy_id' => 1 ];
		$data                  = $this->build_post_data(
			100,
			[],
			[],
			[],
			[],
			[],
			[],
			[
				[
					'term_taxonomy_id' => 1,
					'term_id'          => 555,
					'taxonomy'         => 'category',
					'parent'           => 0,
					'description'      => '',
				],
			],
			[
				[
					'term_id' => 555,
					'name'    => 'Cached Term',
					'slug'    => 'cached-term',
				],
			]
		);
		$inserted              = [];

		$result = $this->invoke_private_method(
			$this->importer,
			'import_single_term_relationship',
			[ $term_relationship_row, $data, $post_id, 100, 'cdiff_', [ 'category' ], $inserted, 'example.com' ]
		);

		$this->assertCount( 1, $result );
	}

	/**
	 * @test
	 * @covers DataImporter::import_single_term_relationship
	 */
	public function test_import_single_term_relationship_should_increment_count_and_update_description(): void {
		global $wpdb;

		$term    = wp_insert_term( 'Count Test Term', 'category' );
		$post_id = $this->create_test_post();

		// Get initial count.
		$initial_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
				$term['term_taxonomy_id']
			)
		);

		// Pre-populate the map.
		$this->reset_private_property( $this->importer, 'taxonomy_term_id_map', [ 555 => $term['term_id'] ] );

		$term_relationship_row = [ 'term_taxonomy_id' => 1 ];
		$data                  = $this->build_post_data(
			100,
			[],
			[],
			[],
			[],
			[],
			[],
			[
				[
					'term_taxonomy_id' => 1,
					'term_id'          => 555,
					'taxonomy'         => 'category',
					'parent'           => 0,
					'description'      => 'Updated description',
				],
			],
			[
				[
					'term_id' => 555,
					'name'    => 'Count Test Term',
					'slug'    => 'count-test-term',
				],
			]
		);
		$inserted              = [];

		$this->invoke_private_method(
			$this->importer,
			'import_single_term_relationship',
			[ $term_relationship_row, $data, $post_id, 100, 'cdiff_', [ 'category' ], $inserted, 'example.com' ]
		);

		// Verify count incremented.
		$new_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
				$term['term_taxonomy_id']
			)
		);
		$this->assertEquals( $initial_count + 1, $new_count );

		// Verify description updated.
		$description = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT description FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
				$term['term_taxonomy_id']
			)
		);
		$this->assertEquals( 'Updated description', $description );
	}

	// =========================================================================
	// IMPORT_TERMMETA TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::import_termmeta
	 */
	public function test_import_termmeta_should_insert_termmeta_rows(): void {
		$term = wp_insert_term( 'Termmeta Test', 'category' );
		$data = $this->build_post_data(
			100,
			[],
			[],
			[],
			[],
			[],
			[],
			[],
			[],
			[
				[
					'meta_id'    => 1,
					'term_id'    => 555,
					'meta_key'   => 'term_meta_1',
					'meta_value' => 'value_1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
				[
					'meta_id'    => 2,
					'term_id'    => 555,
					'meta_key'   => 'term_meta_2',
					'meta_value' => 'value_2', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
			]
		);

		$this->invoke_private_method(
			$this->importer,
			'import_termmeta',
			[ $data, 555, $term['term_id'], 100, 1, 'example.com' ]
		);

		$this->assertEquals( 'value_1', get_term_meta( $term['term_id'], 'term_meta_1', true ) );
		$this->assertEquals( 'value_2', get_term_meta( $term['term_id'], 'term_meta_2', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::import_termmeta
	 */
	public function test_import_termmeta_should_skip_if_already_imported(): void {
		global $wpdb;
		$term = wp_insert_term( 'Skip Termmeta Test', 'category' );

		// Mark as already imported.
		$this->reset_private_property( $this->importer, 'termmeta_imported', [ 555 => true ] );

		$data = $this->build_post_data(
			100,
			[],
			[],
			[],
			[],
			[],
			[],
			[],
			[],
			[
				[
					'meta_id'    => 1,
					'term_id'    => 555,
					'meta_key'   => 'should_not_exist',
					'meta_value' => 'value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
			]
		);

		$this->invoke_private_method(
			$this->importer,
			'import_termmeta',
			[ $data, 555, $term['term_id'], 100, 1, 'example.com' ]
		);

		// Meta should NOT be inserted because we skipped.
		$this->assertEquals( '', get_term_meta( $term['term_id'], 'should_not_exist', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::import_termmeta
	 */
	public function test_import_termmeta_should_mark_term_as_imported(): void {
		$term = wp_insert_term( 'Mark Imported Test', 'category' );
		$data = $this->build_post_data( 100 );

		$this->invoke_private_method(
			$this->importer,
			'import_termmeta',
			[ $data, 777, $term['term_id'], 100, 1, 'example.com' ]
		);

		$imported_map = $this->get_private_property( $this->importer, 'termmeta_imported' );
		$this->assertTrue( isset( $imported_map[777] ) );
		$this->assertTrue( $imported_map[777] );
	}

	/**
	 * @test
	 * @covers DataImporter::import_termmeta
	 */
	public function test_import_termmeta_should_continue_on_insert_error(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$data = $this->build_post_data(
			100,
			[],
			[],
			[],
			[],
			[],
			[],
			[],
			[],
			[
				[
					'meta_id'    => 1,
					'term_id'    => 555,
					'meta_key'   => 'key1',
					'meta_value' => 'value1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
				[
					'meta_id'    => 2,
					'term_id'    => 555,
					'meta_key'   => 'key2',
					'meta_value' => 'value2', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
			]
		);

		// Should NOT throw - errors are logged and processing continues.
		$this->invoke_private_method(
			$importer,
			'import_termmeta',
			[ $data, 555, 123, 100, 1, 'example.com' ]
		);

		// Verify term was marked as imported despite errors.
		$termmeta_imported = $this->get_private_property( $importer, 'termmeta_imported' );
		$this->assertTrue( isset( $termmeta_imported[555] ) );
	}

	// =========================================================================
	// IMPORT_POST_META TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::import_post_meta
	 */
	public function test_import_post_meta_should_insert_all_postmeta_rows(): void {
		$post_id = $this->create_test_post();
		$data    = $this->build_post_data(
			100,
			[
				[
					'meta_id'    => 1,
					'post_id'    => 100,
					'meta_key'   => 'key1',
					'meta_value' => 'value1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
				[
					'meta_id'    => 2,
					'post_id'    => 100,
					'meta_key'   => 'key2',
					'meta_value' => 'value2', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
			]
		);

		$this->invoke_private_method( $this->importer, 'import_post_meta', [ $data, $post_id ] );

		$this->assertEquals( 'value1', get_post_meta( $post_id, 'key1', true ) );
		$this->assertEquals( 'value2', get_post_meta( $post_id, 'key2', true ) );
	}

	/**
	 * @test
	 * @covers DataImporter::import_post_meta
	 */
	public function test_import_post_meta_should_continue_on_individual_insert_error(): void {
		$mock_wpdb = $this->create_failing_wpdb_mock( 'insert' );
		$importer  = new DataImporter( $mock_wpdb );

		$data = $this->build_post_data(
			100,
			[
				[
					'meta_id'    => 1,
					'post_id'    => 100,
					'meta_key'   => 'key1',
					'meta_value' => 'value1', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
				[
					'meta_id'    => 2,
					'post_id'    => 100,
					'meta_key'   => 'key2',
					'meta_value' => 'value2', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
				[
					'meta_id'    => 3,
					'post_id'    => 100,
					'meta_key'   => 'key3',
					'meta_value' => 'value3', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
			]
		);

		// Should NOT throw - errors are logged and processing continues for each meta row.
		$this->invoke_private_method( $importer, 'import_post_meta', [ $data, 123 ] );

		// If we get here without exception, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * @test
	 * @covers DataImporter::import_post_meta
	 */
	public function test_import_post_meta_should_handle_empty_postmeta_array(): void {
		$post_id = $this->create_test_post();
		$data    = $this->build_post_data( 100 ); // Empty postmeta.

		$this->invoke_private_method( $this->importer, 'import_post_meta', [ $data, $post_id ] );

		// Should not throw.
		$this->assertTrue( true );
	}

	// =========================================================================
	// IMPORT_AUTHOR TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::import_author
	 */
	public function test_import_author_should_get_or_create_author_user(): void {
		$unique_login  = 'author_user_' . substr( uniqid(), 0, 8 );
		$post_id       = $this->create_test_post();
		$existing_user = $this->create_test_user( [ 'user_login' => $unique_login ] );
		$data          = $this->build_post_data( 100 );
		$data[ ContentDiffLogic::DATAKEY_POST ]['post_author'] = 999;
		$data[ ContentDiffLogic::DATAKEY_USERS ]               = [
			[
				'ID'         => 999,
				'user_login' => $unique_login,
				'user_email' => $unique_login . '@test.com',
			],
		];

		$this->invoke_private_method( $this->importer, 'import_author', [ $data, $post_id, 'example.com' ] );

		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		$this->assertEquals( $existing_user, (int) $post->post_author );
	}

	/**
	 * @test
	 * @covers DataImporter::import_author
	 */
	public function test_import_author_should_update_post_author_when_changed(): void {
		$unique_original = 'orig_author_' . substr( uniqid(), 0, 8 );
		$unique_new      = 'new_author_' . substr( uniqid(), 0, 8 );
		$original_author = $this->create_test_user( [ 'user_login' => $unique_original ] );
		$new_author      = $this->create_test_user( [ 'user_login' => $unique_new ] );
		$post_id         = $this->create_test_post( [ 'post_author' => $original_author ] );

		$data = $this->build_post_data( 100 );
		$data[ ContentDiffLogic::DATAKEY_POST ]['post_author'] = 999;
		$data[ ContentDiffLogic::DATAKEY_USERS ]               = [
			[
				'ID'         => 999,
				'user_login' => $unique_new,
				'user_email' => $unique_new . '@test.com',
			],
		];

		$this->invoke_private_method( $this->importer, 'import_author', [ $data, $post_id, 'example.com' ] );

		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		$this->assertEquals( $new_author, (int) $post->post_author );
	}

	/**
	 * @test
	 * @covers DataImporter::import_author
	 */
	public function test_import_author_should_not_update_when_author_unchanged(): void {
		$author  = $this->create_test_user( [ 'user_login' => 'same_author_user' ] );
		$post_id = $this->create_test_post( [ 'post_author' => $author ] );

		$data = $this->build_post_data( 100 );
		$data[ ContentDiffLogic::DATAKEY_POST ]['post_author'] = $author;
		$data[ ContentDiffLogic::DATAKEY_USERS ]               = [
			[
				'ID'         => $author,
				'user_login' => 'same_author_user',
				'user_email' => 'same@test.com',
			],
		];

		$this->invoke_private_method( $this->importer, 'import_author', [ $data, $post_id, 'example.com' ] );

		// Should not throw, author unchanged.
		$post = get_post( $post_id );
		$this->assertEquals( $author, (int) $post->post_author );
	}

	/**
	 * @test
	 * @covers DataImporter::import_author
	 */
	public function test_import_author_should_handle_null_author(): void {
		$post_id = $this->create_test_post();
		$data    = $this->build_post_data( 100 );
		$data[ ContentDiffLogic::DATAKEY_POST ]['post_author'] = null;

		$this->invoke_private_method( $this->importer, 'import_author', [ $data, $post_id, 'example.com' ] );

		// Should not throw.
		$this->assertTrue( true );
	}

	// =========================================================================
	// IMPORT_COMMENTS TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::import_comments
	 */
	public function test_import_comments_should_process_all_comments(): void {
		$post_id = $this->create_test_post();
		$data    = $this->build_post_data(
			100,
			[],
			[
				[
					'comment_ID'       => 1,
					'comment_post_ID'  => 100,
					'user_id'          => 0,
					'comment_content'  => 'Comment 1',
					'comment_author'   => 'Author 1',
					'comment_date'     => '2024-01-01 12:00:00',
					'comment_date_gmt' => '2024-01-01 12:00:00',
					'comment_parent'   => 0,
				],
				[
					'comment_ID'       => 2,
					'comment_post_ID'  => 100,
					'user_id'          => 0,
					'comment_content'  => 'Comment 2',
					'comment_author'   => 'Author 2',
					'comment_date'     => '2024-01-01 13:00:00',
					'comment_date_gmt' => '2024-01-01 13:00:00',
					'comment_parent'   => 0,
				],
			]
		);

		$this->invoke_private_method( $this->importer, 'import_comments', [ $data, $post_id, 'example.com' ] );

		$comments = get_comments( [ 'post_id' => $post_id ] );
		$this->assertCount( 2, $comments );
	}

	/**
	 * @test
	 * @covers DataImporter::import_comments
	 */
	public function test_import_comments_should_call_update_parents_with_id_map(): void {
		$post_id = $this->create_test_post();
		$data    = $this->build_post_data(
			100,
			[],
			[
				[
					'comment_ID'       => 1,
					'comment_post_ID'  => 100,
					'user_id'          => 0,
					'comment_content'  => 'Parent Comment',
					'comment_author'   => 'Author',
					'comment_date'     => '2024-01-01 12:00:00',
					'comment_date_gmt' => '2024-01-01 12:00:00',
					'comment_parent'   => 0,
				],
				[
					'comment_ID'       => 2,
					'comment_post_ID'  => 100,
					'user_id'          => 0,
					'comment_content'  => 'Child Comment',
					'comment_author'   => 'Author',
					'comment_date'     => '2024-01-01 13:00:00',
					'comment_date_gmt' => '2024-01-01 13:00:00',
					'comment_parent'   => 1, // Points to comment 1.
				],
			]
		);

		$this->invoke_private_method( $this->importer, 'import_comments', [ $data, $post_id, 'example.com' ] );

		$comments = get_comments(
			[
				'post_id' => $post_id,
				'orderby' => 'comment_date',
				'order'   => 'ASC',
			] 
		);
		$this->assertCount( 2, $comments );

		// Child should have parent set.
		$child = null;
		foreach ( $comments as $comment ) {
			if ( 'Child Comment' === $comment->comment_content ) {
				$child = $comment;
				break;
			}
		}
		$this->assertNotNull( $child );
		$this->assertGreaterThan( 0, (int) $child->comment_parent );
	}

	/**
	 * @test
	 * @covers DataImporter::import_comments
	 */
	public function test_import_comments_should_handle_empty_comments_array(): void {
		$post_id = $this->create_test_post();
		$data    = $this->build_post_data( 100 ); // Empty comments.

		$this->invoke_private_method( $this->importer, 'import_comments', [ $data, $post_id, 'example.com' ] );

		// Should not throw.
		$this->assertTrue( true );
	}

	// =========================================================================
	// IMPORT_POST_DATA TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::import_post_data
	 */
	public function test_import_post_data_should_call_all_import_methods(): void {
		$post_id = $this->create_test_post();
		$data    = $this->build_post_data( 100 );

		// Should not throw.
		$this->importer->import_post_data( $post_id, $data, 'wp_', [ 'category' ], 'example.com' );

		$this->assertTrue( true );
	}

	/**
	 * @test
	 * @covers DataImporter::import_post_data
	 */
	public function test_import_post_data_full_integration_with_real_data(): void {
		global $wpdb;
		$post_id = $this->create_test_post();

		$data = $this->build_post_data(
			100,
			[
				[
					'meta_id'    => 1,
					'post_id'    => 100,
					'meta_key'   => 'integration_meta',
					'meta_value' => 'integration_value', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
				],
			],
			[
				[
					'comment_ID'       => 1,
					'comment_post_ID'  => 100,
					'user_id'          => 0,
					'comment_content'  => 'Integration comment',
					'comment_author'   => 'Author',
					'comment_date'     => '2024-01-01 12:00:00',
					'comment_date_gmt' => '2024-01-01 12:00:00',
					'comment_parent'   => 0,
				],
			]
		);

		$this->importer->import_post_data( $post_id, $data, $wpdb->prefix, [ 'category' ], 'example.com' );

		$this->assertEquals( 'integration_value', get_post_meta( $post_id, 'integration_meta', true ) );
		$comments = get_comments( [ 'post_id' => $post_id ] );
		$this->assertCount( 1, $comments );
	}

	/**
	 * @test
	 * @covers DataImporter::import_post_data
	 */
	public function test_import_post_data_should_handle_minimal_data(): void {
		$post_id = $this->create_test_post();
		$data    = $this->build_post_data( 100 ); // Minimal - empty arrays.

		$this->importer->import_post_data( $post_id, $data, 'wp_', [], 'example.com' );

		$this->assertTrue( true );
	}

	// =========================================================================
	// FIX_HIERARCHICAL_TAXONOMIES_PARENTS TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers DataImporter::fix_hierarchical_taxonomies_parents
	 */
	public function test_fix_hierarchical_taxonomies_parents_should_return_empty_when_all_valid(): void {
		global $wpdb;

		// Create valid parent-child relationship.
		$parent = wp_insert_term( 'Valid Parent', 'category' );
		$child  = wp_insert_term( 'Valid Child', 'category', [ 'parent' => $parent['term_id'] ] );

		$result = $this->importer->fix_hierarchical_taxonomies_parents( $wpdb->prefix, [ 'category' ] );

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers DataImporter::fix_hierarchical_taxonomies_parents
	 */
	public function test_fix_hierarchical_taxonomies_parents_should_reset_invalid_parents_to_zero(): void {
		global $wpdb;

		// Create a term with an invalid parent (parent doesn't exist).
		$term = wp_insert_term( 'Orphan Term ' . uniqid(), 'category' );

		// Manually set an invalid parent.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->term_taxonomy,
			[ 'parent' => 999999 ],
			[ 'term_taxonomy_id' => $term['term_taxonomy_id'] ]
		);

		$result = $this->importer->fix_hierarchical_taxonomies_parents( $wpdb->prefix, [ 'category' ] );

		$this->assertNotEmpty( $result );

		// Verify parent was reset to 0.
		$updated_parent = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT parent FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
				$term['term_taxonomy_id']
			)
		);
		$this->assertEquals( 0, (int) $updated_parent );
	}

	/**
	 * @test
	 * @covers DataImporter::fix_hierarchical_taxonomies_parents
	 */
	public function test_fix_hierarchical_taxonomies_parents_should_return_fixed_term_taxonomy_rows(): void {
		global $wpdb;

		$term = wp_insert_term( 'Another Orphan ' . uniqid(), 'category' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->term_taxonomy,
			[ 'parent' => 888888 ],
			[ 'term_taxonomy_id' => $term['term_taxonomy_id'] ]
		);

		$result = $this->importer->fix_hierarchical_taxonomies_parents( $wpdb->prefix, [ 'category' ] );

		$this->assertIsArray( $result );
		$found = false;
		foreach ( $result as $row ) {
			if ( (int) $row['term_taxonomy_id'] === $term['term_taxonomy_id'] ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * @test
	 * @covers DataImporter::fix_hierarchical_taxonomies_parents
	 */
	public function test_fix_hierarchical_taxonomies_parents_should_only_check_specified_taxonomies(): void {
		global $wpdb;

		// Create a term in post_tag with invalid parent.
		$term = wp_insert_term( 'Tag Orphan ' . uniqid(), 'post_tag' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->term_taxonomy,
			[ 'parent' => 777777 ],
			[ 'term_taxonomy_id' => $term['term_taxonomy_id'] ]
		);

		// Only check 'category', not 'post_tag'.
		$result = $this->importer->fix_hierarchical_taxonomies_parents( $wpdb->prefix, [ 'category' ] );

		// Should not find the post_tag term.
		$found = false;
		foreach ( $result as $row ) {
			if ( (int) $row['term_taxonomy_id'] === $term['term_taxonomy_id'] ) {
				$found = true;
				break;
			}
		}
		$this->assertFalse( $found );
	}
}
