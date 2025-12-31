<?php
/**
 * Unit tests for RunState.
 *
 * Tests run-state file read/write operations for migration progress tracking.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Unit\Logic;

use WP_UnitTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\Logger;
use ReflectionMethod;

/**
 * Unit test class for RunState.
 */
class RunStateTest extends WP_UnitTestCase {

	/**
	 * Temporary directory for test files.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * RunState instance.
	 *
	 * @var RunState
	 */
	private RunState $run_state;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Disable logging for tests.
		Logger::configure( false );

		// Create unique temp directory for each test.
		$this->temp_dir = sys_get_temp_dir() . '/runstate_test_' . uniqid();
		mkdir( $this->temp_dir, 0777, true ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.

		// Create RunState instance with a subdirectory.
		$this->run_state = new RunState( $this->temp_dir . '/run-state' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Recursively delete temp directory.
		$this->delete_directory( $this->temp_dir );
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function delete_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir.
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

	// =========================================================================
	// CONSTRUCTOR TESTS (3 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::__construct
	 */
	public function test_constructor_should_create_directory_if_not_exists(): void {
		$new_dir   = $this->temp_dir . '/new-run-state';
		$run_state = new RunState( $new_dir );

		$this->assertDirectoryExists( $new_dir );
	}

	/**
	 * @test
	 * @covers RunState::__construct
	 */
	public function test_constructor_should_create_nested_directory_recursively(): void {
		$nested_dir = $this->temp_dir . '/a/b/c/run-state';
		$run_state  = new RunState( $nested_dir );

		$this->assertDirectoryExists( $nested_dir );
	}

	/**
	 * @test
	 * @covers RunState::__construct
	 */
	public function test_constructor_should_trim_trailing_slash(): void {
		$dir_with_slash = $this->temp_dir . '/trimmed/';
		$run_state      = new RunState( $dir_with_slash );

		// The internal path should not have trailing slash - verify by checking file path.
		$file_path = $this->invoke_private_method( $run_state, 'get_file_path', [ 'test.json' ] );
		$this->assertEquals( $this->temp_dir . '/trimmed/test.json', $file_path );
	}

	// =========================================================================
	// PRIVATE CORE METHODS VIA REFLECTION (10 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::get_file_path
	 */
	public function test_get_file_path_should_return_correct_full_path(): void {
		$result = $this->invoke_private_method( $this->run_state, 'get_file_path', [ 'manifest.json' ] );

		$this->assertEquals( $this->temp_dir . '/run-state/manifest.json', $result );
	}

	/**
	 * @test
	 * @covers RunState::read_json
	 */
	public function test_read_json_should_return_null_for_nonexistent_file(): void {
		$result = $this->invoke_private_method( $this->run_state, 'read_json', [ 'nonexistent.json' ] );

		$this->assertNull( $result );
	}

	/**
	 * @test
	 * @covers RunState::read_json
	 */
	public function test_read_json_should_return_null_for_invalid_json(): void {
		$file_path = $this->temp_dir . '/run-state/invalid.json';
		file_put_contents( $file_path, 'not valid json {{{' );

		$result = $this->invoke_private_method( $this->run_state, 'read_json', [ 'invalid.json' ] );

		$this->assertNull( $result );
	}

	/**
	 * @test
	 * @covers RunState::read_json
	 */
	public function test_read_json_should_return_null_for_non_array_json(): void {
		$file_path = $this->temp_dir . '/run-state/string.json';
		file_put_contents( $file_path, '"just a string"' );

		$result = $this->invoke_private_method( $this->run_state, 'read_json', [ 'string.json' ] );

		$this->assertNull( $result );
	}

	/**
	 * @test
	 * @covers RunState::read_json
	 */
	public function test_read_json_should_return_array_for_valid_json(): void {
		$data      = [
			'key'    => 'value',
			'number' => 123,
		];
		$file_path = $this->temp_dir . '/run-state/valid.json';
		file_put_contents( $file_path, wp_json_encode( $data ) );

		$result = $this->invoke_private_method( $this->run_state, 'read_json', [ 'valid.json' ] );

		$this->assertEquals( $data, $result );
	}

	/**
	 * @test
	 * @covers RunState::write_json
	 */
	public function test_write_json_should_create_file_with_data(): void {
		$data = [ 'test' => 'data' ];

		$result = $this->invoke_private_method( $this->run_state, 'write_json', [ 'output.json', $data ] );

		$this->assertTrue( $result );
		$this->assertFileExists( $this->temp_dir . '/run-state/output.json' );

		$content = file_get_contents( $this->temp_dir . '/run-state/output.json' );
		$this->assertEquals( $data, json_decode( $content, true ) );
	}

	/**
	 * @test
	 * @covers RunState::write_json
	 */
	public function test_write_json_should_overwrite_existing_file(): void {
		$file_path = $this->temp_dir . '/run-state/overwrite.json';
		file_put_contents( $file_path, wp_json_encode( [ 'old' => 'data' ] ) );

		$new_data = [ 'new' => 'data' ];
		$this->invoke_private_method( $this->run_state, 'write_json', [ 'overwrite.json', $new_data ] );

		$content = file_get_contents( $file_path ); // phpcs:ignore -- WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown.
		$this->assertEquals( $new_data, json_decode( $content, true ) );
	}

	/**
	 * @test
	 * @covers RunState::read_jsonl
	 */
	public function test_read_jsonl_should_return_empty_array_for_nonexistent_file(): void {
		$result = $this->invoke_private_method( $this->run_state, 'read_jsonl', [ 'nonexistent.jsonl' ] );

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers RunState::read_jsonl
	 */
	public function test_read_jsonl_should_skip_empty_and_invalid_lines(): void {
		$file_path = $this->temp_dir . '/run-state/mixed.jsonl';
		$content   = wp_json_encode( [ 'valid' => 1 ] ) . "\n" .
					"\n" . // Empty line.
					"invalid json\n" . // Invalid JSON.
					wp_json_encode( [ 'valid' => 2 ] ) . "\n";
		file_put_contents( $file_path, $content );

		$result = $this->invoke_private_method( $this->run_state, 'read_jsonl', [ 'mixed.jsonl' ] );

		$this->assertCount( 2, $result );
		$this->assertEquals( [ 'valid' => 1 ], $result[0] );
		$this->assertEquals( [ 'valid' => 2 ], $result[1] );
	}

	/**
	 * @test
	 * @covers RunState::append_jsonl
	 */
	public function test_append_jsonl_should_append_line_with_newline(): void {
		$file_path = $this->temp_dir . '/run-state/append.jsonl';

		$this->invoke_private_method( $this->run_state, 'append_jsonl', [ 'append.jsonl', [ 'line' => 1 ] ] );
		$this->invoke_private_method( $this->run_state, 'append_jsonl', [ 'append.jsonl', [ 'line' => 2 ] ] );

		$content = file_get_contents( $file_path ); // phpcs:ignore -- WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown.
		$lines   = explode( "\n", trim( $content ) );

		$this->assertCount( 2, $lines );
		$this->assertEquals( [ 'line' => 1 ], json_decode( $lines[0], true ) );
		$this->assertEquals( [ 'line' => 2 ], json_decode( $lines[1], true ) );
	}

	// =========================================================================
	// NEW IDS TESTS (5 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::write_new_ids
	 */
	public function test_write_new_ids_should_write_array_of_integers(): void {
		$ids = [ 1, 2, 3, 4, 5 ];

		$result = $this->run_state->write_new_ids( $ids );

		$this->assertTrue( $result );
		$this->assertFileExists( $this->temp_dir . '/run-state/' . RunState::FILE_NEW_IDS );
	}

	/**
	 * @test
	 * @covers RunState::get_new_ids
	 */
	public function test_get_new_ids_should_return_null_if_file_not_exists(): void {
		// Use a fresh RunState with no files.
		$empty_dir = $this->temp_dir . '/empty';
		mkdir( $empty_dir ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.
		$empty_run_state = new RunState( $empty_dir . '/run-state' );

		$result = $empty_run_state->get_new_ids();

		$this->assertNull( $result );
	}

	/**
	 * @test
	 * @covers RunState::get_new_ids
	 */
	public function test_get_new_ids_should_return_empty_array_for_empty_json_array(): void {
		$this->run_state->write_new_ids( [] );

		$result = $this->run_state->get_new_ids();

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers RunState::get_new_ids
	 */
	public function test_get_new_ids_should_convert_string_ids_to_integers(): void {
		// Write file with string numbers (simulating JSON decode behavior).
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_NEW_IDS;
		file_put_contents( $file_path, '["1", "2", "3"]' );

		$result = $this->run_state->get_new_ids();

		$this->assertSame( [ 1, 2, 3 ], $result );
	}

	/**
	 * @test
	 * @covers RunState::write_new_ids
	 * @covers RunState::get_new_ids
	 */
	public function test_new_ids_roundtrip(): void {
		$ids = [ 100, 200, 300 ];

		$this->run_state->write_new_ids( $ids );
		$result = $this->run_state->get_new_ids();

		$this->assertEquals( $ids, $result );
	}

	// =========================================================================
	// MODIFIED IDS TESTS (4 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::write_modified_ids
	 */
	public function test_write_modified_ids_should_write_array_of_id_pairs(): void {
		$modified_ids = [
			[
				'live_id'  => 1,
				'local_id' => 10,
			],
			[
				'live_id'  => 2,
				'local_id' => 20,
			],
		];

		$result = $this->run_state->write_modified_ids( $modified_ids );

		$this->assertTrue( $result );
		$this->assertFileExists( $this->temp_dir . '/run-state/' . RunState::FILE_MODIFIED_IDS );
	}

	/**
	 * @test
	 * @covers RunState::get_modified_ids_map
	 */
	public function test_get_modified_ids_map_should_return_null_if_file_not_exists(): void {
		$empty_dir = $this->temp_dir . '/empty3';
		mkdir( $empty_dir ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.
		$empty_run_state = new RunState( $empty_dir . '/run-state' );

		$result = $empty_run_state->get_modified_ids_map();

		$this->assertNull( $result );
	}

	/**
	 * @test
	 * @covers RunState::get_modified_ids_map
	 */
	public function test_get_modified_ids_map_should_return_empty_array_for_empty_data(): void {
		$this->run_state->write_modified_ids( [] );

		$result = $this->run_state->get_modified_ids_map();

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers RunState::get_modified_ids_map
	 */
	public function test_get_modified_ids_map_should_return_live_to_local_map(): void {
		$modified_ids = [
			[
				'live_id'  => 100,
				'local_id' => 1000,
			],
			[
				'live_id'  => 200,
				'local_id' => 2000,
			],
		];
		$this->run_state->write_modified_ids( $modified_ids );

		$result = $this->run_state->get_modified_ids_map();

		$expected = [
			100 => 1000,
			200 => 2000,
		];
		$this->assertEquals( $expected, $result );
	}

	// =========================================================================
	// DELETED MODIFIED IDS TESTS (4 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::append_deleted_modified_id
	 */
	public function test_append_deleted_modified_id_should_append_to_file(): void {
		$result1 = $this->run_state->append_deleted_modified_id(
			[
				'live_id'  => 1,
				'local_id' => 10,
			] 
		);
		$result2 = $this->run_state->append_deleted_modified_id(
			[
				'live_id'  => 2,
				'local_id' => 20,
			] 
		);

		$this->assertTrue( $result1 );
		$this->assertTrue( $result2 );
		$this->assertFileExists( $this->temp_dir . '/run-state/' . RunState::FILE_DELETED_MODIFIED_IDS );
	}

	/**
	 * @test
	 * @covers RunState::get_deleted_modified_ids_map
	 */
	public function test_get_deleted_modified_ids_map_should_return_empty_array_if_no_file(): void {
		$empty_dir = $this->temp_dir . '/empty5';
		mkdir( $empty_dir ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.
		$empty_run_state = new RunState( $empty_dir . '/run-state' );

		$result = $empty_run_state->get_deleted_modified_ids_map();

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers RunState::get_deleted_modified_ids_map
	 */
	public function test_get_deleted_modified_ids_map_should_return_correct_map(): void {
		$this->run_state->append_deleted_modified_id(
			[
				'live_id'  => 100,
				'local_id' => 1000,
			] 
		);
		$this->run_state->append_deleted_modified_id(
			[
				'live_id'  => 200,
				'local_id' => 2000,
			] 
		);

		$result = $this->run_state->get_deleted_modified_ids_map();

		$expected = [
			100 => 1000,
			200 => 2000,
		];
		$this->assertEquals( $expected, $result );
	}

	/**
	 * @test
	 * @covers RunState::get_deleted_modified_ids_map
	 */
	public function test_get_deleted_modified_ids_map_should_cast_to_integers(): void {
		// Write with string IDs.
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_DELETED_MODIFIED_IDS;
		file_put_contents( $file_path, '{"live_id":"100","local_id":"1000"}' . "\n" );

		$result = $this->run_state->get_deleted_modified_ids_map();

		$this->assertSame( 100, array_key_first( $result ) );
		$this->assertSame( 1000, $result[100] );
	}

	// =========================================================================
	// IMPORTED POSTS TESTS (4 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::append_imported_post
	 */
	public function test_append_imported_post_should_append_to_file(): void {
		$result = $this->run_state->append_imported_post(
			[
				'post_type' => 'post',
				'id_old'    => 1,
				'id_new'    => 10,
			] 
		);

		$this->assertTrue( $result );
		$this->assertFileExists( $this->temp_dir . '/run-state/' . RunState::FILE_IMPORTED_POSTS );
	}

	/**
	 * @test
	 * @covers RunState::get_imported_post_ids_map
	 */
	public function test_get_imported_post_ids_map_should_return_empty_array_if_no_file(): void {
		$empty_dir = $this->temp_dir . '/empty7';
		mkdir( $empty_dir ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.
		$empty_run_state = new RunState( $empty_dir . '/run-state' );

		$result = $empty_run_state->get_imported_post_ids_map();

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers RunState::get_imported_post_ids_map
	 */
	public function test_get_imported_post_ids_map_should_return_old_to_new_map(): void {
		$this->run_state->append_imported_post(
			[
				'post_type' => 'post',
				'id_old'    => 100,
				'id_new'    => 1000,
			] 
		);
		$this->run_state->append_imported_post(
			[
				'post_type' => 'page',
				'id_old'    => 200,
				'id_new'    => 2000,
			] 
		);

		$result = $this->run_state->get_imported_post_ids_map();

		$expected = [
			100 => 1000,
			200 => 2000,
		];
		$this->assertEquals( $expected, $result );
	}

	/**
	 * @test
	 * @covers RunState::get_imported_post_ids_map
	 */
	public function test_get_imported_post_ids_map_should_cast_to_integers(): void {
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_IMPORTED_POSTS;
		file_put_contents( $file_path, '{"post_type":"post","id_old":"100","id_new":"1000"}' . "\n" );

		$result = $this->run_state->get_imported_post_ids_map();

		$this->assertSame( 100, array_key_first( $result ) );
		$this->assertSame( 1000, $result[100] );
	}

	// =========================================================================
	// UPDATED PARENTS TESTS (4 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::append_updated_parent
	 */
	public function test_append_updated_parent_should_append_to_file(): void {
		$result = $this->run_state->append_updated_parent(
			[
				'id_old'        => 1,
				'id_new'        => 10,
				'parent_id_old' => 2,
				'parent_id_new' => 20,
			] 
		);

		$this->assertTrue( $result );
		$this->assertFileExists( $this->temp_dir . '/run-state/' . RunState::FILE_UPDATED_PARENTS );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_parents_post_ids_map
	 */
	public function test_get_updated_parents_post_ids_map_should_return_empty_array_if_no_file(): void {
		$empty_dir = $this->temp_dir . '/empty9';
		mkdir( $empty_dir ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.
		$empty_run_state = new RunState( $empty_dir . '/run-state' );

		$result = $empty_run_state->get_updated_parents_post_ids_map();

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_parents_post_ids_map
	 */
	public function test_get_updated_parents_post_ids_map_should_return_correct_map(): void {
		$this->run_state->append_updated_parent(
			[
				'id_old'        => 100,
				'id_new'        => 1000,
				'parent_id_old' => 5,
				'parent_id_new' => 50,
			] 
		);
		$this->run_state->append_updated_parent(
			[
				'id_old'        => 200,
				'id_new'        => 2000,
				'parent_id_old' => 6,
				'parent_id_new' => 60,
			] 
		);

		$result = $this->run_state->get_updated_parents_post_ids_map();

		$expected = [
			100 => 1000,
			200 => 2000,
		];
		$this->assertEquals( $expected, $result );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_parents_post_ids_map
	 */
	public function test_get_updated_parents_post_ids_map_should_cast_to_integers(): void {
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_UPDATED_PARENTS;
		file_put_contents( $file_path, '{"id_old":"100","id_new":"1000"}' . "\n" );

		$result = $this->run_state->get_updated_parents_post_ids_map();

		$this->assertSame( 100, array_key_first( $result ) );
		$this->assertSame( 1000, $result[100] );
	}

	// =========================================================================
	// UPDATED FEATURED IMAGES TESTS (5 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::append_updated_featured_image_post
	 */
	public function test_append_updated_featured_image_post_should_append_to_file(): void {
		$result = $this->run_state->append_updated_featured_image_post(
			[
				'id_old' => 1,
				'id_new' => 10,
			] 
		);

		$this->assertTrue( $result );
		$this->assertFileExists( $this->temp_dir . '/run-state/' . RunState::FILE_UPDATED_FEATURED );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_featured_image_post_ids_map
	 */
	public function test_get_updated_featured_image_post_ids_map_should_return_empty_array_if_no_file(): void {
		$empty_dir = $this->temp_dir . '/empty11';
		mkdir( $empty_dir ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.
		$empty_run_state = new RunState( $empty_dir . '/run-state' );

		$result = $empty_run_state->get_updated_featured_image_post_ids_map();

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_featured_image_post_ids_map
	 */
	public function test_get_updated_featured_image_post_ids_map_should_return_correct_map(): void {
		$this->run_state->append_updated_featured_image_post(
			[
				'id_old' => 100,
				'id_new' => 1000,
			] 
		);
		$this->run_state->append_updated_featured_image_post(
			[
				'id_old' => 200,
				'id_new' => 2000,
			] 
		);

		$result = $this->run_state->get_updated_featured_image_post_ids_map();

		$expected = [
			100 => 1000,
			200 => 2000,
		];
		$this->assertEquals( $expected, $result );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_featured_image_post_ids_map
	 */
	public function test_get_updated_featured_image_post_ids_map_should_skip_entries_missing_keys(): void {
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_UPDATED_FEATURED;
		$content   = '{"id_old":100,"id_new":1000}' . "\n" .
					'{"missing_key":200}' . "\n" . // Missing id_old and id_new.
					'{"id_old":300,"id_new":3000}' . "\n";
		file_put_contents( $file_path, $content );

		$result = $this->run_state->get_updated_featured_image_post_ids_map();

		$this->assertCount( 2, $result );
		$this->assertEquals( 1000, $result[100] );
		$this->assertEquals( 3000, $result[300] );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_featured_image_post_ids_map
	 */
	public function test_get_updated_featured_image_post_ids_map_should_cast_to_integers(): void {
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_UPDATED_FEATURED;
		file_put_contents( $file_path, '{"id_old":"100","id_new":"1000"}' . "\n" );

		$result = $this->run_state->get_updated_featured_image_post_ids_map();

		$this->assertSame( 100, array_key_first( $result ) );
		$this->assertSame( 1000, $result[100] );
	}

	// =========================================================================
	// UPDATED BLOCKS TESTS (5 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::append_updated_block_post
	 */
	public function test_append_updated_block_post_should_append_to_file(): void {
		$result = $this->run_state->append_updated_block_post(
			[
				'id_old' => 1,
				'id_new' => 10,
			] 
		);

		$this->assertTrue( $result );
		$this->assertFileExists( $this->temp_dir . '/run-state/' . RunState::FILE_UPDATED_BLOCKS );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_block_post_ids_map
	 */
	public function test_get_updated_block_post_ids_map_should_return_empty_array_if_no_file(): void {
		$empty_dir = $this->temp_dir . '/empty13';
		mkdir( $empty_dir ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.
		$empty_run_state = new RunState( $empty_dir . '/run-state' );

		$result = $empty_run_state->get_updated_block_post_ids_map();

		$this->assertEquals( [], $result );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_block_post_ids_map
	 */
	public function test_get_updated_block_post_ids_map_should_return_correct_map(): void {
		$this->run_state->append_updated_block_post(
			[
				'id_old' => 100,
				'id_new' => 1000,
			] 
		);
		$this->run_state->append_updated_block_post(
			[
				'id_old' => 200,
				'id_new' => 2000,
			] 
		);

		$result = $this->run_state->get_updated_block_post_ids_map();

		$expected = [
			100 => 1000,
			200 => 2000,
		];
		$this->assertEquals( $expected, $result );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_block_post_ids_map
	 */
	public function test_get_updated_block_post_ids_map_should_skip_entries_missing_keys(): void {
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_UPDATED_BLOCKS;
		$content   = '{"id_old":100,"id_new":1000}' . "\n" .
					'{"missing_key":200}' . "\n" . // Missing id_old and id_new.
					'{"id_old":300,"id_new":3000}' . "\n";
		file_put_contents( $file_path, $content );

		$result = $this->run_state->get_updated_block_post_ids_map();

		$this->assertCount( 2, $result );
		$this->assertEquals( 1000, $result[100] );
		$this->assertEquals( 3000, $result[300] );
	}

	/**
	 * @test
	 * @covers RunState::get_updated_block_post_ids_map
	 */
	public function test_get_updated_block_post_ids_map_should_cast_to_integers(): void {
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_UPDATED_BLOCKS;
		file_put_contents( $file_path, '{"id_old":"100","id_new":"1000"}' . "\n" );

		$result = $this->run_state->get_updated_block_post_ids_map();

		$this->assertSame( 100, array_key_first( $result ) );
		$this->assertSame( 1000, $result[100] );
	}

	// =========================================================================
	// MANIFEST TESTS (2 tests)
	// =========================================================================

	/**
	 * @test
	 * @covers RunState::write_manifest
	 */
	public function test_write_manifest_should_write_data_to_file(): void {
		$manifest = [
			'started_at'      => '2024-01-01 12:00:00',
			'source_hostname' => 'example.com',
			'new_ids_count'   => 100,
		];

		$result = $this->run_state->write_manifest( $manifest );

		$this->assertTrue( $result );
		$this->assertFileExists( $this->temp_dir . '/run-state/' . RunState::FILE_MANIFEST );
	}

	/**
	 * @test
	 * @covers RunState::write_manifest
	 */
	public function test_write_manifest_roundtrip_via_read_json(): void {
		$manifest = [
			'started_at'      => '2024-01-01 12:00:00',
			'source_hostname' => 'example.com',
			'new_ids_count'   => 100,
		];

		$this->run_state->write_manifest( $manifest );
		$result = $this->invoke_private_method( $this->run_state, 'read_json', [ RunState::FILE_MANIFEST ] );

		$this->assertEquals( $manifest, $result );
	}
}
