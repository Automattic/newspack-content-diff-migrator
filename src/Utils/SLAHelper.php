<?php
/**
 * Simple Local Avatars helper utility.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Utils;

use wpdb;
use WP_Error;

/**
 * Helper class for Simple Local Avatars.
 */
class SLAHelper {

	const AVATAR_META_KEY = 'simple_local_avatar';

	/**
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * @param wpdb $wpdb Global $wpdb.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Gets avatar meta for all users from a given table prefix.
	 *
	 * @param string $table_prefix Table prefix (local or live).
	 *
	 * @return array Map of user_id => unserialized avatar meta array.
	 */
	public function get_avatar_meta_by_user_id( string $table_prefix ): array {
		// phpcs:disable -- WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching WordPress.DB.PreparedSQL.InterpolatedNotPrepared.
		$table_prefix_escaped = esc_sql( $table_prefix );
		$results              = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT user_id, meta_value FROM {$table_prefix_escaped}usermeta WHERE meta_key = %s",
				self::AVATAR_META_KEY 
			),
			ARRAY_A
		);
		// phpcs:enable

		$avatar_by_user = [];
		foreach ( $results as $row ) {
			$avatar_by_user[ (int) $row['user_id'] ] = maybe_unserialize( $row['meta_value'] );
		}
		return $avatar_by_user;
	}

	/**
	 * Sets a Simple Local Avatar for a user.
	 *
	 * @param int $user_id       User ID.
	 * @param int $attachment_id Attachment ID of the avatar image.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function set_user_avatar( int $user_id, int $attachment_id ): bool|WP_Error {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', sprintf( 'User ID %d does not exist.', $user_id ) );
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'invalid_attachment', sprintf( 'Attachment ID %d does not exist.', $attachment_id ) );
		}
		// Note: We intentionally skip wp_attachment_is_image() check here because:
		// 1. Migrated attachments may not have full metadata immediately
		// 2. We trust the source data - if it was an avatar on live, it's valid
		// 3. The attachment existing is sufficient validation for ID translation

		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( false === $attachment_url ) {
			return new WP_Error( 'invalid_url', sprintf( 'Could not get URL for attachment ID %d.', $attachment_id ) );
		}

		$meta_value = [
			'media_id' => $attachment_id,
			'full'     => $attachment_url,
			'blog_id'  => get_current_blog_id(),
		];

		$updated = update_user_meta( $user_id, self::AVATAR_META_KEY, $meta_value );
		
		// update_user_meta returns false if value is unchanged (not just on failure).
		// Verify it's actually an error by checking if the stored value differs.
		if ( false === $updated ) {
			$current_value = get_user_meta( $user_id, self::AVATAR_META_KEY, true );
			if ( $current_value !== $meta_value ) {
				return new WP_Error( 'update_failed', sprintf( 'Failed to update avatar for user ID %d.', $user_id ) );
			}
			// Value is already set correctly - treat as success.
		}

		return true;
	}
}
