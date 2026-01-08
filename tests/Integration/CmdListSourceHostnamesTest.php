<?php
/**
 * Integration tests for cmd_list_migrated_source_hostnames command.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for cmd_list_migrated_source_hostnames command.
 *
 * @group integration
 */
class CmdListSourceHostnamesTest extends IntegrationTestCase {
	/**
	 * Tests that no hostnames are returned when no content has been imported.
	 *
	 * @group list-previously-migrated-source-hostnames-command
	 */
	public function test_should_return_empty_when_no_imported_content(): void {
		$hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertEmpty( $hostnames, 'Should return empty array when no content has been imported.' );
	}

	/**
	 * Tests that hostname is returned after importing content from a single source.
	 *
	 * @group list-previously-migrated-source-hostnames-command
	 */
	public function test_should_return_single_hostname_after_import(): void {
		// Import a post from live DB.
		$fixture = $this->load_fixture( 'post-basic' );
		$this->insert_live_data( $fixture );

		$this->run_search_command();
		$this->run_migrate_command();

		$hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertCount( 1, $hostnames, 'Should return one hostname.' );
		$this->assertContains( $this->source_hostname, $hostnames, 'Should contain the source hostname used for import.' );
	}

	/**
	 * Tests that hostname is detected from post old_id meta.
	 *
	 * @group list-previously-migrated-source-hostnames-command
	 */
	public function test_should_detect_hostname_from_post_meta(): void {
		// Create a local post with old_id meta.
		$post_id  = self::factory()->post->create();
		$meta_key = $this->get_old_id_meta_key();
		update_post_meta( $post_id, $meta_key, 12345 );

		$hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertContains( $this->source_hostname, $hostnames, 'Should detect hostname from post meta.' );
	}

	/**
	 * Tests that hostname is detected from user old_id meta.
	 *
	 * @group list-previously-migrated-source-hostnames-command
	 */
	public function test_should_detect_hostname_from_user_meta(): void {
		// Create a local user with old_id meta.
		$user_id  = wp_insert_user(
			[
				'user_login' => 'test_hostname_user',
				'user_email' => 'test_hostname@test.local',
				'user_pass'  => 'password',
			]
		);
		$meta_key = $this->get_old_id_meta_key();
		update_user_meta( $user_id, $meta_key, 12345 );

		$hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertContains( $this->source_hostname, $hostnames, 'Should detect hostname from user meta.' );
	}

	/**
	 * Tests that multiple hostnames are returned when content from different sources exists.
	 *
	 * @group list-previously-migrated-source-hostnames-command
	 */
	public function test_should_return_multiple_hostnames_from_different_sources(): void {
		// Create posts with different source hostnames.
		$post1_id = self::factory()->post->create();
		$post2_id = self::factory()->post->create();

		$meta_key_1 = 'newspackcontentdiff_oldid_source-one.example.com';
		$meta_key_2 = 'newspackcontentdiff_oldid_source-two.example.com';

		update_post_meta( $post1_id, $meta_key_1, 1001 );
		update_post_meta( $post2_id, $meta_key_2, 2001 );

		$hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertCount( 2, $hostnames, 'Should return two hostnames.' );
		$this->assertContains( 'source-one.example.com', $hostnames, 'Should contain first source hostname.' );
		$this->assertContains( 'source-two.example.com', $hostnames, 'Should contain second source hostname.' );
	}

	/**
	 * Tests that hostnames are unique even when multiple objects have the same source.
	 *
	 * @group list-previously-migrated-source-hostnames-command
	 */
	public function test_should_return_unique_hostnames(): void {
		// Create multiple posts with same source hostname.
		$post1_id = self::factory()->post->create();
		$post2_id = self::factory()->post->create();
		$post3_id = self::factory()->post->create();

		$meta_key = 'newspackcontentdiff_oldid_same-source.example.com';
		update_post_meta( $post1_id, $meta_key, 1001 );
		update_post_meta( $post2_id, $meta_key, 1002 );
		update_post_meta( $post3_id, $meta_key, 1003 );

		$hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertCount( 1, $hostnames, 'Should return only one unique hostname.' );
		$this->assertContains( 'same-source.example.com', $hostnames, 'Should contain the source hostname.' );
	}

	/**
	 * Tests that hostnames from both posts and users are combined.
	 *
	 * @group list-previously-migrated-source-hostnames-command
	 */
	public function test_should_combine_hostnames_from_posts_and_users(): void {
		// Create post with one source.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'newspackcontentdiff_oldid_posts-source.example.com', 1001 );

		// Create user with different source.
		$user_id = wp_insert_user(
			[
				'user_login' => 'test_combined_user',
				'user_email' => 'combined@test.local',
				'user_pass'  => 'password',
			]
		);
		update_user_meta( $user_id, 'newspackcontentdiff_oldid_users-source.example.com', 2001 );

		$hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertCount( 2, $hostnames, 'Should return two hostnames from different sources.' );
		$this->assertContains( 'posts-source.example.com', $hostnames, 'Should contain posts source hostname.' );
		$this->assertContains( 'users-source.example.com', $hostnames, 'Should contain users source hostname.' );
	}

	/**
	 * Tests that same hostname from posts and users is deduplicated.
	 *
	 * @group list-previously-migrated-source-hostnames-command
	 */
	public function test_should_deduplicate_hostname_across_posts_and_users(): void {
		$shared_hostname = 'shared-source.example.com';
		$meta_key        = 'newspackcontentdiff_oldid_' . $shared_hostname;

		// Create post with shared source.
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, $meta_key, 1001 );

		// Create user with same source.
		$user_id = wp_insert_user(
			[
				'user_login' => 'test_dedup_user',
				'user_email' => 'dedup@test.local',
				'user_pass'  => 'password',
			]
		);
		update_user_meta( $user_id, $meta_key, 2001 );

		$hostnames = $this->logic->get_migrated_source_hostnames();
		$this->assertCount( 1, $hostnames, 'Should return only one unique hostname even when in both posts and users.' );
		$this->assertContains( $shared_hostname, $hostnames, 'Should contain the shared source hostname.' );
	}
}
