<?php
/**
 * Integration tests for command cmd_migrate_live_content, user migration.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;
use Newspack\ContentDiffMigrator\Logic\RunState;

/**
 * Integration test class for command cmd_migrate_live_content, user migration.
 *
 * @group integration
 */
class CmdMigrateLiveContentUsersTest extends IntegrationTestCase {
	/**
	 * @group users
	 */
	public function test_should_migrate_all_live_users_to_local(): void {
		global $wpdb;

		// Create multiple users in live DB.
		$users = [
			$this->create_user_fixture(
				[
					'ID'         => 101,
					'user_login' => 'author1',
					'user_email' => 'author1@test.local',
				] 
			),
			$this->create_user_fixture(
				[
					'ID'         => 102,
					'user_login' => 'author2',
					'user_email' => 'author2@test.local',
				] 
			),
			$this->create_user_fixture(
				[
					'ID'         => 103,
					'user_login' => 'author3',
					'user_email' => 'author3@test.local',
				] 
			),
		];
		foreach ( $users as $user ) {
			$wpdb->insert( $this->live_table_prefix . 'users', $user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		// Also create a post so search command has something to find.
		$post = $this->create_post_fixture(
			[
				'ID'          => 3001,
				'post_author' => 101,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Run migration (includes migrate_all_users).
		$this->run_search_command();
		$this->run_migrate_command();

		// Verify all users were created.
		$this->assertNotFalse( get_user_by( 'login', 'author1' ), 'author1 should be created.' );
		$this->assertNotFalse( get_user_by( 'login', 'author2' ), 'author2 should be created.' );
		$this->assertNotFalse( get_user_by( 'login', 'author3' ), 'author3 should be created.' );
	}

	/**
	 * @group users
	 */
	public function test_should_skip_user_migration_when_user_already_exists_locally(): void {
		global $wpdb;

		// Create user locally first.
		$existing_user_id = wp_insert_user(
			[
				'user_login' => 'existinguser',
				'user_email' => 'existing@test.local',
				'user_pass'  => 'password123',
			]
		);

		// Create same user (by login) in live DB.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 201,
				'user_login' => 'existinguser',
				'user_email' => 'different@test.local', // Different email.
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post to trigger migration.
		$post = $this->create_post_fixture(
			[
				'ID'          => 3002,
				'post_author' => 201,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// First, run attribution to assign old_id meta to existing local content.
		$this->run_attribute_command();

		// Verify old_id meta was set on existing user by attribution.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $existing_user_id, $meta_key, true );
		$this->assertEquals( 201, (int) $old_id, 'Existing user should have old_id meta after attribution.' );

		// Now run migration.
		$this->run_search_command();
		$this->run_migrate_command();

		// Verify existing user was used (not duplicated).
		$users = get_users( [ 'login' => 'existinguser' ] );
		$this->assertCount( 1, $users, 'Should not create duplicate user.' );

		// Verify the post uses the existing user as author.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 3002, $this->source_hostname );
		$new_post    = get_post( $new_post_id );
		$this->assertEquals( $existing_user_id, (int) $new_post->post_author, 'Post should use existing local user as author.' );
	}

	/**
	 * @group users
	 */
	public function test_should_save_old_id_usermeta_for_newly_created_user(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 301,
				'user_login' => 'newuser301',
				'user_email' => 'newuser301@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with this user as author.
		$post = $this->create_post_fixture(
			[
				'ID'          => 3003,
				'post_author' => 301,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Find the newly created user.
		$user = get_user_by( 'login', 'newuser301' );
		$this->assertNotFalse( $user, 'User should be created.' );

		// Verify old_id meta.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $user->ID, $meta_key, true );
		$this->assertEquals( 301, (int) $old_id, 'New user should have old_id usermeta.' );
	}

	/**
	 * @group users
	 */
	public function test_should_migrate_usermeta_for_newly_created_user(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 401,
				'user_login' => 'usermetauser',
				'user_email' => 'usermeta@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Add usermeta.
		$usermeta = [
			[
				'user_id'    => 401,
				'meta_key'   => 'first_name',
				'meta_value' => 'John', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'user_id'    => 401,
				'meta_key'   => 'last_name',
				'meta_value' => 'Doe', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
			[
				'user_id'    => 401,
				'meta_key'   => 'description',
				'meta_value' => 'Bio text here.', // phpcs:ignore -- WordPress.DB.SlowDBQuery.slow_db_query_meta_value.
			],
		];
		foreach ( $usermeta as $meta ) {
			$wpdb->insert( $this->live_table_prefix . 'usermeta', $meta ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		}

		// Create post.
		$post = $this->create_post_fixture(
			[
				'ID'          => 3004,
				'post_author' => 401,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$user = get_user_by( 'login', 'usermetauser' );
		$this->assertNotFalse( $user, 'User should be created.' );

		// Verify usermeta was imported.
		$this->assertEquals( 'John', get_user_meta( $user->ID, 'first_name', true ) );
		$this->assertEquals( 'Doe', get_user_meta( $user->ID, 'last_name', true ) );
		$this->assertEquals( 'Bio text here.', get_user_meta( $user->ID, 'description', true ) );
	}

	/**
	 * @group users
	 */
	public function test_should_not_duplicate_user_when_running_migration_twice(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 501,
				'user_login' => 'nodupe501',
				'user_email' => 'nodupe@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 3005,
				'post_author' => 501,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Run migration twice.
		$this->run_search_command();
		$this->run_migrate_command();

		// Create new run-state for second run.
		$this->cleanup_temp_dir( $this->temp_data_dir );
		$this->run_state = new RunState( $this->temp_data_dir . '/run-state' );
		$this->command->set_run_state( $this->run_state );

		// Insert a new post to trigger second migration.
		$post2 = $this->create_post_fixture(
			[
				'ID'          => 3006,
				'post_author' => 501,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify only one user exists.
		$users = get_users( [ 'login' => 'nodupe501' ] );
		$this->assertCount( 1, $users, 'User should not be duplicated on second migration.' );
	}

	/**
	 * @group users
	 */
	public function test_should_assign_existing_author_when_user_login_matches(): void {
		global $wpdb;

		// Create user locally first.
		$local_user_id = wp_insert_user(
			[
				'user_login' => 'matchingauthor',
				'user_email' => 'match@test.local',
				'user_pass'  => 'password123',
			]
		);

		// Create same user in live DB.
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 601,
				'user_login' => 'matchingauthor',
				'user_email' => 'match@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with this author.
		$post = $this->create_post_fixture(
			[
				'ID'          => 4001,
				'post_author' => 601,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post uses existing user.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 4001, $this->source_hostname );
		$new_post    = get_post( $new_post_id );
		$this->assertEquals( $local_user_id, $new_post->post_author, 'Post should use existing local user as author.' );
	}

	/**
	 * @group users
	 */
	public function test_should_create_new_author_when_user_does_not_exist(): void {
		global $wpdb;

		// Create user only in live DB (not locally).
		$live_user = $this->create_user_fixture(
			[
				'ID'         => 701,
				'user_login' => 'newauthor701',
				'user_email' => 'newauthor701@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with this author.
		$post = $this->create_post_fixture(
			[
				'ID'          => 4002,
				'post_author' => 701,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify user was created.
		$new_user = get_user_by( 'login', 'newauthor701' );
		$this->assertNotFalse( $new_user, 'New author should be created.' );

		// Verify post uses new user.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 4002, $this->source_hostname );
		$new_post    = get_post( $new_post_id );
		$this->assertEquals( $new_user->ID, $new_post->post_author, 'Post should use newly created user as author.' );
	}

	/**
	 * @group users
	 */
	public function test_should_save_old_id_usermeta_for_new_author(): void {
		global $wpdb;

		$live_user = $this->create_user_fixture(
			[
				'ID'         => 801,
				'user_login' => 'authormeta801',
				'user_email' => 'authormeta@test.local',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'users', $live_user ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$post = $this->create_post_fixture(
			[
				'ID'          => 4003,
				'post_author' => 801,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		$new_user = get_user_by( 'login', 'authormeta801' );
		$this->assertNotFalse( $new_user, 'Author should be created.' );

		// Verify old_id usermeta.
		$meta_key = $this->get_old_id_meta_key();
		$old_id   = get_user_meta( $new_user->ID, $meta_key, true );
		$this->assertEquals( 801, (int) $old_id, 'New author should have old_id usermeta.' );
	}

	/**
	 * @group users
	 */
	public function test_should_handle_post_with_zero_author_id(): void {
		global $wpdb;

		// Create post with author ID 0.
		$post = $this->create_post_fixture(
			[
				'ID'          => 4004,
				'post_author' => 0,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was imported with author 0 or a default author.
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 4004, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post with author 0 should still be imported.' );

		$new_post = get_post( $new_post_id );
		// Author could be 0 or a default; the key is that import succeeds.
		$this->assertGreaterThanOrEqual( 0, $new_post->post_author, 'Post author should be valid.' );
	}

	/**
	 * @group users
	 */
	public function test_should_handle_post_with_invalid_author_id(): void {
		global $wpdb;

		// Create post with author ID that doesn't exist in live users table.
		$post = $this->create_post_fixture(
			[
				'ID'          => 4005,
				'post_author' => 99999,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command();
		$this->run_migrate_command();

		// Verify post was still imported (author might be set to 0 or default).
		$new_post_id = $this->logic->get_current_post_id_by_old_id( 4005, $this->source_hostname );
		$this->assertNotNull( $new_post_id, 'Post with invalid author should still be imported.' );
	}
}
