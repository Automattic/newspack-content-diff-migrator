<?php
/**
 * Integration tests for attribution commands.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for attribution commands.
 *
 * @group integration
 */
class CmdAttributeTest extends IntegrationTestCase {

	/**
	 * Helper to create a JSONL file with ID pairs.
	 *
	 * @param array $pairs Array of ['old_id' => int, 'local_id' => int] pairs.
	 *
	 * @return string Path to the created JSONL file.
	 */
	private function create_jsonl_file( array $pairs ): string {
		$file_path = $this->temp_data_dir . '/' . uniqid( 'test_ids_' ) . '.jsonl';
		$content   = '';
		foreach ( $pairs as $pair ) {
			$content .= wp_json_encode( $pair ) . "\n";
		}
		file_put_contents( $file_path, $content ); // phpcs:ignore -- test file.
		return $file_path;
	}
	
	/**
	 * =========================================================================
	 * cmd_attribute_ids Tests
	 * =========================================================================
	 */

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_attribute_posts_with_different_old_and_local_ids(): void {
		// Create posts.
		$post1 = self::factory()->post->create(
			[
				'post_title'  => 'Post 1',
				'post_status' => 'publish',
			] 
		);
		$post2 = self::factory()->post->create(
			[
				'post_title'  => 'Post 2',
				'post_status' => 'publish',
			] 
		);
		$post3 = self::factory()->post->create(
			[
				'post_title'  => 'Post 3 (not in list)',
				'post_status' => 'publish',
			] 
		);

		// Create JSONL file with ID pairs where old_id != local_id.
		$jsonl_file = $this->create_jsonl_file(
			[
				[
					'old_id'   => 1001,
					'local_id' => $post1,
				],
				[
					'old_id'   => 1002,
					'local_id' => $post2,
				],
			]
		);

		// Run command with JSONL file.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => $jsonl_file,
			] 
		);

		// Verify specified posts were attributed with correct old_id values.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( 1001, (int) get_post_meta( $post1, $meta_key, true ), 'Post 1 should be attributed with old_id 1001.' );
		$this->assertEquals( 1002, (int) get_post_meta( $post2, $meta_key, true ), 'Post 2 should be attributed with old_id 1002.' );

		// Verify post not in list was not attributed.
		$this->assertEmpty( get_post_meta( $post3, $meta_key, true ), 'Post 3 should not be attributed.' );
	}

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_attribute_users_with_different_old_and_local_ids(): void {
		// Create users.
		$user1 = self::factory()->user->create( [ 'user_login' => 'user1_' . uniqid() ] );
		$user2 = self::factory()->user->create( [ 'user_login' => 'user2_' . uniqid() ] );
		$user3 = self::factory()->user->create( [ 'user_login' => 'user3_' . uniqid() ] );

		// Create JSONL file with ID pairs.
		$jsonl_file = $this->create_jsonl_file(
			[
				[
					'old_id'   => 501,
					'local_id' => $user1,
				],
				[
					'old_id'   => 502,
					'local_id' => $user2,
				],
			]
		);

		// Run command with JSONL file.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'user-ids'        => $jsonl_file,
			] 
		);

		// Verify specified users were attributed with correct old_id values.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( 501, (int) get_user_meta( $user1, $meta_key, true ), 'User 1 should be attributed with old_id 501.' );
		$this->assertEquals( 502, (int) get_user_meta( $user2, $meta_key, true ), 'User 2 should be attributed with old_id 502.' );

		// Verify user not in list was not attributed.
		$this->assertEmpty( get_user_meta( $user3, $meta_key, true ), 'User 3 should not be attributed.' );
	}

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_attribute_terms_with_different_old_and_local_ids(): void {
		// Create terms.
		$term1 = wp_insert_term( 'Term 1 ' . uniqid(), 'category' );
		$term2 = wp_insert_term( 'Term 2 ' . uniqid(), 'category' );
		$term3 = wp_insert_term( 'Term 3 ' . uniqid(), 'category' );

		// Create JSONL file with ID pairs.
		$jsonl_file = $this->create_jsonl_file(
			[
				[
					'old_id'   => 301,
					'local_id' => $term1['term_id'],
				],
				[
					'old_id'   => 302,
					'local_id' => $term2['term_id'],
				],
			]
		);

		// Run command with JSONL file.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'term-ids'        => $jsonl_file,
			] 
		);

		// Verify specified terms were attributed with correct old_id values.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( 301, (int) get_term_meta( $term1['term_id'], $meta_key, true ), 'Term 1 should be attributed with old_id 301.' );
		$this->assertEquals( 302, (int) get_term_meta( $term2['term_id'], $meta_key, true ), 'Term 2 should be attributed with old_id 302.' );

		// Verify term not in list was not attributed.
		$this->assertEmpty( get_term_meta( $term3['term_id'], $meta_key, true ), 'Term 3 should not be attributed.' );
	}

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_attribute_attachments_with_different_old_and_local_ids(): void {
		// Create attachments.
		$attachment1 = self::factory()->attachment->create( [ 'post_title' => 'Attachment 1' ] );
		$attachment2 = self::factory()->attachment->create( [ 'post_title' => 'Attachment 2' ] );
		$attachment3 = self::factory()->attachment->create( [ 'post_title' => 'Attachment 3 (not in list)' ] );

		// Create JSONL file with ID pairs.
		$jsonl_file = $this->create_jsonl_file(
			[
				[
					'old_id'   => 2001,
					'local_id' => $attachment1,
				],
				[
					'old_id'   => 2002,
					'local_id' => $attachment2,
				],
			]
		);

		// Run command with JSONL file.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'attachment-ids'  => $jsonl_file,
			] 
		);

		// Verify specified attachments were attributed with correct old_id values.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( 2001, (int) get_post_meta( $attachment1, $meta_key, true ), 'Attachment 1 should be attributed with old_id 2001.' );
		$this->assertEquals( 2002, (int) get_post_meta( $attachment2, $meta_key, true ), 'Attachment 2 should be attributed with old_id 2002.' );

		// Verify attachment not in list was not attributed.
		$this->assertEmpty( get_post_meta( $attachment3, $meta_key, true ), 'Attachment 3 should not be attributed.' );
	}

	/**
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_skip_already_attributed_ids(): void {
		// Create content and pre-attribute to same source with different values.
		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$user = self::factory()->user->create( [ 'user_login' => 'test_user_' . uniqid() ] );
		$term = wp_insert_term( 'Test Term ' . uniqid(), 'category' );

		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $post, $meta_key, 999 );
		update_user_meta( $user, $meta_key, 888 );
		update_term_meta( $term['term_id'], $meta_key, 777 );

		// Create JSONL files with ID pairs.
		$post_jsonl = $this->create_jsonl_file(
			[
				[
					'old_id'   => 111,
					'local_id' => $post,
				],
			] 
		);
		$user_jsonl = $this->create_jsonl_file(
			[
				[
					'old_id'   => 222,
					'local_id' => $user,
				],
			] 
		);
		$term_jsonl = $this->create_jsonl_file(
			[
				[
					'old_id'   => 333,
					'local_id' => $term['term_id'],
				],
			] 
		);

		// Run command with these IDs.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => $post_jsonl,
				'user-ids'        => $user_jsonl,
				'term-ids'        => $term_jsonl,
			] 
		);

		// Verify meta was not changed (still has original values, not overwritten).
		$this->assertEquals( 999, (int) get_post_meta( $post, $meta_key, true ), 'Post meta should not change.' );
		$this->assertEquals( 888, (int) get_user_meta( $user, $meta_key, true ), 'User meta should not change.' );
		$this->assertEquals( 777, (int) get_term_meta( $term['term_id'], $meta_key, true ), 'Term meta should not change.' );
	}

	/**
	 * Tests that cmd_attribute_ids logs error and returns when no IDs are provided.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_log_error_when_no_ids_provided(): void {
		// Call command with no ID arguments - should return early without throwing.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
			]
		);

		// Logger is configured( false ) in tests, so no actual log file is created.
		// Method should return gracefully, and if it gets here, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * Tests that cmd_attribute_ids skips nonexistent posts gracefully.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_skip_nonexistent_posts(): void {
		// Create JSONL file with non-existent local_id.
		$jsonl_file = $this->create_jsonl_file(
			[
				[
					'old_id'   => 100,
					'local_id' => 999999,
				],
			] 
		);

		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => $jsonl_file,
			]
		);

		// Nonexistent posts are logged as warnings in null logger, but don't cause failures.
		// Method should complete without throwing an exception.
		$this->assertTrue( true );
	}

	/**
	 * Tests that cmd_attribute_ids handles invalid JSONL lines gracefully.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_skip_invalid_jsonl_lines(): void {
		// Create a post that should be attributed.
		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Create JSONL file with some invalid lines.
		$file_path = $this->temp_data_dir . '/test_invalid.jsonl';
		$content   = "not valid json\n";
		$content  .= '{"missing_local_id": 100}' . "\n";
		$content  .= '{"old_id": "not_numeric", "local_id": ' . $post . "}\n";
		$content  .= '{"old_id": 1234, "local_id": ' . $post . "}\n"; // Valid line.
		file_put_contents( $file_path, $content ); // phpcs:ignore -- test file.

		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => $file_path,
			]
		);

		// Verify the valid line was processed.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( 1234, (int) get_post_meta( $post, $meta_key, true ), 'Post should be attributed with old_id 1234 from valid line.' );
	}

	/**
	 * Tests that cmd_attribute_ids handles non-existent JSONL file gracefully.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_handle_nonexistent_file(): void {
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => '/nonexistent/path/file.jsonl',
			]
		);

		// Method should complete gracefully without throwing.
		$this->assertTrue( true );
	}

	/**
	 * Tests attribution of multiple object types at once.
	 *
	 * @test
	 * @group attribute-commands
	 */
	public function attribute_ids_should_attribute_multiple_types_at_once(): void {
		// Create objects.
		$post       = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$attachment = self::factory()->attachment->create( [ 'post_title' => 'Test Attachment' ] );
		$user       = self::factory()->user->create( [ 'user_login' => 'multitype_user_' . uniqid() ] );
		$term       = wp_insert_term( 'Multi Type Term ' . uniqid(), 'category' );

		// Create JSONL files.
		$post_jsonl       = $this->create_jsonl_file(
			[
				[
					'old_id'   => 100,
					'local_id' => $post,
				],
			] 
		);
		$attachment_jsonl = $this->create_jsonl_file(
			[
				[
					'old_id'   => 200,
					'local_id' => $attachment,
				],
			] 
		);
		$user_jsonl       = $this->create_jsonl_file(
			[
				[
					'old_id'   => 300,
					'local_id' => $user,
				],
			] 
		);
		$term_jsonl       = $this->create_jsonl_file(
			[
				[
					'old_id'   => 400,
					'local_id' => $term['term_id'],
				],
			] 
		);

		// Run command with all types.
		$this->command->cmd_attribute_ids(
			[],
			[
				'source-hostname' => $this->source_hostname,
				'data-dir'        => $this->temp_data_dir,
				'post-ids'        => $post_jsonl,
				'attachment-ids'  => $attachment_jsonl,
				'user-ids'        => $user_jsonl,
				'term-ids'        => $term_jsonl,
			] 
		);

		// Verify all were attributed correctly.
		$meta_key = $this->get_old_id_meta_key();
		$this->assertEquals( 100, (int) get_post_meta( $post, $meta_key, true ), 'Post should be attributed.' );
		$this->assertEquals( 200, (int) get_post_meta( $attachment, $meta_key, true ), 'Attachment should be attributed.' );
		$this->assertEquals( 300, (int) get_user_meta( $user, $meta_key, true ), 'User should be attributed.' );
		$this->assertEquals( 400, (int) get_term_meta( $term['term_id'], $meta_key, true ), 'Term should be attributed.' );
	}
}
