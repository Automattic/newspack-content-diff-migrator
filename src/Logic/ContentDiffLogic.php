<?php
/**
 * Content Diff migrator exports and imports the content differential from one site to the local site
 * while keeping the existing local content.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Logic;

use Newspack\ContentDiffMigrator\Utils\Logger;
use Newspack\ContentDiffMigrator\Utils\Progress;
use Psr\Log\LogLevel;
use RuntimeException;
use WP_User;
use wpdb;

/**
 * Core Content Diff logic.
 */
class ContentDiffLogic {

	/**
	 * Prefix for meta key with the old ID.
	 * Source hostname is appended, e.g. meta_key:
	 *  'newspackcontentdiff_oldid_www.example.com'
	 */
	const SAVED_META_LIVE_ID_PREFIX = 'newspackcontentdiff_oldid_';

	// Data array keys.
	const DATAKEY_POST              = 'post';
	const DATAKEY_POSTMETA          = 'postmeta';
	const DATAKEY_COMMENTS          = 'comments';
	const DATAKEY_COMMENTMETA       = 'commentmeta';
	const DATAKEY_USERS             = 'users';
	const DATAKEY_USERMETA          = 'usermeta';
	const DATAKEY_TERMRELATIONSHIPS = 'term_relationships';
	const DATAKEY_TERMTAXONOMY      = 'term_taxonomy';
	const DATAKEY_TERMS             = 'terms';
	const DATAKEY_TERMMETA          = 'termmeta';

	/**
	 * Global $wpdb.
	 *
	 * @var wpdb Global $wpdb.
	 */
	private wpdb $wpdb;

	/**
	 * BlockUpdater instance.
	 *
	 * @var BlockUpdater
	 */
	private BlockUpdater $block_updater;

	/**
	 * DataImporter instance.
	 *
	 * @var DataImporter
	 */
	private DataImporter $data_importer;

	/**
	 * DB utilities instance.
	 *
	 * @var DB
	 */
	private DB $db;

	/**
	 * ContentDiffMigrator constructor.
	 *
	 * @param wpdb $wpdb Global $wpdb.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb          = $wpdb;
		$this->block_updater = new BlockUpdater( [ $this, 'attachment_url_to_postid_resolver' ] );
		$this->data_importer = new DataImporter( $wpdb );
		$this->db            = new DB( $wpdb );
	}

	/**
	 * Gets the DataImporter instance.
	 *
	 * @return DataImporter
	 */
	public function get_data_importer(): DataImporter {
		return $this->data_importer;
	}

	/**
	 * Gets the source-specific meta key for storing old IDs.
	 *
	 * @param string $source_hostname Source hostname (e.g., 'www.example.com').
	 *
	 * @throws \InvalidArgumentException If source hostname is empty.
	 *
	 * @return string The full meta key (e.g., 'newspackcontentdiff_oldid_www.example.com').
	 */
	public static function get_old_id_meta_key( string $source_hostname ): string {
		if ( empty( $source_hostname ) ) {
			throw new \InvalidArgumentException( 'Source hostname is required.' );
		}

		// Sanitize hostname.
		$source_hostname = wp_parse_url( 'https://' . $source_hostname, PHP_URL_HOST );

		return self::SAVED_META_LIVE_ID_PREFIX . $source_hostname;
	}

	/**
	 * Gets all source hostnames from which content has been imported, by scanning meta keys.
	 *
	 * @return array List of source hostnames.
	 */
	public function get_migrated_source_hostnames(): array {
		// Get all distinct meta keys.
		// phpcs:disable -- WordPress.DB.PreparedSQL.NotPrepared.
		$like          = self::SAVED_META_LIVE_ID_PREFIX . '%';
		$postmeta_keys = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$this->wpdb->postmeta} WHERE meta_key LIKE %s",
				$like
			)
		);
		$usermeta_keys = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$this->wpdb->usermeta} WHERE meta_key LIKE %s",
				$like
			)
		);
		// phpcs:enable
		
		$postmeta_keys = is_array( $postmeta_keys ) ? $postmeta_keys : [];
		$usermeta_keys = is_array( $usermeta_keys ) ? $usermeta_keys : [];
		$all_keys      = array_unique( array_merge( $postmeta_keys, $usermeta_keys ) );

		// Get migrated source hostnames.
		$source_hostnames = [];
		foreach ( $all_keys as $key ) {
			$site = substr( $key, strlen( self::SAVED_META_LIVE_ID_PREFIX ) );
			if ( ! in_array( $site, $source_hostnames, true ) ) {
				$source_hostnames[] = $site;
			}
		}

		return $source_hostnames;
	}

	/**
	 * Gets records from the $posts_table, $post_types, returns the minimal set of columns needed to determine whether a post
	 * doesn't exist in DB and needs to be inserted, or has been modified and needs to be updated.
	 *
	 * @param string $posts_table   Name of posts table.
	 * @param array  $post_types    Post types to fetch.
	 * @param array  $post_statuses Post statuses to fetch.
	 *
	 * @return array Associative array with columns specified in used query.
	 */
	public function get_posts_rows_for_content_diff( string $posts_table, array $post_types, array $post_statuses ): array {
		// Get post types and statuses placeholders for $wpdb::prepare.
		$post_types_placeholders        = array_fill( 0, count( $post_types ), '%s' );
		$post_types_placeholders_csv    = implode( ',', $post_types_placeholders );
		$post_statuses_placeholders     = array_fill( 0, count( $post_statuses ), '%s' );
		$post_statuses_placeholders_csv = implode( ',', $post_statuses_placeholders );

		// $wpdb->prepare can't handle table names, so we'll additionally str_replace {TABLE}.
		// phpcs:disable
		$sql_replace_table = $this->wpdb->prepare(
			"SELECT ID, post_name, post_title, post_status, post_type, post_date, post_modified
				FROM {TABLE}
				WHERE post_type IN ( $post_types_placeholders_csv )
				AND post_status IN ( $post_statuses_placeholders_csv );",
			array_merge( $post_types, $post_statuses )
		);
		$posts_table_escaped = esc_sql( $posts_table );
		$results             = $this->wpdb->get_results(  str_replace( '{TABLE}', $posts_table_escaped, $sql_replace_table), ARRAY_A );

		// Return empty array instead of null.
		$results = is_null( $results ) ? [] : $results;

		return $results;
	}

	/**
	 * Gets a list of all Attachments imported by Content Diff, "old_id"=>"new_id" IDs mapping from the postmeta.
	 *
	 * @param string $source_hostname Source hostname.
	 *
	 * @return array Imported attachment IDs, keys are old/live IDs, values are new/local/Staging IDs.
	 */
	public function get_imported_attachment_id_map_from_db( string $source_hostname ): array {

		$attachment_ids_map = [];
		$meta_key           = $this->get_old_id_meta_key( $source_hostname );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT wpm.post_id, wpm.meta_value
					FROM {$this->wpdb->postmeta} wpm
					JOIN {$this->wpdb->posts} wp ON wp.ID = wpm.post_id
					WHERE wpm.meta_key = %s
					AND wp.post_type = 'attachment';",
				$meta_key,
			),
			ARRAY_A
		);
		foreach ( $results as $result ) {
			$attachment_ids_map[ $result['meta_value'] ] = $result['post_id'];
		}

		return $attachment_ids_map;
	}

	/**
	 * Gets an array of all Post IDs imported by Content Diff, their "old_id"=>"new_id" from the postmeta.
	 *
	 * @param string $source_hostname Source hostname.
	 * @param array  $post_types  Post types to include.
	 *
	 * @return array Imported post and pages IDs, keys are old/live IDs, values are new/local/Staging IDs.
	 */
	public function get_imported_post_id_mapping_from_db( string $source_hostname, array $post_types = [ 'post', 'page' ] ): array {

		$post_ids_map = [];
		$meta_key     = $this->get_old_id_meta_key( $source_hostname );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated dynamically.
		$post_types_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT wpm.post_id, wpm.meta_value
					FROM {$this->wpdb->postmeta} wpm
					JOIN {$this->wpdb->posts} wp ON wp.ID = wpm.post_id
					WHERE wpm.meta_key = %s
					AND wp.post_type IN ( {$post_types_placeholders} );",
				array_merge(
					[ $meta_key ],
					$post_types
				)
			),
			ARRAY_A
		);
		// phpcs:disable

		foreach ( $results as $result ) {
			$post_ids_map[ $result['meta_value'] ] = $result['post_id'];
		}

		return $post_ids_map;
	}

	/**
	 * Finds unique records in live posts table which don't exist in local posts table.
	 * Optimized to O(n+m) using a normalized composite key hash map.
	 *
	 * Outputs progress by 10% increments to the CLI.
	 *
	 * @param array $results_live_posts  Rows from live posts table.
	 * @param array $results_local_posts Rows from local posts table.
	 *
	 * @return array IDs of posts found.
	 */
	public function filter_new_live_ids( array $results_live_posts, array $results_local_posts ): array {
		// Search unique on live.
		$ids = [];

		// Build a lookup hash from local posts for O(1) lookup instead of O(n) search.
		// Use a hardened composite key derived from normalized fields.
		$local_posts_lookup = [];
		foreach ( $results_local_posts as $local_post ) {
			$lookup_key = $this->build_post_composite_key_for_post( $local_post );
			$local_posts_lookup[ $lookup_key ] = true;
		}

		$progress = new Progress( count( $results_live_posts ) );
		foreach ( $results_live_posts as $key_live_post => $live_post ) {

			// Output progress by 10%.
			if ( $progress_milestone = $progress->tick( $key_live_post + 1 ) ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( $progress_milestone ) );
			}

			// Use hash lookup instead of nested loop - O(1) instead of O(n).
			$lookup_key = $this->build_post_composite_key_for_post( $live_post );
			$found = isset( $local_posts_lookup[ $lookup_key ] );

			// Unique on live, add to $ids.
			if ( false === $found ) {
				$ids[] = (int) $live_post['ID'];
			}
		}
		if ( $progress->finish() ) {
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( 100 ) );
		}

		return $ids;
	}

	/**
	 * Finds records in live posts table which have a newer post_modified date.
	 * Optimized to O(n+m) using a normalized composite key hash map.
	 *
	 * Outputs progress by 10% increments to the CLI.
	 *
	 * @param array $results_live_posts  Rows from live posts table.
	 * @param array $results_local_posts Rows from local posts table.
	 *
	 * @return array {
	 *     Array of modified post ID pairs.
	 *
	 *     @type array $match {
	 *         @type int $live_id  Live post ID.
	 *         @type int $local_id Matching local post ID.
	 *     }
	 * }
	 */
	public function filter_modified_live_ids( array $results_live_posts, array $results_local_posts ): array {

		// Check if modified date is different. Posts which were already imported with Content Diff will have the original meta ID.
		// But posts which were imported just by raw table import won't have the meta. So a full comparisson is needed.
		$ids_modified = [];

		// Build a lookup hash from local posts for O(1) lookup instead of O(n) search.
		// Use a hardened composite key, store local ID and post_modified for comparison.
		$local_posts_lookup = [];
		foreach ( $results_local_posts as $local_post ) {
			$lookup_key = $this->build_post_composite_key_for_post( $local_post );
			// Store only the first match (original code breaks on first match).
			if ( ! isset( $local_posts_lookup[ $lookup_key ] ) ) {
				$local_posts_lookup[ $lookup_key ] = [
					'ID'            => $local_post['ID'],
					'post_modified' => $local_post['post_modified'],
				];
			}
		}

		$progress = new Progress( count( $results_live_posts ) );
		foreach ( $results_live_posts as $key_live_post => $live_post ) {

			// Output progress by 10%.
			if ( $progress_milestone = $progress->tick( $key_live_post + 1 ) ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( $progress_milestone ) );
			}

			// Use hash lookup instead of nested loop - O(1) instead of O(n).
			$lookup_key = $this->build_post_composite_key_for_post( $live_post );

			// Check if match exists and post_modified is newer on live.
			if ( isset( $local_posts_lookup[ $lookup_key ] ) ) {
				$local_post = $local_posts_lookup[ $lookup_key ];
				if ( $live_post['post_modified'] > $local_post['post_modified'] ) {
					$ids_modified[] = [
						'live_id'  => (int) $live_post['ID'],
						'local_id' => (int) $local_post['ID'],
					];
				}
			}
		}
		if ( $progress->finish() ) {
			Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::INFO, Progress::format( 100 ) );
		}

		return $ids_modified;
	}

	/**
	 * Fetches a Post and all core WP relational objects belonging to the post. Can fetch from a custom table prefix.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $table_prefix Table prefix to fetch from.
	 *
	 * @return array $args {
	 *     Post and all core WP Post-related data.
	 *
	 *     @type array $post              Contains `posts` row (DATAKEY_POST).
	 *     @type array $postmeta          Post's `postmeta` rows (DATAKEY_POSTMETA).
	 *     @type array $comments          Post's `comments` rows (DATAKEY_COMMENTS).
	 *     @type array $commentmeta       Post's `commentmeta` rows (DATAKEY_COMMENTMETA).
	 *     @type array $users             Post's `users` rows for Author and Comment Users (DATAKEY_USERS).
	 *     @type array $usermeta          Post's `usermeta` rows (DATAKEY_USERMETA).
	 *     @type array $term_relationships Post's `term_relationships` rows (DATAKEY_TERMRELATIONSHIPS).
	 *     @type array $term_taxonomy     Post's `term_taxonomy` rows (DATAKEY_TERMTAXONOMY).
	 *     @type array $terms             Post's `terms` rows (DATAKEY_TERMS).
	 *     @type array $termmeta          Post's `termmeta` rows (DATAKEY_TERMMETA).
	 * }
	 */
	public function get_post_data( int $post_id, string $table_prefix ): array {

		$data = $this->get_empty_data_array();

		// Get Post.
		$post_row                   = $this->select_post_row( $table_prefix, $post_id );
		$data[ self::DATAKEY_POST ] = $post_row;

		// Get Post Metas.
		$data[ self::DATAKEY_POSTMETA ] = $this->select_postmeta_rows( $table_prefix, $post_id );

		// Get Post Author User.
		$author_row                    = $this->select_user_row( $table_prefix, $data[ self::DATAKEY_POST ]['post_author'] );
		$data[ self::DATAKEY_USERS ][] = $author_row;

		// Get Post Author User Metas.
		if ( is_array( $author_row ) && array_key_exists( 'ID', $author_row ) ) {
			$data[ self::DATAKEY_USERMETA ] = array_merge(
				$data[ self::DATAKEY_USERMETA ],
				$this->select_usermeta_rows( $table_prefix, $author_row['ID'] )
			);
		}

		// Get Comments.
		if ( $post_row['comment_count'] > 0 ) {
			$comment_rows                   = $this->select_comment_rows( $table_prefix, $post_id );
			$data[ self::DATAKEY_COMMENTS ] = $comment_rows;

			// Get Comment Metas.
			foreach ( $comment_rows as $key_comment => $comment ) {
				$data[ self::DATAKEY_COMMENTMETA ] = array_merge(
					$data[ self::DATAKEY_COMMENTMETA ],
					$this->select_commentmeta_rows( $table_prefix, $comment['comment_ID'] )
				);

				// Get Comment User (if the same User was not already fetched).
				if ( $comment['user_id'] > 0 && empty( $this->filter_array_elements( $data[ self::DATAKEY_USERS ], 'ID', $comment['user_id'] ) ) ) {
					$comment_user_row              = $this->select_user_row( $table_prefix, $comment['user_id'] );
					if ( $comment_user_row ) {
						$data[ self::DATAKEY_USERS ][] = $comment_user_row;

						// Get Get Comment User Metas.
						$data[ self::DATAKEY_USERMETA ] = array_merge(
							$data[ self::DATAKEY_USERMETA ],
							$this->select_usermeta_rows( $table_prefix, $comment_user_row['ID'] )
						);
					}
				}
			}
		}

		// Get Term Relationships.
		$term_relationships_rows                 = $this->select_term_relationships_rows( $table_prefix, $post_id );
		$data[ self::DATAKEY_TERMRELATIONSHIPS ] = $term_relationships_rows;

		// Get all wp_term_taxonomy records.
		$term_ids                            = [];
		$keys_with_missing_term_taxonomy_ids = [];
		foreach ( $data[ self::DATAKEY_TERMRELATIONSHIPS ] as $key_termrelationship_row => $term_relationship_row ) {
			$term_taxonomy_id = $term_relationship_row['term_taxonomy_id'];
			$term_taxonomy    = $this->select_term_taxonomy_row( $table_prefix, $term_taxonomy_id );

			// In case that the term_taxonomy record is missing from Live DB for this $term_taxonomy_id.
			if ( is_null( $term_taxonomy ) ) {
				$keys_with_missing_term_taxonomy_ids[] = $key_termrelationship_row;
				continue;
			}

			$data[ self::DATAKEY_TERMTAXONOMY ][] = $term_taxonomy;
			$term_ids[]                           = $term_taxonomy['term_id'];
		}

		// Clean up $data[ self::DATAKEY_TERMRELATIONSHIPS ] in case some $term_taxonomy_id records are missing from live DB.
		if ( ! empty( $keys_with_missing_term_taxonomy_ids ) ) {
			foreach ( $keys_with_missing_term_taxonomy_ids as $key_with_missing_term_taxonomy_id ) {
				unset( $data[ self::DATAKEY_TERMRELATIONSHIPS ][ $key_with_missing_term_taxonomy_id ] );
			}
			$data[ self::DATAKEY_TERMRELATIONSHIPS ] = array_values( $data[ self::DATAKEY_TERMRELATIONSHIPS ] );
		}

		// Get Terms.
		$missing_term_ids = [];
		foreach ( $term_ids as $term_id ) {
			$term_row = $this->select_term_row( $table_prefix, $term_id );

			// In case some terms records are missing in Live DB.
			if ( is_null( $term_row ) || empty( $term_row ) ) {
				$missing_term_ids[] = $term_id;
				continue;
			}

			$data[ self::DATAKEY_TERMS ][] = $term_row;
		}

		// Get Term Metas.
		foreach ( $term_ids as $term_id ) {
			// Skip if term rows were missing.
			if ( in_array( $term_id, $missing_term_ids ) ) {
				continue;
			}

			$termmeta_rows = $this->select_termmeta_rows( $table_prefix, $term_id );
			if ( is_null( $termmeta_rows ) || empty( $termmeta_rows ) ) {
				continue;
			}

			$data[ self::DATAKEY_TERMMETA ] = array_merge(
				$data[ self::DATAKEY_TERMMETA ],
				$termmeta_rows
			);
		}

		return $data;
	}

	/**
	 * Migrates all users from Live tables to local tables.
	 *
	 * @param string $live_table_prefix Live DB table prefix.
	 * @param string $source_hostname   Source hostname.
	 *
	 * @return array Map of all newly inserted users. Keys are Live wp_user.ID's, and values are newly inserted user IDs.
	 *
	 * @throws RuntimeException If user insertion fails, gets thrown by insert_usermeta_row and insert_user.
	 */
	public function migrate_all_users( string $live_table_prefix, string $source_hostname ): array {
		// Keys are Live wp_user.IDs, and values are local user IDs (existing or newly inserted).
		$users_map = [];

		$users_rows = $this->select( $live_table_prefix . 'users', [], $select_just_one_row = false );
		foreach ( $users_rows as $user_row ) {
			$usermeta_rows = $this->select_usermeta_rows( $live_table_prefix, $user_row['ID'] );

			try {
				$user_id_local = $this->data_importer->get_or_create_user( $user_row, $usermeta_rows, $source_hostname );
				if ( ! is_null( $user_id_local ) ) {
					$users_map[ $user_row['ID'] ] = $user_id_local;
				} else {
					Logger::instance()->log_brief_and_verbose( LogLevel::ERROR, 'migrate_all_users live DB user row is invalid, skipping user', [ 'user_row' => $user_row ] );
				}
			} catch ( \Exception $e ) {
				Logger::instance()->log_brief_and_verbose( LogLevel::ERROR, sprintf( 'migrate_all_users get_or_create_user error: %s', $e->getMessage() ), [ 'user_row' => $user_row, 'usermeta_rows' => $usermeta_rows ] );
			}
		}

		return $users_map;
	}

	/**
	 * Matches local posts to live posts using composite key hash mapping.
	 * Similar pattern to filter_new_live_ids but returns local->live ID pairs.
	 *
	 * @param array $results_local_posts Rows from local posts table.
	 * @param array $results_live_posts  Rows from live posts table.
	 *
	 * @return array {
	 *     Array of matched post pairs with local_id and live_id.
	 *
	 *     @type array {
	 *         @type int $local_id Local post ID.
	 *         @type int $live_id  Live post ID.
	 *     }
	 * }
	 */
	public function match_local_to_live_posts( array $results_local_posts, array $results_live_posts ): array {
		$matches = [];

		// Get posts composite hashes, and compare them to find matches.
		
		// Get hashes for live posts.
		$live_posts_lookup = [];
		foreach ( $results_live_posts as $live_post ) {
			$lookup_key = $this->build_post_composite_key_for_post( $live_post );
			
			// Store composite key with live ID.
			if ( ! isset( $live_posts_lookup[ $lookup_key ] ) ) {
				$live_posts_lookup[ $lookup_key ] = (int) $live_post['ID'];
			}
		}

		// Get hashes for local posts, and compare them to find matches.
		foreach ( $results_local_posts as $key_local_post => $local_post ) {
			$lookup_key = $this->build_post_composite_key_for_post( $local_post );

			// Found a match.
			if ( isset( $live_posts_lookup[ $lookup_key ] ) ) {
				$matches[] = [
					'local_id' => (int) $local_post['ID'],
					'live_id'  => $live_posts_lookup[ $lookup_key ],
				];
			}
		}

		return $matches;
	}

	/**
	 * Gets user rows for attribution matching.
	 *
	 * @param string $table_prefix Table prefix (local or live).
	 *
	 * @return array Associative array with ID and user_login.
	 */
	public function get_users_rows_for_attribution( string $table_prefix ): array {
		$users_table = esc_sql( $table_prefix . 'users' );

		// phpcs:disable -- WordPress.DB.PreparedSQL.InterpolatedNotPrepared.
		$results = $this->wpdb->get_results(
			"SELECT ID, user_login FROM {$users_table}",
			ARRAY_A
		);
		// phpcs:enable

		return is_null( $results ) ? [] : $results;
	}

	/**
	 * Matches local users to live users by user_login (unique identifier).
	 *
	 * @param array $results_local_users Rows from local users table.
	 * @param array $results_live_users  Rows from live users table.
	 *
	 * @return array {
	 *     Array of matched user pairs with local_id and live_id.
	 *
	 *     @type array $match {
	 *         @type int $local_id Local user ID.
	 *         @type int $live_id  Live user ID.
	 *     }
	 * }
	 */
	public function match_local_to_live_users( array $results_local_users, array $results_live_users ): array {
		$matched_users = [];

		// Live users lookup by user_login.
		$live_users_lookup = [];
		foreach ( $results_live_users as $live_user ) {
			$live_users_lookup[ $live_user['user_login'] ] = (int) $live_user['ID'];
		}

		foreach ( $results_local_users as $local_user ) {
			// User is matched.
			if ( isset( $live_users_lookup[ $local_user['user_login'] ] ) ) {
				$matched_users[] = [
					'local_id' => (int) $local_user['ID'],
					'live_id'  => $live_users_lookup[ $local_user['user_login'] ],
				];
			}
		}

		return $matched_users;
	}

	/**
	 * Imports a single post from the live database.
	 *
	 * Fetches post data, inserts the post, imports all related data (meta, author, comments, taxonomies),
	 * and saves the source-specific old ID meta.
	 *
	 * @param int    $id_live                               Live post ID to import.
	 * @param string $live_table_prefix     Live database table prefix.
	 * @param array  $taxonomies_to_migrate List of taxonomies allowed to be migrated.
	 * @param string $source_hostname       Source hostname for meta key.
	 *
	 * @throws \RuntimeException If post insertion fails.
	 *
	 * @return array {
	 *     Import result data.
	 *
	 *     @type string $post_type Post type of the imported post.
	 *     @type int    $id_old    Original live post ID.
	 *     @type int    $id_new    New local post ID.
	 * }
	 */
	public function import_single_post(
		int $id_live,
		string $live_table_prefix,
		array $taxonomies_to_migrate,
		string $source_hostname
	): array {
		// Get all post data from live DB.
		$post_data = $this->get_post_data( $id_live, $live_table_prefix );
		$post_type = $post_data[ self::DATAKEY_POST ]['post_type'];

		// Insert the post row to get the new ID.
		$post_id_new = $this->insert_post( $post_data[ self::DATAKEY_POST ] );

		// Import all related post data (meta, author, comments, taxonomies). Errors are logged directly by DataImporter.
		$this->data_importer->import_post_data( $post_id_new, $post_data, $live_table_prefix, $taxonomies_to_migrate, $source_hostname );

		// Save source-specific old ID meta.
		$meta_key     = self::get_old_id_meta_key( $source_hostname );
		$meta_updated = update_post_meta( $post_id_new, $meta_key, $id_live );
		if ( false === $meta_updated ) {
			$context = [
				'id_new'   => $post_id_new,
				'id_old'   => $id_live,
				'meta_key' => $meta_key,
			];
			Logger::instance()->log_brief_and_verbose( LogLevel::ERROR, sprintf( 'Failed to save old_id postmeta for imported post %d which may cause duplicate imports on resume. DB error: %s', $post_id_new, $this->wpdb->last_error ), $context );
			throw new \RuntimeException( sprintf( 'Critical: Failed to save old_id postmeta for post %d', esc_html( $post_id_new ) ) );
		}

		return [
			'post_type' => $post_type,
			'id_old'    => $id_live,
			'id_new'    => $post_id_new,
		];
	}

	/**
	 * Updates Post's post_parent ID.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $new_parent_id New post_parent ID for this post.
	 */
	public function update_post_parent( int $post_id, int $new_parent_id ): void {
		$updated = $this->wpdb->update( $this->wpdb->posts, [ 'post_parent' => $new_parent_id ], [ 'ID' => $post_id ] );
		if ( false === $updated ) {
			$context = [
				'post_id'       => $post_id,
				'new_parent_id' => $new_parent_id,
			];
			Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::ERROR, sprintf( 'Failed to update post_parent for post ID %d. DB error: %s', $post_id, $this->wpdb->last_error ), $context );
		}
	}

	/**
	 * Updates a single Post's featured image ID with new ID after insertion.
	 *
	 * @param int   $post_id                     Local Post ID.
	 * @param array $imported_attachment_ids_map Keys are IDs on Live Site, values are IDs of imported posts on Local Site.
	 */
	public function update_featured_image( int $post_id, array $imported_attachment_ids_map ): void {
		if ( empty( $imported_attachment_ids_map ) ) {
			return;
		}

		// Get Post's current _thumbnail_id.
		// phpcs:disable
		$current_thumbnail_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT meta_value
				FROM {$this->wpdb->postmeta}
				WHERE meta_key = '_thumbnail_id'
				AND post_id = %d",
				$post_id
			)
		);
		// phpcs:enable
		if ( ! $current_thumbnail_id ) {
			return;
		}

		// Get the new _thumbnail_id.
		$new_thumbnail_id = $imported_attachment_ids_map[ $current_thumbnail_id ] ?? null;
		if ( is_null( $new_thumbnail_id ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$updated = $this->wpdb->update(
			$this->wpdb->postmeta,
			[ 'meta_value' => $new_thumbnail_id ],
			[
				'post_id'  => $post_id,
				'meta_key' => '_thumbnail_id',
			]
		);
		// phpcs:enable

		// Log to file only.
		if ( false != $updated && $updated > 0 ) {
			Logger::instance()->log(
				Logger::OUTPUT_FILE,
				LogLevel::DEBUG,
				'Featured image updated',
				[
					'post_id'           => $post_id,
					'_thumbnail_id_old' => (int) $current_thumbnail_id,
					'_thumbnail_id_new' => (int) $new_thumbnail_id,
				]
			);
		}
	}

	/**
	 * Updates Gutenberg Blocks' attachment IDs with new attachment IDs in a single post's `post_content` and `post_excerpt` fields.
	 *
	 * @param int   $post_id                      The post ID to update.
	 * @param array $known_attachment_ids_updates An array of known Attachment IDs which were updated; keys are old IDs, values are new IDs.
	 * @param array $local_hostname_aliases       An array of image hostnames to be looked up as local. Explanation via an example --
	 *                                            let's take hostname.com and a local image https://hostname.com/wp-content/2022/09/22/a.jpg
	 *                                            as local image. Searching for this image's attachment ID will work just fine using
	 *                                            the full URL. But perhaps if this site is using an S3 bucket, and if some of
	 *                                            the URLs in post_content use https://hostname.s3.amazonaws.com/wp-content/uploads/2022/09/22/a.jpg
	 *                                            we should then add value 'hostname.s3.amazonaws.com' in this array here, so that
	 *                                            \attachment_url_to_postid can query the attachment ID by treating this S3 hostname
	 *                                            as an alias of the local one.
	 */
	public function update_blocks_ids( int $post_id, array $known_attachment_ids_updates, array $local_hostname_aliases = [] ): void {
		if ( empty( $known_attachment_ids_updates ) ) {
			return;
		}

		// Filter the $local_hostname_aliases argument -- remove the local host if the user entered it, just leaving additional hostname aliases here.
		if ( ! empty( $local_hostname_aliases ) ) {
			$siteurl_parsed     = wp_parse_url( get_option( 'siteurl' ) );
			$local_hostname     = $siteurl_parsed['host'];
			$key_local_hostname = array_search( $local_hostname, $local_hostname_aliases );
			if ( false !== $key_local_hostname ) {
				unset( $local_hostname_aliases[ $key_local_hostname ] );
			}
		}

		// Fetch the post.
		// phpcs:disable -- Query is correctly prepared. WordPress.DB.PreparedSQL.NotPrepared.
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT ID, post_content, post_excerpt FROM {$this->wpdb->posts} WHERE ID = %d;",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! $result ) {
			return;
		}

		$content_before  = $result['post_content'];
		$content_updated = $result['post_content'];
		$excerpt_before  = $result['post_excerpt'];
		$excerpt_updated = $result['post_excerpt'];

		// Update all block types using BlockUpdater.
		$content_updated = $this->block_updater->update_all_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
		$excerpt_updated = $this->block_updater->update_all_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );

		// Persist.
		if ( $content_before != $content_updated || $excerpt_before != $excerpt_updated ) {
			$updated = $this->wpdb->update(
				$this->wpdb->posts,
				[
					'post_content' => $content_updated,
					'post_excerpt' => $excerpt_updated,
				],
				[ 'ID' => $post_id ]
			);
			if ( false === $updated ) {
				$context = [
					'post_id'  => $post_id,
					'db_error' => $this->wpdb->last_error,
				];
				Logger::instance()->log_brief_and_verbose( LogLevel::ERROR, sprintf( 'Failed to update blocks for post ID %d. DB error: %s', $post_id, $this->wpdb->last_error ), $context );
			}
		}

		// Log detailed updates to file only.
		$log_context = [ 'id_new' => $post_id ];
		if ( $content_before != $content_updated ) {
			$log_context = array_merge(
				$log_context,
				[
					'post_content_before' => $content_before,
					'post_content_after'  => $content_updated,
				]
			);
		}
		if ( $excerpt_before != $excerpt_updated ) {
			$log_context = array_merge(
				$log_context,
				[
					'post_excerpt_before' => $excerpt_before,
					'post_excerpt_after'  => $excerpt_updated,
				]
			);
		}
		Logger::instance()->log( Logger::OUTPUT_FILE, LogLevel::DEBUG, sprintf( 'Updated block attachment IDs in content and excerpt for post ID %d.', $post_id ), $log_context );
	}

	/**
	 * Returns an empty data array.
	 *
	 * @return array $args {
	 *     And empty array with structured keys which will contain all Post and Post-related data.
	 *
	 *     @type array self::DATAKEY_POST              Contains `posts` row.
	 *     @type array self::DATAKEY_POSTMETA          Post's `postmeta` rows.
	 *     @type array self::DATAKEY_COMMENTS          Post's `comments` rows.
	 *     @type array self::DATAKEY_COMMENTMETA       Post's `commentmeta` rows.
	 *     @type array self::DATAKEY_USERS             Post's `users` rows (for the Post Author, and the Comment Users).
	 *     @type array self::DATAKEY_USERMETA          Post's `usermeta` rows.
	 *     @type array self::DATAKEY_TERMRELATIONSHIPS Post's `term_relationships` rows.
	 *     @type array self::DATAKEY_TERMTAXONOMY      Post's `term_taxonomy` rows.
	 *     @type array self::DATAKEY_TERMS             Post's `terms` rows.
	 * }
	 */
	private function get_empty_data_array(): array {
		return [
			self::DATAKEY_POST              => [],
			self::DATAKEY_POSTMETA          => [],
			self::DATAKEY_COMMENTS          => [],
			self::DATAKEY_COMMENTMETA       => [],
			self::DATAKEY_USERS             => [],
			self::DATAKEY_USERMETA          => [],
			self::DATAKEY_TERMRELATIONSHIPS => [],
			self::DATAKEY_TERMTAXONOMY      => [],
			self::DATAKEY_TERMS             => [],
			self::DATAKEY_TERMMETA          => [],
		];
	}

	/**
	 * Checks if a Term Taxonomy exists.
	 *
	 * @param int    $term_id  term_id.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return int|null term_taxonomy_id or null.
	 */
	public function get_existing_term_taxonomy( int $term_id, string $taxonomy ): ?int {
		// phpcs:disable -- wpdb::prepare used by wrapper.
		$var = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT tt.term_taxonomy_id
			FROM {$this->wpdb->term_taxonomy} tt
			WHERE tt.term_id = %d
			AND tt.taxonomy = %s;",
				$term_id,
				$taxonomy
			)
		);
		// phpcs:enable

		return is_numeric( $var ) ? (int) $var : $var;
	}

	/**
	 * Selects a row from the posts table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $post_id      Post ID.
	 *
	 * @return array|null Associative array return from $wpdb::get_row, or null if no results.
	 */
	public function select_post_row( string $table_prefix, int $post_id ): ?array {
		return $this->select( $table_prefix . 'posts', [ 'ID' => $post_id ], $select_just_one_row = true );
	}

	/**
	 * Selects rows from the postmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $post_id      Post ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_postmeta_rows( string $table_prefix, int $post_id ): array {
		return $this->select( $table_prefix . 'postmeta', [ 'post_id' => $post_id ] );
	}

	/**
	 * Selects a row from the users table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $user_id      User ID.
	 *
	 * @return array|null Associative array return from $wpdb::get_row, or null if no results.
	 */
	public function select_user_row( string $table_prefix, int $user_id ): ?array {
		return $this->select( $table_prefix . 'users', [ 'ID' => $user_id ], $select_just_one_row = true );
	}

	/**
	 * Selects rows from the usermeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $user_id      User ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_usermeta_rows( string $table_prefix, int $user_id ): array {
		return $this->select( $table_prefix . 'usermeta', [ 'user_id' => $user_id ] );
	}

	/**
	 * Selects rows from the comments table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $post_id Post ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_comment_rows( string $table_prefix, int $post_id ): array {
		return $this->select( $table_prefix . 'comments', [ 'comment_post_ID' => $post_id ] );
	}

	/**
	 * Selects rows from the commentmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $comment_id Comment ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_commentmeta_rows( string $table_prefix, int $comment_id ): array {
		return $this->select( $table_prefix . 'commentmeta', [ 'comment_id' => $comment_id ] );
	}

	/**
	 * Selects rows from the term_relationships table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $post_id Post ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_term_relationships_rows( string $table_prefix, int $post_id ): array {
		return $this->select( $table_prefix . 'term_relationships', [ 'object_id' => $post_id ] );
	}

	/**
	 * Selects a row from the term_taxonomy table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $term_taxonomy_id term_taxonomy_id.
	 *
	 * @return array|null Associative array return from $wpdb::get_row, or null if no results.
	 */
	public function select_term_taxonomy_row( string $table_prefix, int $term_taxonomy_id ): ?array {
		return $this->select( $table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $term_taxonomy_id ], $select_just_one_row = true );
	}

	/**
	 * Selects a row from the terms table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $term_id Term ID.
	 *
	 * @return array|null Associative array return from $wpdb::get_row, or null if no results.
	 */
	public function select_term_row( string $table_prefix, int $term_id ): ?array {
		return $this->select( $table_prefix . 'terms', [ 'term_id' => $term_id ], $select_just_one_row = true );
	}

	/**
	 * Selects rows from the termmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $term_id Term ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_termmeta_rows( string $table_prefix, int $term_id ): array {
		return $this->select( $table_prefix . 'termmeta', [ 'term_id' => $term_id ] );
	}

	/**
	 * Simple reusable select query with custom `where` conditions.
	 *
	 * @param string $table_name          Table name to select from.
	 * @param array  $where_conditions    Keys are columns, values are their values.
	 * @param bool   $select_just_one_row Select just one row will use wpdb::get_row. Default is false which uses wpdb::get_results.
	 *
	 * @return array|null wpdb results in associative array form. If $select_just_one_row is used, the result is an array or null.
	 *                    Otherwise, the result is an array with subarray rows, or an empty array.
	 */
	private function select( string $table_name, array $where_conditions, bool $select_just_one_row = false ): ?array {
		$sql = 'SELECT * FROM ' . esc_sql( $table_name );

		if ( ! empty( $where_conditions ) ) {
			$where_sprintf = '';
			foreach ( $where_conditions as $column => $value ) {
				$where_sprintf .= ( ! empty( $where_sprintf ) ? ' AND' : '' )
									. ' ' . esc_sql( $column ) . ' = %s';
			}
			$where_sprintf = ' WHERE' . $where_sprintf;
			$sql_sprintf   = $sql . $where_sprintf;

			// phpcs:ignore -- $sql_sprintf is escaped.
			$sql = $this->wpdb->prepare( $sql_sprintf, array_values( $where_conditions ) );
		}

		if ( true === $select_just_one_row ) {
			// phpcs:ignore -- $wpdb::prepare was used above.
			return $this->wpdb->get_row( $sql, ARRAY_A );
		} else {
			// phpcs:ignore -- $wpdb::prepare was used above.
			return $this->wpdb->get_results( $sql, ARRAY_A );
		}
	}

	/**
	 * Inserts Post.
	 *
	 * @param array $post_row `post` row.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted Post ID.
	 */
	public function insert_post( array $post_row ): int {
		$insert_post_row = $post_row;
		$orig_id         = $insert_post_row['ID'];
		unset( $insert_post_row['ID'] );

		$inserted = $this->wpdb->insert( $this->wpdb->posts, $insert_post_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting post, ID %d, post row %s', $orig_id, wp_json_encode( $post_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts into `terms` table.
	 *
	 * @param array $term_row `term` row.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted term_id.
	 */
	public function insert_term( array $term_row ): int {
		$insert_term_row = $term_row;
		if ( isset( $insert_term_row['term_id'] ) ) {
			unset( $insert_term_row['term_id'] );
		}

		$inserted = $this->wpdb->insert( $this->wpdb->terms, $insert_term_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term, $term_row %s', wp_json_encode( $term_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Finds current post ID by old live DB ID, by searching for a source-specific post meta.
	 *
	 * @param string     $meta_key   Name of postmeta which contains old post ID (use get_old_id_meta_key()).
	 * @param int|string $meta_value Post ID from live DB.
	 *
	 * @return string|null Current Post ID.
	 */
	public function get_post_id_by_postmeta( string $meta_key, int|string $meta_value ): ?string {

		// phpcs:disable -- wpdb::prepare is used correctly.
		$post_id_new = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT post_id
			FROM {$this->wpdb->postmeta}
			WHERE meta_key = %s
			AND meta_value = %s",
				$meta_key,
				$meta_value
			)
		);
		// phpcs:enable

		return $post_id_new;
	}

	/**
	 * Finds a local post ID by live DB's ID, by comparing the live DB post record to the local DB.
	 *
	 * @param int    $id_live           Old live site's post object ID.
	 * @param string $live_table_prefix Live DB tables' prefix.
	 *
	 * @return int|null Current Post ID.
	 */
	public function get_current_post_id_by_comparing_with_live_db( int $id_live, string $live_table_prefix ): ?string {

		$live_posts_table = $live_table_prefix . 'posts';
		$posts_table      = $this->wpdb->posts;

		// phpcs:disable -- wpdb::prepare is used correctly.
		$post_id_new = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT wp.ID
			FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
				AND wp.post_type = lwp.post_type
			WHERE lwp.ID = %d ;",
				$id_live
			)
		);
		// phpcs:enable

		return $post_id_new;
	}

	/**
	 * Wrapper for WP's native \attachment_url_to_postid() with support for local hostname aliases.
	 *
	 * @param string $url                    The URL to resolve.
	 * @param array  $local_hostname_aliases Array of hostnames to use as local hostname aliases.
	 *
	 * @return int The found post ID, or 0 on failure.
	 */
	public function attachment_url_to_postid_resolver( string $url, array $local_hostname_aliases = [] ): int {

		// If $url hostname has one of the given aliases, substitute its hostname with the local hostname.
		if ( ! empty( $local_hostname_aliases ) ) {
			$parsed_url = wp_parse_url( $url );
			if ( in_array( $parsed_url['host'], $local_hostname_aliases ) ) {
				$siteurl        = get_option( 'siteurl' );
				$siteurl_parsed = wp_parse_url( $siteurl );

				$url = str_replace( '//' . $parsed_url['host'], '//' . $siteurl_parsed['host'], $url );
			}
		}

		// phpcs:ignore
		$post_id = \attachment_url_to_postid( $url );

		return $post_id;
	}

	/**
	 * Filters a multidimensional array and searches for all subarray elemens containing a key and value.
	 *
	 * @param array $data  Array being searched and filtered.
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for.
	 *
	 * @return array An array with sub-arrays which match the $key $value filter, or an empty array if nothing is found.
	 */
	public function filter_array_elements( array $data, mixed $key, mixed $value ): array {
		$found = [];
		foreach ( $data as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				$found[] = $subarray;
			}
		}

		return $found;
	}

	/**
	 * Builds a hardened composite key from a post-like associative array and a list of fields.
	 *
	 * - Applies normalization: cast to string, trim whitespace, lowercase for case-insensitive match.
	 * - Missing fields are treated as empty strings.
	 * - Uses json encoding and md5 to avoid delimiter collision and keep the key compact.
	 *
	 * @param array $post   Associative array with post fields.
	 * @param array $fields Ordered list of field names to include in the key.
	 *
	 * @return string Composite key.
	 */
	private function build_post_composite_key( array $post, array $fields ): string {
		$normalized = [];
		foreach ( $fields as $field ) {
			$value        = isset( $post[ $field ] ) ? $post[ $field ] : '';
			$value        = is_scalar( $value ) ? (string) $value : '';
			$value        = trim( $value );
			$value        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
			$normalized[] = $value;
		}

		return md5( wp_json_encode( $normalized ) );
	}

	/**
	 * Builds a composite key for posts, using the standard post field sets for comparison.
	 *
	 * @param array $post Associative array with post fields.
	 *
	 * @return string Composite key.
	 */
	private function build_post_composite_key_for_post( array $post ): string {
		return $this->build_post_composite_key( $post, [ 'post_name', 'post_title', 'post_type', 'post_status', 'post_date' ] );
	}
}
