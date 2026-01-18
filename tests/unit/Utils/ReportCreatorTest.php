<?php
/**
 * Unit tests for ReportCreator.
 *
 * Tests CSV report generation from run-state JSONL files.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Unit\Utils;

use WP_UnitTestCase;
use Newspack\ContentDiffMigrator\Utils\ReportCreator;
use Newspack\ContentDiffMigrator\Logic\RunState;
use Newspack\ContentDiffMigrator\Utils\Logger;

/**
 * Unit test class for ReportCreator.
 */
class ReportCreatorTest extends WP_UnitTestCase {

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
	 * ReportCreator instance.
	 *
	 * @var ReportCreator
	 */
	private ReportCreator $report_creator;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Disable logging for tests.
		Logger::configure( false );

		// Create unique temp directory for each test.
		$this->temp_dir = sys_get_temp_dir() . '/report_creator_test_' . uniqid();
		mkdir( $this->temp_dir, 0777, true ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir.

		// Create RunState instance.
		$this->run_state = new RunState( $this->temp_dir . '/run-state' );

		// Create ReportCreator instance.
		$this->report_creator = new ReportCreator( $this->run_state );
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
	 * Parse a CSV file and return array of rows (each row is an associative array).
	 *
	 * @param string $file_path Path to CSV file.
	 *
	 * @return array Array of rows with headers as keys.
	 */
	private function parse_csv( string $file_path ): array {
		if ( ! file_exists( $file_path ) ) {
			return [];
		}

		$rows   = [];
		$handle = fopen( $file_path, 'r' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.

		// Escape='' for RFC 4180 compliance (php.net/fgetcsv).
		$headers = fgetcsv( $handle, null, ',', '"', '' ); // First row is headers.

		// Escape='' for RFC 4180 compliance (php.net/fgetcsv).
		while ( ( $data = fgetcsv( $handle, null, ',', '"', '' ) ) !== false ) {
			$row = [];
			foreach ( $headers as $index => $header ) {
				$row[ $header ] = $data[ $index ] ?? '';
			}
			$rows[] = $row;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		return $rows;
	}

	/**
	 * Get CSV headers from a file.
	 *
	 * @param string $file_path Path to CSV file.
	 *
	 * @return array Headers array.
	 */
	private function get_csv_headers( string $file_path ): array {
		if ( ! file_exists( $file_path ) ) {
			return [];
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.

		// Escape='' for RFC 4180 compliance (php.net/fgetcsv).
		$headers = fgetcsv( $handle, null, ',', '"', '' );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		return $headers ?: []; // phpcs:ignore -- Universal.Operators.DisallowShortTernary.Found.
	}

	// =========================================================================
	// CSV HEADERS TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_create_posts_csv_with_correct_headers(): void {
		$reports_dir = $this->temp_dir . '/reports';

		$this->report_creator->create_all_csvs( $reports_dir );

		$headers = $this->get_csv_headers( $reports_dir . '/' . ReportCreator::REPORT_POSTS );
		$this->assertEquals( [ 'status', 'post_type', 'id_old', 'id_new' ], $headers );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_create_users_csv_with_correct_headers(): void {
		$reports_dir = $this->temp_dir . '/reports';

		$this->report_creator->create_all_csvs( $reports_dir );

		$headers = $this->get_csv_headers( $reports_dir . '/' . ReportCreator::REPORT_USERS );
		$this->assertEquals( [ 'status', 'id_old', 'id_new' ], $headers );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_create_terms_csv_with_correct_headers(): void {
		$reports_dir = $this->temp_dir . '/reports';

		$this->report_creator->create_all_csvs( $reports_dir );

		$headers = $this->get_csv_headers( $reports_dir . '/' . ReportCreator::REPORT_TERMS );
		$this->assertEquals( [ 'status', 'term_id_old', 'term_id_new', 'taxonomy' ], $headers );
	}

	// =========================================================================
	// POSTS CSV TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_write_imported_posts_to_csv(): void {
		// Add imported posts to run-state.
		$this->run_state->append_imported_post(
			[
				'id_old'    => 100,
				'id_new'    => 200,
				'post_type' => 'post',
				'status'    => 'imported',
			]
		);
		$this->run_state->append_imported_post(
			[
				'id_old'    => 101,
				'id_new'    => 201,
				'post_type' => 'page',
				'status'    => 'imported',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_POSTS );

		$this->assertCount( 2, $rows );
		$this->assertEquals( 'imported', $rows[0]['status'] );
		$this->assertEquals( 'post', $rows[0]['post_type'] );
		$this->assertEquals( '100', $rows[0]['id_old'] );
		$this->assertEquals( '200', $rows[0]['id_new'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_write_modified_posts_to_csv(): void {
		// Add imported post first.
		$this->run_state->append_imported_post(
			[
				'id_old'    => 100,
				'id_new'    => 200,
				'post_type' => 'post',
				'status'    => 'imported',
			]
		);

		// Add deleted/modified entry (these mark posts as "modified").
		$this->run_state->append_deleted_modified_id(
			[
				'live_id'  => 100,
				'local_id' => 200,
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_POSTS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'modified', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_deduplicate_posts_with_priority_modified_over_imported(): void {
		// Add same post twice: first as imported, then as modified.
		$this->run_state->append_imported_post(
			[
				'id_old'    => 100,
				'id_new'    => 200,
				'post_type' => 'post',
				'status'    => 'imported',
			]
		);
		// Append again with modified status.
		$this->run_state->append_imported_post(
			[
				'id_old'    => 100,
				'id_new'    => 200,
				'post_type' => 'post',
				'status'    => 'modified',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_POSTS );

		// Should only have one row with "modified" status.
		$this->assertCount( 1, $rows );
		$this->assertEquals( 'modified', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_include_attachment_posts_with_modified_status(): void {
		// Add attachment with modified status (MDCS field update).
		$this->run_state->append_imported_post(
			[
				'id_old'    => 500,
				'id_new'    => 600,
				'post_type' => 'attachment',
				'status'    => 'modified',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_POSTS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'modified', $rows[0]['status'] );
		$this->assertEquals( 'attachment', $rows[0]['post_type'] );
	}

	// =========================================================================
	// USERS CSV TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_write_imported_users_to_csv(): void {
		$this->run_state->append_imported_user(
			[
				'id_old' => 10,
				'id_new' => 20,
				'status' => 'imported',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_USERS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'imported', $rows[0]['status'] );
		$this->assertEquals( '10', $rows[0]['id_old'] );
		$this->assertEquals( '20', $rows[0]['id_new'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_write_merged_users_to_csv(): void {
		$this->run_state->append_imported_user(
			[
				'id_old' => 10,
				'id_new' => 20,
				'status' => 'merged',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_USERS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'merged', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_write_modified_users_to_csv(): void {
		$this->run_state->append_imported_user(
			[
				'id_old' => 10,
				'id_new' => 20,
				'status' => 'modified',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_USERS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'modified', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_deduplicate_users_with_priority_modified_over_merged_over_imported(): void {
		// Same user appears with different statuses.
		$this->run_state->append_imported_user(
			[
				'id_old' => 10,
				'id_new' => 20,
				'status' => 'imported',
			]
		);
		$this->run_state->append_imported_user(
			[
				'id_old' => 10,
				'id_new' => 20,
				'status' => 'merged',
			]
		);
		$this->run_state->append_imported_user(
			[
				'id_old' => 10,
				'id_new' => 20,
				'status' => 'modified',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_USERS );

		// Should only have one row with highest priority status.
		$this->assertCount( 1, $rows );
		$this->assertEquals( 'modified', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_deduplicate_users_merged_over_imported(): void {
		// User imported first, then merged from another source.
		$this->run_state->append_imported_user(
			[
				'id_old' => 10,
				'id_new' => 20,
				'status' => 'imported',
			]
		);
		$this->run_state->append_imported_user(
			[
				'id_old' => 11,
				'id_new' => 20,
				'status' => 'merged',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_USERS );

		// Should only have one row with "merged" status (higher priority).
		$this->assertCount( 1, $rows );
		$this->assertEquals( 'merged', $rows[0]['status'] );
	}

	// =========================================================================
	// TERMS CSV TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_write_imported_terms_to_csv(): void {
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 50,
				'term_id_new' => 60,
				'taxonomy'    => 'category',
				'status'      => 'imported',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_TERMS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'imported', $rows[0]['status'] );
		$this->assertEquals( '50', $rows[0]['term_id_old'] );
		$this->assertEquals( '60', $rows[0]['term_id_new'] );
		$this->assertEquals( 'category', $rows[0]['taxonomy'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_write_merged_terms_to_csv(): void {
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 50,
				'term_id_new' => 60,
				'taxonomy'    => 'post_tag',
				'status'      => 'merged',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_TERMS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'merged', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_write_modified_terms_to_csv(): void {
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 50,
				'term_id_new' => 60,
				'taxonomy'    => 'category',
				'status'      => 'modified',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_TERMS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'modified', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_deduplicate_terms_with_priority_modified_over_merged_over_imported(): void {
		// Same term appears with different statuses.
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 50,
				'term_id_new' => 60,
				'taxonomy'    => 'category',
				'status'      => 'imported',
			]
		);
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 51,
				'term_id_new' => 60,
				'taxonomy'    => 'category',
				'status'      => 'merged',
			]
		);
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 50,
				'term_id_new' => 60,
				'taxonomy'    => 'category',
				'status'      => 'modified',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_TERMS );

		// Should only have one row with highest priority status.
		$this->assertCount( 1, $rows );
		$this->assertEquals( 'modified', $rows[0]['status'] );
	}

	// =========================================================================
	// DIRECTORY AND EMPTY DATA TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_create_reports_directory_if_not_exists(): void {
		$reports_dir = $this->temp_dir . '/new-reports-dir';
		$this->assertDirectoryDoesNotExist( $reports_dir );

		$this->report_creator->create_all_csvs( $reports_dir );

		$this->assertDirectoryExists( $reports_dir );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_generate_empty_csvs_with_headers_when_no_data(): void {
		$reports_dir = $this->temp_dir . '/reports';

		$this->report_creator->create_all_csvs( $reports_dir );

		// All three CSVs should exist.
		$this->assertFileExists( $reports_dir . '/' . ReportCreator::REPORT_POSTS );
		$this->assertFileExists( $reports_dir . '/' . ReportCreator::REPORT_USERS );
		$this->assertFileExists( $reports_dir . '/' . ReportCreator::REPORT_TERMS );

		// Each should have headers but no data rows.
		$posts_rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_POSTS );
		$users_rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_USERS );
		$terms_rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_TERMS );

		$this->assertCount( 0, $posts_rows );
		$this->assertCount( 0, $users_rows );
		$this->assertCount( 0, $terms_rows );

		// But headers should exist.
		$this->assertNotEmpty( $this->get_csv_headers( $reports_dir . '/' . ReportCreator::REPORT_POSTS ) );
		$this->assertNotEmpty( $this->get_csv_headers( $reports_dir . '/' . ReportCreator::REPORT_USERS ) );
		$this->assertNotEmpty( $this->get_csv_headers( $reports_dir . '/' . ReportCreator::REPORT_TERMS ) );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_handle_multiple_post_types_in_posts_csv(): void {
		$this->run_state->append_imported_post(
			[
				'id_old'    => 100,
				'id_new'    => 200,
				'post_type' => 'post',
				'status'    => 'imported',
			]
		);
		$this->run_state->append_imported_post(
			[
				'id_old'    => 101,
				'id_new'    => 201,
				'post_type' => 'page',
				'status'    => 'imported',
			]
		);
		$this->run_state->append_imported_post(
			[
				'id_old'    => 102,
				'id_new'    => 202,
				'post_type' => 'attachment',
				'status'    => 'imported',
			]
		);
		$this->run_state->append_imported_post(
			[
				'id_old'    => 103,
				'id_new'    => 203,
				'post_type' => 'custom_cpt',
				'status'    => 'imported',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_POSTS );

		$this->assertCount( 4, $rows );

		$post_types = array_column( $rows, 'post_type' );
		$this->assertContains( 'post', $post_types );
		$this->assertContains( 'page', $post_types );
		$this->assertContains( 'attachment', $post_types );
		$this->assertContains( 'custom_cpt', $post_types );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_handle_multiple_taxonomies_in_terms_csv(): void {
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 50,
				'term_id_new' => 60,
				'taxonomy'    => 'category',
				'status'      => 'imported',
			]
		);
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 51,
				'term_id_new' => 61,
				'taxonomy'    => 'post_tag',
				'status'      => 'imported',
			]
		);
		$this->run_state->append_imported_term(
			[
				'term_id_old' => 52,
				'term_id_new' => 62,
				'taxonomy'    => 'custom_taxonomy',
				'status'      => 'imported',
			]
		);

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_TERMS );

		$this->assertCount( 3, $rows );

		$taxonomies = array_column( $rows, 'taxonomy' );
		$this->assertContains( 'category', $taxonomies );
		$this->assertContains( 'post_tag', $taxonomies );
		$this->assertContains( 'custom_taxonomy', $taxonomies );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_use_default_status_imported_when_status_missing_in_post(): void {
		// Manually write a JSONL entry without status field.
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_IMPORTED_POSTS;
		file_put_contents( $file_path, '{"id_old":100,"id_new":200,"post_type":"post"}' . "\n" );

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_POSTS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'imported', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_use_default_status_imported_when_status_missing_in_user(): void {
		// Manually write a JSONL entry without status field.
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_IMPORTED_USERS;
		file_put_contents( $file_path, '{"id_old":10,"id_new":20}' . "\n" );

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_USERS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'imported', $rows[0]['status'] );
	}

	/**
	 * @test
	 * @covers ReportCreator::create_all_csvs
	 */
	public function test_should_use_default_status_imported_when_status_missing_in_term(): void {
		// Manually write a JSONL entry without status field.
		$file_path = $this->temp_dir . '/run-state/' . RunState::FILE_IMPORTED_TERMS;
		file_put_contents( $file_path, '{"term_id_old":50,"term_id_new":60,"taxonomy":"category"}' . "\n" );

		$reports_dir = $this->temp_dir . '/reports';
		$this->report_creator->create_all_csvs( $reports_dir );

		$rows = $this->parse_csv( $reports_dir . '/' . ReportCreator::REPORT_TERMS );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 'imported', $rows[0]['status'] );
	}
}
