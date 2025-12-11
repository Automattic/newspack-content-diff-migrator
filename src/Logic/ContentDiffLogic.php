<?php
/**
 * Content Diff migrator exports and imports the content differential from one site to the local site
 * while keeping the existing local content.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Logic;

use Newspack\ContentDiffMigrator\Utils\PHP as PHPUtil;
use NewspackContentConverter\ContentPatcher\ElementManipulators\HtmlElementManipulator;
use NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
use RuntimeException;
use WP_CLI;
use WP_User;
use wpdb;

/**
 * Core Content Diff logic.
 */
class ContentDiffLogic {

	/**
	 * Prefix for meta key with the old ID.
	 * Source hostname is appended, e.g. meta_key:
	 *  'newspackcontentdiff_live_id_www.example.com'
	 */
	const SAVED_META_LIVE_ID_PREFIX = 'newspackcontentdiff_live_id_';

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

	const CORE_WP_TABLES = [
		'commentmeta',
		'comments',
		'links',
		'options',
		'postmeta',
		'posts',
		'terms',
		'termmeta',
		'term_relationships',
		'term_taxonomy',
		'usermeta',
		'users',
	];

	/**
	 * Global $wpdb.
	 *
	 * @var wpdb Global $wpdb.
	 */
	private $wpdb;

	/**
	 * WpBlockManipulator.
	 *
	 * @var WpBlockManipulator.
	 */
	private $wp_block_manipulator;

	/**
	 * HtmlElementManipulator.
	 *
	 * @var HtmlElementManipulator
	 */
	private $html_element_manipulator;

	/**
	 * Crawler.
	 *
	 * @var Crawler.
	 */
	private $dom_crawler;

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
	 * ContentDiffMigrator constructor.
	 *
	 * @param object $wpdb Global $wpdb.
	 */
	public function __construct( object $wpdb ) {
		$this->wpdb                     = $wpdb;
		$this->wp_block_manipulator     = new WpBlockManipulator();
		$this->html_element_manipulator = new HtmlElementManipulator();
		$this->block_updater            = new BlockUpdater( [ $this, 'attachment_url_to_postid' ] );
		$this->data_importer            = new DataImporter( $wpdb );
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
	 * Gets the source-specific meta key for storing old IDs.
	 *
	 * @param string $source_hostname Source hostname (e.g., 'www.example.com').
	 *
	 * @throws \InvalidArgumentException If source hostname is empty.
	 *
	 * @return string The full meta key (e.g., 'newspackcontentdiff_live_id_www.example.com').
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
	 * Gets a diff of new Posts, Pages and Attachments from the Live Site.
	 *
	 * @param string $live_table_prefix Table prefix for the Live Site.
	 *
	 * @return     array Result from $wpdb->get_results.
	 * @throws     \RuntimeException Throws exception if any live tables do not match the collation of their corresponding Core WP DB table.
	 * @deprecated Since large JOINs can time out on Atomic, this was eprecated in favor of `get_posts_rows_for_content_diff` and
	 * `filter_new_live_ids`. And there's also the new `filter_modified_live_ids` method.
	 */
	public function get_live_diff_content_ids( $live_table_prefix ) {
		if ( ! $this->are_table_collations_matching( $live_table_prefix ) ) {
			throw new \RuntimeException( 'Table collations do not match for some (or all) WP tables.' );
		}

		$ids              = [];
		$live_posts_table = esc_sql( $live_table_prefix ) . 'posts';
		$posts_table      = $this->wpdb->prefix . 'posts';

		// Get all Posts and Pages except revisions and trashed items.
		$sql_posts = "SELECT lwp.ID FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
			WHERE lwp.post_type IN ( 'post', 'page' )
			AND lwp.post_status IN ( 'publish', 'future', 'draft', 'pending', 'private' )
			AND wp.ID IS NULL;";
		// phpcs:ignore -- no SQL parameters used.
		$results   = $this->wpdb->get_results( $sql_posts, ARRAY_A );
		foreach ( $results as $result ) {
			$ids[] = $result['ID'];
		}

		// Get attachments.
		$sql_attachments = "SELECT lwp.ID FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
			WHERE lwp.post_type IN ( 'attachment' )
			AND wp.ID IS NULL;";
		// phpcs:ignore -- no SQL parameters used.
		$results         = $this->wpdb->get_results( $sql_attachments, ARRAY_A );
		foreach ( $results as $result ) {
			$ids[] = $result['ID'];
		}

		return $ids;
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
	public function get_posts_rows_for_content_diff( string $posts_table, array $post_types, array $post_statuses ) {
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
	public function get_imported_attachment_id_mapping_from_db( string $source_hostname ): array {

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

		$percent_progress = null;
		foreach ( $results_live_posts as $key_live_post => $live_post ) {

			// Output progress meter by 10% increments.
			$last_percent_progress = $percent_progress;
			$this->get_progress_percentage( count( $results_live_posts ), $key_live_post + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			// Use hash lookup instead of nested loop - O(1) instead of O(n).
			$lookup_key = $this->build_post_composite_key_for_post( $live_post );
			$found = isset( $local_posts_lookup[ $lookup_key ] );

			// Unique on live, add to $ids.
			if ( false === $found ) {
				$ids[] = (int) $live_post['ID'];
			}
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
	 * @return array $ids_modified {
	 *     IDs of posts found.
	 *
	 *     @type int live_id  Live Post ID.
	 *     @type int local_id Matching Local Post ID.
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

		$percent_progress = null;
		foreach ( $results_live_posts as $key_live_post => $live_post ) {

			// Output progress meter by 10% increments.
			$last_percent_progress = $percent_progress;
			$this->get_progress_percentage( count( $results_live_posts ), $key_live_post + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
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
	public function get_post_data( $post_id, $table_prefix ) {

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
	 * Checks local Hierarchical Taxonomies, and returns those which might have wrong parent term_ids that don't exist.
	 *
	 * @param string $table_prefix DB table prefix which is to be used for this query.
	 * @param array  $taxonomies_to_check Hierarchical Taxonomies to check.
	 *
	 * @return array $args {
	 *     Hierarchical Taxonomies which have nonexistent parent term_id.
	 *
	 *     @type string term_id          wp_terms.term_id.
	 *     @type string name             wp_terms.name.
	 *     @type string slug             wp_terms.slug.
	 *     @type string term_taxonomy_id wp_termtaxonomy.term_taxonomy_id.
	 *     @type string taxonomy         wp_termtaxonomy.taxonomy, will be 'category'.
	 *     @type string parent           wp_termtaxonomy.parent which is not found in wp_terms and is wrong.
	 * }
	 */
	public function get_taxonomies_with_nonexistent_parents( $table_prefix, $taxonomies_to_check ): array {

		$terms         = esc_sql( $table_prefix . 'terms' );
		$term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		// phpcs:disable -- wpdb::prepare used by wrapper and query fully sanitized.
		$taxonomy_format = implode( ', ', array_fill( 0, count( $taxonomies_to_check ), '%s' ) );
		$taxonomies_with_nonexistent_parents = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.parent
				FROM {$terms} t
				JOIN {$term_taxonomy} tt
					ON t.term_id = tt.term_id AND tt.taxonomy IN ($taxonomy_format) AND parent <> 0
				LEFT JOIN {$terms} ttparent
					ON ttparent.term_id = tt.parent
				WHERE ttparent.term_id IS NULL;",
				$taxonomies_to_check
			)
			,
			ARRAY_A
		);
		// phpcs:enable

		return $taxonomies_with_nonexistent_parents;
	}

	/**
	 * Sets these wp_term_taxnomy.term_taxonomy_ids' parents to 0.
	 *
	 * @param string $table_prefix      DB table prefix.
	 * @param array  $term_taxonomy_ids term_taxonomy_ids.
	 *
	 * @return void
	 */
	public function reset_hierarchical_taxonomies_parents( string $table_prefix, array $term_taxonomy_ids ): void {
		$placeholders  = implode( ',', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );
		// phpcs:disable -- wpdb::prepare used by wrapper and query fully sanitized.
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$term_taxonomy} SET parent = 0 WHERE term_taxonomy_ID IN ( {$placeholders} );",
				$term_taxonomy_ids
			)
		);
		// phpcs:enable
	}

	/**
	 * Recreates all hierarchical or non-hierarchical taxonomies from Live to local.
	 *
	 * @param string $live_table_prefix Live DB table prefix.
	 * @param array  $hierarchical_taxonomies_to_migrate Hierarchical taxonomies to migrate.
	 *
	 * @return array Map of all live to local hierarchical taxonomies. Keys are live category term_ids, and values are their corresponding
	 *               local category term_ids.
	 */
	public function recreate_hierarchical_taxonomies( $live_table_prefix, $hierarchical_taxonomies_to_migrate ) {
		$table_prefix             = $this->wpdb->prefix;
		$live_terms_table         = esc_sql( $live_table_prefix . 'terms' );
		$live_termstaxonomy_table = esc_sql( $live_table_prefix . 'term_taxonomy' );

		// Get all live site's hierarchical hierarchical taxonomies, ordered by parent for easy hierarchical reconstruction.
		// phpcs:disable -- wpdb::prepare is used by wrapper.
		$taxonomy_format = implode( ', ', array_fill( 0, count( $hierarchical_taxonomies_to_migrate ), '%s' ) );
		$live_hierarchical_taxonomies = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
				FROM $live_terms_table t
				JOIN $live_termstaxonomy_table tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy IN ($taxonomy_format)
				ORDER BY tt.parent;",
				$hierarchical_taxonomies_to_migrate
			),
			ARRAY_A
		);
		// phpcs:enable

		// Go through all the $live_taxonomies and get or create them on local, and mark their term_id changes in $hierarchical_taxonomy_term_id_updates.
		$hierarchical_taxonomy_term_id_updates = [];
		foreach ( $live_hierarchical_taxonomies as $live_hierarchical_taxonomy ) {
			$live_hierarchical_taxonomy_tree = $this->get_hierarchical_taxonomy_tree( $live_table_prefix, $live_hierarchical_taxonomy );

			// Register taxonomy if not already registered needed (init action not executed at this point, and it just needs to be register it for the purpose of this plugin).
			if ( ! taxonomy_exists( $live_hierarchical_taxonomy_tree['taxonomy'] ) ) {
				$registered_taxonomy = register_taxonomy(
					$live_hierarchical_taxonomy_tree['taxonomy'],
					'post',
					[
						'taxonomy'     => $live_hierarchical_taxonomy_tree['taxonomy'],
						'description'  => $live_hierarchical_taxonomy_tree['taxonomy'],
						'count'        => $live_hierarchical_taxonomy_tree['count'],
						'public'       => true,
						'hierarchical' => true,
					]
				);
				if ( is_wp_error( $registered_taxonomy ) ) {
					WP_CLI::error( 'Failed to register taxonomy ' . $live_hierarchical_taxonomy_tree['taxonomy'] . ' error: ' . $registered_taxonomy->get_error_message() );
				}
			}

			$created_hierarchical_taxonomy_tree = $this->get_or_create_hierarchical_taxonomy_tree( $table_prefix, $live_hierarchical_taxonomy_tree );

			$hierarchical_taxonomy_term_id_updates[ $live_hierarchical_taxonomy['term_id'] ] = $created_hierarchical_taxonomy_tree['term_id'];
		}

		return $hierarchical_taxonomy_term_id_updates;
	}

	/**
	 * Fetches the hierarchical taxonomy's tree by retrieving all the parent hierarchical taxonomies down to the top parent.
	 *
	 * @param string $table_prefix DB table prefix.
	 * @param array  $hierarchical_taxonomy {
	 *    Hierarchical taxonomy data array.
	 *
	 *     @type string term_id     Hierarchical taxonomy term_id.
	 *     @type string taxonomy    Should always be a hierarchical taxonomy.
	 *     @type string name        Hierarchical taxonomy name.
	 *     @type string slug        Hierarchical taxonomy slug.
	 *     @type string description Hierarchical taxonomy description.
	 *     @type string count       Hierarchical taxonomy count.
	 *     @type string parent      Hierarchical taxonomy parent term_id.
	 * }
	 *
	 * @return array {
	 *     A nested array of hierarchical taxonomies, where 'parent' key is either another subarray hierarchical taxonomy, or '0' if no parent.
	 *
	 *     @type string       term_id     Hierarchical taxonomy term_id.
	 *     @type string       taxonomy    Should always be a hierarchical taxonomy.
	 *     @type string       name        Hierarchical taxonomy name.
	 *     @type string       slug        Hierarchical taxonomy slug.
	 *     @type string       description Hierarchical taxonomy description.
	 *     @type string       count       Hierarchical taxonomy count.
	 *     @type string|array parent      Either nested parent subarray hierarchical taxonomy containing all the same keys and values, or '0'.
	 * }
	 */
	public function get_hierarchical_taxonomy_tree( $table_prefix, $hierarchical_taxonomy ) {

		$hierarchical_taxonomy_tree = $hierarchical_taxonomy;

		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		$parent_term_id = $hierarchical_taxonomy['parent'];
		if ( 0 != $parent_term_id ) {
			// phpcs:disable -- wpdb::prepare used by wrapper.
			$parent_row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
					FROM {$table_terms} t
			        JOIN {$table_term_taxonomy} tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					AND t.term_id = %s
					ORDER BY tt.parent;",
					[ $hierarchical_taxonomy['taxonomy'], $parent_term_id ]
				),
				ARRAY_A
			);
			// phpcs:enable

			// This is either root taxonomy, or go level up recursively.
			if ( 0 == $parent_row['parent'] ) {
				$hierarchical_taxonomy_tree['parent'] = $parent_row;
			} else {
				$hierarchical_taxonomy_tree['parent'] = $this->get_hierarchical_taxonomy_tree( $table_prefix, $parent_row );
			}
		}

		return $hierarchical_taxonomy_tree;
	}

	/**
	 * Rebuilds the full tree of a hierarchical taxonomy. Either taxes an existing hierarchical taxonomy, or creates it.
	 *
	 * @param string $table_prefix  DB table prefix.
	 * @param array  $hierarchical_taxonomy_tree {
	 *     A nested array of hierarchical taxonomies, where 'parent' key is either another subarray hierarchical taxonomy, or '0' if no parent.
	 *     This is being read as a parameter and will be rebuilt node by node.
	 *
	 *     @type string       term_id     Hierarchical Taxonomy term_id.
	 *     @type string       taxonomy    Should always be a hierarchical taxonomy.
	 *     @type string       name        Hierarchical Taxonomy name.
	 *     @type string       slug        Hierarchical Taxonomy slug.
	 *     @type string       description Hierarchical Taxonomy description.
	 *     @type string       count       Hierarchical Taxonomy count.
	 *     @type string|array parent      Either nested parent subarray hierarchical taxonomy containing all the same keys and values, or '0'.
	 * }
	 *
	 * @return array {
	 *     A nested array of hierarchical taxonomies, where 'parent' key is either another subarray hierarchical taxonomy, or '0' if no parent.
	 *     This is the resulting hierarchical taxonomy tree, either existing hierarchical taxonomies fetched or new ones created.
	 *
	 *     @type string       term_id     Hierarchical Taxonomy term_id.
	 *     @type string       taxonomy    Should always be a hierarchical taxonomy.
	 *     @type string       name        Hierarchical Taxonomy name.
	 *     @type string       slug        Hierarchical Taxonomy slug.
	 *     @type string       description Hierarchical Taxonomy description.
	 *     @type string       count       Hierarchical Taxonomy count.
	 *     @type string|array parent      Either nested parent subarray hierarchical taxonomy containing all the same keys and values, or '0'.
	 * }
	 */
	public function get_or_create_hierarchical_taxonomy_tree( $table_prefix, $hierarchical_taxonomy_tree ) {
		// If this is the top parent hierarchical taxonomy, get or create it.
		if ( 0 == $hierarchical_taxonomy_tree['parent'] ) {

			// Get or create this top parent hierarchical taxonomy.
			$hierarchical_taxonomy_top_parent_row     = $this->get_hierarchical_taxonomy_array_by_name_and_parent( $table_prefix, $hierarchical_taxonomy_tree['name'], $hierarchical_taxonomy_tree['taxonomy'], 0 );
			$hierarchical_taxonomy_top_parent_term_id = $hierarchical_taxonomy_top_parent_row['term_id'] ?? null;
			if ( ! $hierarchical_taxonomy_top_parent_term_id ) {
				// Insert it if it doesn't exist.
				$hierarchical_taxonomy_top_parent_term_id = $this->wp_insert_or_update_term(
					$hierarchical_taxonomy_tree['name'],
					$hierarchical_taxonomy_tree['description'],
					0,
					$hierarchical_taxonomy_tree['taxonomy']
				);
			}
			// Get this parent taxonomy's full array.
			$hierarchical_taxonomy_top_parent = $this->get_term_and_taxonomy_array(
				$table_prefix,
				[ 'term_id' => $hierarchical_taxonomy_top_parent_term_id ],
				$hierarchical_taxonomy_tree['taxonomy']
			);

			return $hierarchical_taxonomy_top_parent;
		}

		// If this is not top parent taxonomy, keep going deeper recursively until reaching it.
		if ( 0 != $hierarchical_taxonomy_tree['parent'] ) {
			$current_parent_tree = $this->get_or_create_hierarchical_taxonomy_tree( $table_prefix, $hierarchical_taxonomy_tree['parent'] );
		}

		// For a non-top-parent taxonomy, get or create its tree and return.
		$taxonomy_row     = $this->get_hierarchical_taxonomy_array_by_name_and_parent( $table_prefix, $hierarchical_taxonomy_tree['name'], $hierarchical_taxonomy_tree['taxonomy'], $current_parent_tree['term_id'] );
		$taxonomy_term_id = $taxonomy_row['term_id'] ?? null;
		if ( ! $taxonomy_term_id ) {
			$taxonomy_term_id = $this->wp_insert_or_update_term(
				$hierarchical_taxonomy_tree['name'],
				$hierarchical_taxonomy_tree['description'],
				$current_parent_tree['term_id'],
				$hierarchical_taxonomy_tree['taxonomy']
			);
		}
		$taxonomy = $this->get_term_and_taxonomy_array(
			$table_prefix,
			[ 'term_id' => $taxonomy_term_id ],
			$hierarchical_taxonomy_tree['taxonomy']
		);

		// This is the reubuilt taxonomy tree.
		$rebuilt_hierarchical_taxonomy_tree           = $taxonomy;
		$rebuilt_hierarchical_taxonomy_tree['parent'] = $current_parent_tree;

		return $rebuilt_hierarchical_taxonomy_tree;
	}

	/**
	 * Gets Term and Taxonomy data array by either term_id or Term name.
	 *
	 * @param string $table_prefix DB table prefix.
	 * @param array  $where        Where clause. Must provide either 'term_id' or 'term_name' key and value.
	 * @param string $taxonomy     Taxonomy.
	 *
	 * @return array|null {
	 *     @type string term_id     Term term_id.
	 *     @type string taxonomy    Term taxonomy, e.g. 'category' or 'post_tag' or 'author'.
	 *     @type string name        Term name.
	 *     @type string slug        Term slug.
	 *     @type string description Taxonomy description.
	 *     @type string count       Term count.
	 *     @type string parent      Term parent's term_id.
	 * }
	 */
	public function get_term_and_taxonomy_array( $table_prefix, array $where, $taxonomy ) {

		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		$query_and_clause    = '';
		$query_and_parameter = null;
		if ( isset( $where['term_id'] ) ) {
			$query_and_clause    = ' AND t.term_id = %s ';
			$query_and_parameter = $where['term_id'];
		} elseif ( isset( $where['term_name'] ) ) {
			$query_and_clause    = ' AND t.name = %s ';
			$query_and_parameter = $where['term_name'];
		} else {
			return null;
		}

		// phpcs:disable -- wpdb::prepare used by wrapper.
		$term_taxonomy_data = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, tt.term_taxonomy_id, t.name, t.slug, tt.parent, tt.description, tt.count
				FROM $table_terms t
		        JOIN $table_term_taxonomy tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				{$query_and_clause} ;",
				$taxonomy,
				$query_and_parameter
			),
			ARRAY_A
		);
		// phpcs:enable

		return $term_taxonomy_data;
	}

	/**
	 * Gets hierarchical taxonomy by its name and parent.
	 *
	 * @param string $table_prefix          DB table prefix.
	 * @param string $taxonomy_name         Hierarchical Taxonomy name.
	 * @param string $taxonomy              Hierarchical Taxonomy.
	 * @param string $taxonomy_parent       Hierarchical Taxonomy parent's term_id.
	 *
	 * @return array {
	 *     @type string term_id     Hierarchical Taxonomy term_id.
	 *     @type string taxonomy    Should always be a hierarchical taxonomy.
	 *     @type string name        Hierarchical Taxonomy name.
	 *     @type string slug        Hierarchical Taxonomy slug.
	 *     @type string description Hierarchical Taxonomy description.
	 *     @type string count       Hierarchical Taxonomy count.
	 *     @type string parent      Hierarchical Taxonomy parent's term_id.
	 * }
	 */
	public function get_hierarchical_taxonomy_array_by_name_and_parent( $table_prefix, $taxonomy_name, $taxonomy, $taxonomy_parent ) {
		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		// phpcs:disable -- wpdb::prepare used by wrapper.
		$hierarchical_taxonomy = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
					FROM $table_terms t
			        JOIN $table_term_taxonomy tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					AND tt.parent = %s
					AND t.name = %s;",
				$taxonomy,
				$taxonomy_parent,
				$taxonomy_name
			),
			ARRAY_A
		);
		// phpcs:enable

		return $hierarchical_taxonomy;
	}

	/**
	 * Create a term in a hierarchical taxonomy, or update it if it already exists.
	 *
	 * @param string $term_name         Term name.
	 * @param string $term_description  Term description.
	 * @param string $term_parent       Term parent's term_id.
	 * @param string $taxonomy          Taxonomy.
	 *
	 * @return int|\WP_Error The ID number of the new or updated Term on success. Zero or a WP_Error on failure,
	 *                       depending on param `$wp_error`.
	 */
	public function wp_insert_or_update_term( $term_name, $term_description, $term_parent, $taxonomy ) {
		// Check if the term already exists.
		$term_exists = term_exists( $term_name, $taxonomy, $term_parent ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.term_exists_term_exists

		// If the term doesn't exist, insert it.
		if ( ! $term_exists ) {
			$term_id = wp_insert_term(
				$term_name,
				$taxonomy,
				[
					'description' => $term_description,
					'parent'      => $term_parent,
				]
			);

			if ( is_wp_error( $term_id ) ) {
				return $term_id;
			}
			return $term_id['term_id'];
		}

		// If the term exists, update it.
		$term_id     = $term_exists['term_id'];
		$term_update = wp_update_term(
			$term_id,
			$taxonomy,
			[
				'description' => $term_description,
				'parent'      => $term_parent,
			]
		);

		if ( is_wp_error( $term_update ) ) {
			return $term_update;
		}

		return $term_id;
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
	public function migrate_all_users( $live_table_prefix, string $source_hostname ) {

		// Keys are Live wp_user.IDs, and values are newly inserted user IDs.
		$inserted_users_map = [];

		$users_rows = $this->select( $live_table_prefix . 'users', [], $select_just_one_row = false );
		foreach ( $users_rows as $user_row ) {
			// Skip if user exists.
			$user_existing = $this->get_user_by( 'login', $user_row['user_login'] );
			if ( $user_existing instanceof WP_User ) {
				continue;
			}

			// Get user metas.
			$usermeta_rows = $this->select_usermeta_rows( $live_table_prefix, $user_row['ID'] );

			// Insert user and user metas.
			$user_id_new = $this->insert_user( $user_row, $source_hostname );
			foreach ( $usermeta_rows as $usermeta_row ) {
				$this->insert_usermeta_row( $usermeta_row, $user_id_new );
			}

			$inserted_users_map[ $user_row['ID'] ] = $user_id_new;
		}

		return $inserted_users_map;
	}

	/**
	 * Matches local posts to live posts using composite key hash mapping.
	 * Similar pattern to filter_new_live_ids but returns local->live ID pairs.
	 *
	 * @param array $results_local_posts Rows from local posts table.
	 * @param array $results_live_posts  Rows from live posts table.
	 *
	 * @return array Matched pairs with local_id and live_id.
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
	 * @return array Matched pairs with local_id and live_id.
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
	 * Imports all the Post related data.
	 *
	 * @param int    $post_id                  Post Id.
	 * @param array  $data                     Array containing all the data, @see
	 *                                         \Newspack\ContentDiffMigrator\Logic\ContentDiffMigrator::get_post_data
	 *                                         for structure.
	 * @param array  $hierarchical_taxonomy_term_id_updates Hierarchical Taxonomy term_ids updates. Keys are old Live hierarchical taxonomy term_ids, and values are
	 *                                         corresponding Hierarchical Taxonomies on local (Staging) term_ids.
	 * @param string $source_hostname          Source hostname.
	 *
	 * @return array List of errors which occurred.
	 */
	/**
	 * Imports all post-related data (meta, author, comments, taxonomies).
	 *
	 * @param int    $post_id                              Post ID.
	 * @param array  $data                                 Post data array with keys: post, postmeta, comments, commentmeta, users, usermeta, term_relationships, term_taxonomy, terms, termmeta.
	 * @param array  $hierarchical_taxonomy_term_id_updates Map of updated hierarchical taxonomy term_ids. Keys are Taxonomies' term_ids on live, and values are corresponding Taxonomies' term_ids on local (staging).
	 * @param string $source_hostname                      Source hostname.
	 *
	 * @return array Array of error messages.
	 */
	public function import_post_data( $post_id, $data, $hierarchical_taxonomy_term_id_updates, string $source_hostname ) {
		return $this->data_importer->import_post_data( $post_id, $data, $hierarchical_taxonomy_term_id_updates, $source_hostname );
	}

	/**
	 * Updates Post's post_parent ID.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $new_parent_id New post_parent ID for this post.
	 */
	public function update_post_parent( $post_id, $new_parent_id ) {
		$this->wpdb->update( $this->wpdb->posts, [ 'post_parent' => $new_parent_id ], [ 'ID' => $post_id ] );
	}

	/**
	 * Updates Posts' Thumbnail IDs with new Thumbnail IDs after insertion.
	 *
	 * @param array  $imported_post_ids           Imported local Post IDs.
	 * @param array  $imported_attachment_ids_map Keys are IDs on Live Site, values are IDs of imported posts on Local Site.
	 * @param string $log_file_path               Optional. Full path to a log file. If provided, the method will save and append
	 *                                            a detailed output of all the changes made.
	 * @param bool   $dry_run                     If true, will not make changes to DB, and will output changes to CLI instead of
	 *                                            saving them to $log_file_path.
	 */
	public function update_featured_images( $imported_post_ids, $imported_attachment_ids_map, $log_file_path, $dry_run = false ) {
		if ( empty( $imported_post_ids ) || empty( $imported_attachment_ids_map ) ) {
			return;
		}

		/**
		 * This command will only update '_thumbnail_id's for Posts which were imported by the Content Diff (not any other Posts).
		 *
		 * Explanation why:
		 * for example, we could have imported two different attachments:
		 *      {"post_type":"attachment","id_old":1111,"id_new":999}
		 *      {"post_type":"attachment","id_old":1223,"id_new":1111}
		 * and let's say these two posts exist on Staging:
		 *      - first with '_thumbnail_id' 1111
		 *          --> this one needs to be updated from 1111 to 999
		 *      - second with '_thumbnail_id' 1111, but let's say this post was created directly on Staging and it used the second attachment with Staging ID 1111
		 *          --> this one's _thumbnail_id should be updated from 1111 to 999
		 *
		 * Therefore this command will only update '_thumbnail_id's for those Posts that were imported by the Content Diff.
		 */

		// Loop through posts and update their _thumbnail_id if needed.
		foreach ( $imported_post_ids as $new_post_id ) {

			// Get Post's current _thumbnail_id.
			// phpcs:disable
			$current_thumbnail_id = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT meta_value
					FROM {$this->wpdb->postmeta}
					WHERE meta_key = '_thumbnail_id'
					AND post_id = %d",
					$new_post_id
				)
			);
			// phpcs:enable

			// Check if this _thumbnail_id is used as a key in $imported_attachment_ids_map (keys are "old_id"s, values are "new_id"s).
			if ( ! $current_thumbnail_id || ! array_key_exists( $current_thumbnail_id, $imported_attachment_ids_map ) ) {
				continue;
			}

			// Get the new _thumbnail_id and update it.
			$new_thumbnail_id = $imported_attachment_ids_map[ $current_thumbnail_id ];
			if ( $dry_run ) {
				$updated = 1;
			} else {
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				$updated = $this->wpdb->update(
					$this->wpdb->postmeta,
					[ 'meta_value' => $new_thumbnail_id ],
					[
						'post_id'  => $new_post_id,
						'meta_key' => '_thumbnail_id',
					]
				);
				// phpcs:enable
			}

			// Log.
			if ( false != $updated && $updated > 0 && ! is_null( $log_file_path ) ) {
				$msg = wp_json_encode(
					[
						'post_id' => (int) $new_post_id,
						'id_old'  => (int) $current_thumbnail_id,
						'id_new'  => (int) $new_thumbnail_id,
					]
				);
				if ( $dry_run ) {
					WP_CLI::line( 'Updating _thubnail_id id_old=>id_new ' . $msg );
				} else {
					$this->log( $log_file_path, $msg );
				}
			}
		}
	}

	/**
	 * Updates Gutenberg Blocks' attachment IDs with new attachment IDs in created `post_content` and `post_excerpt` fields.
	 *
	 * @param array  $imported_post_ids            An array of newly imported Post IDs. Will only fetch an do replacements in these.
	 * @param array  $known_attachment_ids_updates An array of known Attachment IDs which were updated; keys are old IDs, values are
	 *                                             new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local. Explanation and example --
	 *                                             let's take hostname.com and a local image https://hostname.com/wp-content/2022/09/22/a.jpg
	 *                                             as local image. Searching for this image's attachment ID will work just fine using
	 *                                             the full URL. But perhaps if this site is using an S3 bucket, and if some of
	 *                                             the URLs in post_content use https://hostname.s3.amazonaws.com/wp-content/uploads/2022/09/22/a.jpg
	 *                                             we should then add value 'hostname.s3.amazonaws.com' in this array here, so that
	 *                                             \attachment_url_to_postid can query the attachment ID by treating this S3 hostname
	 *                                             as an alias of the local one.
	 * @param string $log_file_path                Optional. Full path to a log file. If provided, will save and append a detailed
	 *                                             output of all the changes made.
	 *
	 * @return void
	 */
	public function update_blocks_ids( $imported_post_ids, array $known_attachment_ids_updates, array $local_hostname_aliases = [], $log_file_path = null ) {

		// Filter the $local_hostname_aliases argument -- remove the local host if the user entered it, just leaving additional hostname aliases here.
		if ( ! empty( $local_hostname_aliases ) ) {
			$siteurl_parsed     = wp_parse_url( get_option( 'siteurl' ) );
			$local_hostname     = $siteurl_parsed['host'];
			$key_local_hostname = array_search( $local_hostname, $local_hostname_aliases );
			if ( false !== $key_local_hostname ) {
				unset( $local_hostname_aliases[ $key_local_hostname ] );
				unset( $local_hostname_aliases[ $key_local_hostname ] );
			}
		}

		// Fetch imported posts.
		$post_ids_new = array_values( $imported_post_ids );
		$posts_table  = $this->wpdb->posts;
		$placeholders = implode( ',', array_fill( 0, count( $post_ids_new ), '%d' ) );
		// phpcs:disable -- wpdb::prepare used by wrapper.
		$sql          = $this->wpdb->prepare(
			"SELECT ID, post_content, post_excerpt FROM $posts_table pm WHERE ID IN ( $placeholders );",
			$post_ids_new
		);
		$results      = $this->wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		// Loop through all imported posts, and do all the replacements.
		foreach ( $results as $key_result => $result ) {
			$id              = $result['ID'];
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
					[ 'ID' => $id ]
				);
			}

			// Log updates.
			if ( ! is_null( $log_file_path ) ) {
				// Log the post ID that was checked.
				$log_entry = [ 'id_new' => $id ];

				// And if any updates were made, log them fully.
				if ( $content_before != $content_updated ) {
					$log_entry = array_merge(
						$log_entry,
						[
							'post_content_before' => $content_before,
							'post_content_after'  => $content_updated,
						]
					);
				}

				if ( $excerpt_before != $excerpt_updated ) {
					$log_entry = array_merge(
						$log_entry,
						[
							'post_excerpt_before' => $excerpt_before,
							'post_excerpt_after'  => $excerpt_updated,
						]
					);
				}

				$this->log( $log_file_path, wp_json_encode( $log_entry ) );
			}
		}
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
	private function get_empty_data_array() {
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
	public function get_existing_term_taxonomy( $term_id, $taxonomy ) {
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
	public function select_post_row( $table_prefix, $post_id ) {
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
	public function select_postmeta_rows( $table_prefix, $post_id ) {
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
	public function select_user_row( $table_prefix, $user_id ) {
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
	public function select_usermeta_rows( $table_prefix, $user_id ) {
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
	public function select_comment_rows( $table_prefix, $post_id ) {
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
	public function select_commentmeta_rows( $table_prefix, $comment_id ) {
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
	public function select_term_relationships_rows( $table_prefix, $post_id ) {
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
	public function select_term_taxonomy_row( $table_prefix, $term_taxonomy_id ) {
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
	public function select_term_row( $table_prefix, $term_id ) {
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
	public function select_termmeta_rows( $table_prefix, $term_id ) {
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
	private function select( $table_name, $where_conditions, $select_just_one_row = false ) {
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
	public function insert_post( $post_row ) {
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
	 * Inserts a post_meta record.
	 *
	 * @param array $postmeta_row ARRAY_A formatted wp_postmeta row with values to be inserted.
	 * @param int   $post_id      Post ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted meta_id.
	 */
	public function insert_postmeta_row( $postmeta_row, $post_id ) {
		$insert_postmeta_row = $postmeta_row;
		unset( $insert_postmeta_row['meta_id'] );
		$insert_postmeta_row['post_id'] = $post_id;

		$inserted = $this->wpdb->insert( $this->wpdb->postmeta, $insert_postmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error in insert_postmeta_row, post_id %s, postmeta_row %s', $post_id, wp_json_encode( $postmeta_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts a User.
	 *
	 * @param array  $user_row         `user` row.
	 * @param string $source_hostname  Source hostname.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted User ID.
	 */
	public function insert_user( $user_row, string $source_hostname ) {
		$old_user_id = $user_row['ID'];

		$insert_user_row = $user_row;
		unset( $insert_user_row['ID'] );

		$inserted = $this->wpdb->insert( $this->wpdb->users, $insert_user_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting user, ID %d, user_row %s', $user_row['ID'], wp_json_encode( $user_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Last inserted ID.
		$new_user_id = $this->wpdb->insert_id;

		// Save original user ID as usermeta.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$this->wpdb->insert(
			$this->wpdb->usermeta,
			[
				'user_id'    => $new_user_id,
				'meta_key'   => $this->get_old_id_meta_key( $source_hostname ),
				'meta_value' => $old_user_id,
			]
		);
		// phpcs:enable

		return $new_user_id;
	}

	/**
	 * Inserts User Meta.
	 *
	 * @param array $usermeta_row `usermeta` row.
	 * @param int   $user_id       User ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted umeta_id.
	 */
	public function insert_usermeta_row( $usermeta_row, $user_id ) {
		$insert_usermeta_row = $usermeta_row;
		unset( $insert_usermeta_row['umeta_id'] );
		$insert_usermeta_row['user_id'] = $user_id;

		$inserted = $this->wpdb->insert( $this->wpdb->usermeta, $insert_usermeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting user meta, user_id %d, $usermeta_row %s', $user_id, wp_json_encode( $usermeta_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts a Comment with an updated post_id and user_id.
	 *
	 * @param array $comment_row      `comment` row.
	 * @param int   $new_post_id      Post ID.
	 * @param int   $new_user_id      User ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted comment_id.
	 */
	public function insert_comment( $comment_row, $new_post_id, $new_user_id ) {
		$insert_comment_row = $comment_row;
		unset( $insert_comment_row['comment_ID'] );
		$insert_comment_row['comment_post_ID'] = $new_post_id;
		$insert_comment_row['user_id']         = $new_user_id;

		$inserted = $this->wpdb->insert( $this->wpdb->comments, $insert_comment_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting comment, $new_post_id %d, $new_user_id %d, $comment_row %s', $new_post_id, $new_user_id, wp_json_encode( $comment_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts Comment Metas with an updated comment_id.
	 *
	 * @param array $commentmeta_row Comment Meta rows.
	 * @param int   $new_comment_id  New Comment ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted meta_id.
	 */
	public function insert_commentmeta_row( $commentmeta_row, $new_comment_id ) {
		$insert_commentmeta_row = $commentmeta_row;
		unset( $insert_commentmeta_row['meta_id'] );
		$insert_commentmeta_row['comment_id'] = $new_comment_id;

		$inserted = $this->wpdb->insert( $this->wpdb->commentmeta, $insert_commentmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting comment meta, $new_comment_id %d, $commentmeta_row %s', $new_comment_id, wp_json_encode( $commentmeta_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Updates a Comment's parent ID.
	 *
	 * @throws \RuntimeException In case update fails.
	 *
	 * @param int $comment_id         Comment ID.
	 * @param int $comment_parent_new new Comment Parent ID.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	public function update_comment_parent( $comment_id, $comment_parent_new ) {
		$updated = $this->wpdb->update( $this->wpdb->comments, [ 'comment_parent' => $comment_parent_new ], [ 'comment_ID' => $comment_id ] );
		if ( 1 != $updated ) {
			throw new \RuntimeException( sprintf( 'Error updating comment parent, $comment_id %d, $comment_parent_new %d', $comment_id, $comment_parent_new ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $updated;
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
	public function insert_term( $term_row ) {
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
	 * Wrapper of WP's native \wp_insert_term. @see \wp_insert_term.
	 *
	 * @param string       $term_name The term name to add.
	 * @param string       $taxonomy  The taxonomy to which to add the term.
	 * @param array|string $args {
	 *     Optional. Array or query string of arguments for inserting a term.
	 *
	 *     @type string $alias_of    Slug of the term to make this term an alias of.
	 *                               Default empty string. Accepts a term slug.
	 *     @type string $description The term description. Default empty string.
	 *     @type int    $parent      The id of the parent term. Default 0.
	 *     @type string $slug        The term slug to use. Default empty string.
	 * }
	 *
	 * @return array|WP_Error {
	 *     An array of the new term data, WP_Error otherwise.
	 *
	 *     @type int        $term_id          The new term ID.
	 *     @type int|string $term_taxonomy_id The new term taxonomy ID. Can be a numeric string.
	 * }
	 */
	public function wp_insert_term( $term_name, $taxonomy, $args = [] ) {
		return \wp_insert_term( $term_name, $taxonomy, $args );
	}

	/**
	 * Inserts Term Meta.
	 *
	 * @param array $termmeta_row `usermeta` row.
	 * @param int   $term_id      User ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted meta_id.
	 */
	public function insert_termmeta_row( $termmeta_row, $term_id ) {
		$insert_termmeta_row = $termmeta_row;
		unset( $insert_termmeta_row['meta_id'] );
		$insert_termmeta_row['term_id'] = $term_id;

		$inserted = $this->wpdb->insert( $this->wpdb->termmeta, $insert_termmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term meta, $term_id %d, $termmeta_row %s', $term_id, wp_json_encode( $termmeta_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts into `term_taxonomy` table.
	 *
	 * @param array $term_taxonomy_row `term_taxonomy` row.
	 * @param int   $new_term_id       New `term_id` value to be set.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted term_taxonomy_id.
	 */
	public function insert_term_taxonomy( $term_taxonomy_row, $new_term_id ) {
		$insert_term_taxonomy_row = $term_taxonomy_row;
		if ( isset( $insert_term_taxonomy_row['term_taxonomy_id'] ) ) {
			unset( $insert_term_taxonomy_row['term_taxonomy_id'] );
		}
		$insert_term_taxonomy_row['term_id'] = $new_term_id;

		$inserted = $this->wpdb->insert( $this->wpdb->term_taxonomy, $insert_term_taxonomy_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term_taxonomy, $new_term_id %d, term_taxonomy_id %s', $new_term_id, wp_json_encode( $term_taxonomy_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts into `term_relationships` table.
	 *
	 * @param int $object_id        `object_id` column.
	 * @param int $term_taxonomy_id `term_taxonomy_id` column.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted object_id.
	 */
	public function insert_term_relationship( $object_id, $term_taxonomy_id ) {
		$inserted = $this->wpdb->insert(
			$this->wpdb->term_relationships,
			[
				'object_id'        => $object_id,
				'term_taxonomy_id' => $term_taxonomy_id,
			]
		);
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term relationship, $object_id %d, $term_taxonomy_id %d', $object_id, $term_taxonomy_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Updates a Post's Author.
	 *
	 * @throws \RuntimeException In case update fails.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $new_author_id New Author ID.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	public function update_post_author( $post_id, $new_author_id ) {
		$updated = $this->wpdb->update( $this->wpdb->posts, [ 'post_author' => $new_author_id ], [ 'ID' => $post_id ] );
		if ( 1 != $updated ) {
			throw new \RuntimeException( sprintf( 'Error updating post author, $post_id %d, $new_author_id %d', $post_id, $new_author_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $updated;
	}

	/**
	 * Gets a list of all the tables in the active DB.
	 *
	 * @return array List of all tables in DB.
	 */
	public function get_all_db_tables() {
		$all_tables        = [];
		$all_tables_result = $this->wpdb->get_results( 'SHOW TABLES;', ARRAY_N );
		foreach ( $all_tables_result as $table ) {
			$all_tables[] = $table[0];
		}

		return $all_tables;
	}

	/**
	 * Checks whether all core WP DB tables are present in used DB.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables  Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException In case not all live DB core WP tables are found.
	 */
	public function validate_core_wp_db_tables_exist_in_db( $table_prefix, $skip_tables = [] ) {
		$all_tables = $this->get_all_db_tables();
		foreach ( self::CORE_WP_TABLES as $table ) {
			if ( in_array( $table, $skip_tables ) ) {
				continue;
			}
			$tablename = $table_prefix . $table;
			if ( ! in_array( $tablename, $all_tables ) ) {
				throw new \RuntimeException( sprintf( 'Core WP DB table %s not found.', $tablename ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
	}

	/**
	 * This function will compare Core WP Tables against the Live WP tables
	 * brought in for a content migration/refresh. This will be
	 * useful for determining whether a collation
	 * migration is necessary.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return array
	 */
	public function get_collation_comparison_of_live_and_core_wp_tables( string $table_prefix, array $skip_tables = [] ): array {
		$validated_tables = [];

		$core_tables = array_diff( self::CORE_WP_TABLES, $skip_tables );
		foreach ( $core_tables as $table ) {
			$core_table = esc_sql( $this->wpdb->prefix . $table );
			$live_table = esc_sql( $table_prefix . $table );

			// phpcs:ignore -- query fully sanitized.
			$core_table_status = $this->wpdb->get_row( "SHOW TABLE STATUS WHERE name LIKE '$core_table'" );
			// phpcs:ignore -- query fully sanitized.
			$live_table_status = $this->wpdb->get_row( "SHOW TABLE STATUS WHERE name LIKE '$live_table'" );

			if ( is_null( $live_table_status ) ) {
				WP_CLI::warning( "Live table `$live_table` does not exist, skipping table." );
				continue;
			}

			// phpcs:ignore -- ignore CamelCase param.
			$match_test = $live_table_status->Collation === $core_table_status->Collation;

			$validated_tables[] = [
				'table'                => $table,
				'core_table_name'      => $core_table,
				// phpcs:ignore -- ignore CamelCase param.
				'core_table_collation' => $core_table_status->Collation,
				'live_table_name'      => $live_table,
				// phpcs:ignore -- ignore CamelCase param.
				'live_table_collation' => $live_table_status->Collation,
				'match'                => $match_test ? 'YES' : 'NO',
				'match_bool'           => $match_test,
			];
		}

		if ( empty( $validated_tables ) ) {
			throw new \RuntimeException( 'Unable to validate collation on content diff tables. Please verify live table prefix.' );
		}

		return $validated_tables;
	}

	/**
	 * Convenience function that only returns tables which have a different collation
	 * than the Core WP DB tables.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return array
	 */
	public function filter_for_different_collated_tables( string $table_prefix, array $skip_tables = [] ): array {
		$collation_comparison = $this->get_collation_comparison_of_live_and_core_wp_tables( $table_prefix, $skip_tables );

		return array_values(
			array_filter(
				$collation_comparison,
				fn( $validated_table ) => false === $validated_table['match_bool']
			)
		);
	}

	/**
	 * Convenience function which returns a simple boolean value indicating whether all Live
	 * DB tables have matching collations with their corresponding Core WP DB tables.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return bool
	 */
	public function are_table_collations_matching( string $table_prefix, array $skip_tables = [] ): bool {
		return empty( $this->filter_for_different_collated_tables( $table_prefix, $skip_tables ) );
	}

	/**
	 * This function will handle the operation to move data from the
	 * incompatibly collated table to the new compatible table.
	 *
	 * @param string $prefix Live table prefix.
	 * @param string $table The Core WP Table to address.
	 * @param int    $records_per_transaction The amount of records to process per transaction.
	 * @param int    $sleep_in_seconds Delay in seconds between each DB transaction.
	 * @param string $prefix_for_backup Custom prefix for table to be backed up to.
	 *
	 * @throws \RuntimeException Throws various exceptions if unable to complete required SQL operations.
	 */
	public function copy_table_data_using_proper_collation( string $prefix, string $table, int $records_per_transaction = 5000, int $sleep_in_seconds = 1, string $prefix_for_backup = 'bak_' ) {
		$backup_table              = esc_sql( $prefix_for_backup . $prefix . $table );
		$source_table              = esc_sql( $prefix . $table );
		$match_collation_for_table = esc_sql( $this->wpdb->prefix . $table );

		$rename_sql = "RENAME TABLE $source_table TO $backup_table";
		// phpcs:ignore -- query fully sanitized.
		$rename_result             = $this->wpdb->query( $rename_sql );

		if ( is_wp_error( $rename_result ) ) {
			throw new \RuntimeException( "Unable to rename table: '$rename_sql'\n" . $rename_result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$create_like_table_sql = "CREATE TABLE {$source_table} LIKE $match_collation_for_table";
		// phpcs:ignore -- query fully sanitized.
        $create_result         = $this->wpdb->query( $create_like_table_sql );

		if ( false === $create_result ) {
			$db_error = ( '' != $this->wpdb->last_error ) ? $this->wpdb->last_error : 'unknown error';
			throw new \RuntimeException( "Unable to create table: '$create_like_table_sql'\nDB error: $db_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$limiter = [
			'start' => 0,
			'limit' => $records_per_transaction,
		];

		$table_columns_sql = "SHOW COLUMNS FROM $source_table";
		// phpcs:ignore -- query fully sanitized.
		$table_columns_results = $this->wpdb->get_results( $table_columns_sql );
		$table_columns         = implode( ',', array_map( fn( $column_row ) => "`$column_row->Field`", $table_columns_results ) );
		// phpcs:ignore -- query fully sanitized.
        $count                 = $this->wpdb->get_row( "SELECT COUNT(*) as counter FROM $backup_table;" );

		if ( empty( $count ) || 0 === (int) $count->counter ) {
			throw new \RuntimeException( "Table '$backup_table' has 0 rows. No need to continue." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$iterations = ceil( $count->counter / $limiter['limit'] );
		for ( $i = 1; $i <= $iterations; $i++ ) {
			$insert_sql = "INSERT INTO `{$source_table}`({$table_columns}) SELECT {$table_columns} FROM {$backup_table} LIMIT {$limiter['start']}, {$limiter['limit']}";
			// phpcs:ignore -- query fully sanitized.
            $insert_result = $this->wpdb->query( $insert_sql );

			if ( ( false !== $insert_result ) && ( 0 !== $insert_result ) ) {
				$limiter['start'] = $limiter['start'] + $limiter['limit'];
			} else {
				$db_error = ( '' != $this->wpdb->last_error ) ? 'DB error message: ' . $this->wpdb->last_error : 'No DB error message available -- check error and debug logs.';
				WP_CLI::error( sprintf( "Got up to (not including) %s. Failed running SQL '%s'. %s", $limiter['start'], $insert_sql, $db_error ) );
			}

			if ( $sleep_in_seconds ) {
				sleep( $sleep_in_seconds );
			}
		}
	}

	/**
	 * Wrapper for WP's native \get_user_by(), for easier testing.
	 *
	 * @param string     $field The field to retrieve the user with. id | ID | slug | email | login.
	 * @param int|string $value A value for $field. A user ID, slug, email address, or login name.
	 *
	 * @return WP_User|false WP_User object on success, false on failure.
	 */
	public function get_user_by( $field, $value ) {
		return get_user_by( $field, $value );
	}

	/**
	 * Finds current post ID by old live DB ID, by searching for a source-specific post meta.
	 *
	 * @param int|string $id_live  Post ID from live DB.
	 * @param string     $meta_key Name of postmeta which contains old post ID (use get_old_id_meta_key()).
	 *
	 * @return string|null Current Post ID.
	 */
	public function get_current_post_id_by_custom_meta( $id_live, $meta_key ) {

		// phpcs:disable -- wpdb::prepare is used correctly.
		$post_id_new = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT post_id
			FROM {$this->wpdb->postmeta}
			WHERE meta_key = %s
			AND meta_value = %s",
				$meta_key,
				$id_live
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
	public function get_current_post_id_by_comparing_with_live_db( $id_live, $live_table_prefix ) {

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
	 * Wrapper for WP's native \get_post(), for easier testing.
	 *
	 * @param int|WP_Post|null $post   Optional. Post ID or post object. `null`, `false`, `0` and other PHP falsey
	 *                                 values return the current global post inside the loop. A numerically valid post
	 *                                 ID that points to a non-existent post returns `null`. Defaults to global $post.
	 * @param string           $output Optional. The required return type. One of OBJECT, ARRAY_A, or ARRAY_N, which
	 *                                 correspond to a WP_Post object, an associative array, or a numeric array,
	 *                                 respectively. Default OBJECT.
	 * @param string           $filter Optional. Type of filter to apply. Accepts 'raw', 'edit', 'db',
	 *                                 or 'display'. Default 'raw'.
	 * @return WP_Post|array|null Type corresponding to $output on success or null on failure.
	 *                            When $output is OBJECT, a `WP_Post` instance is returned.
	 */
	public function get_post( $post = null, $output = OBJECT, $filter = 'raw' ) {
		return get_post( $post, $output, $filter );
	}

	/**
	 * Cleans up the attachment file URL by just keeping scheme, host and path.
	 *
	 * @param string $url Attachment file URL.
	 *
	 * @return string Cleaned URL.
	 */
	public function clean_attachment_url_for_query( $url ) {
		$parsed_url = wp_parse_url( $url );

		$url_cleaned = sprintf(
			'%s://%s%s',
			$parsed_url['scheme'],
			$parsed_url['host'],
			$parsed_url['path'],
		);

		return $url_cleaned;
	}

	/**
	 * Checks if this $url should be queried as local attachment -- does it have the same hostname as 'siteurl', or is the hostname
	 * one of $local_hostname_aliases.
	 *
	 * @param string $url                    Attachment file URL.
	 * @param array  $local_hostname_aliases Array of hostnames to use as local hostname aliases.
	 *
	 * @return bool Should this URL be queried as local attachment.
	 */
	public function should_url_be_queried_as_local_attachment( $url, $local_hostname_aliases ) {
		$url_parsed = wp_parse_url( $url );
		$url_host   = $url_parsed['host'];

		$siteurl        = get_option( 'siteurl' );
		$siteurl_parsed = wp_parse_url( $siteurl );
		$siteurl_host   = $siteurl_parsed['host'];

		return $siteurl_host == $url_host || in_array( $url_host, $local_hostname_aliases );
	}

	/**
	 * Wrapper for WP's native \attachment_url_to_postid(), for easier testing.
	 *
	 * @param string $url                    The URL to resolve.
	 * @param array  $local_hostname_aliases Array of hostnames to use as local hostname aliases.
	 *
	 * @return int The found post ID, or 0 on failure.
	 */
	public function attachment_url_to_postid( $url, $local_hostname_aliases = [] ) {

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
		$post_id = attachment_url_to_postid( $url );

		return $post_id;
	}

	/**
	 * Filters a multidimensional array and searches for a subarray with a key and value.
	 *
	 * @param array $data  Array being searched and filtered.
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for.
	 *
	 * @return null|array The array which matches the $key $value filter, or null.
	 */
	public function filter_array_element( $data, $key, $value ) {
		foreach ( $data as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				return $subarray;
			}
		}

		return null;
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
	public function filter_array_elements( $data, $key, $value ) {
		$found = [];
		foreach ( $data as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				$found[] = $subarray;
			}
		}

		return $found;
	}

	/**
	 * A simple progress meter which updates percentage progress of a counter in terms of a given percentage number increment. You
	 * get to tell it the percentage increment, for example, update the status progress by every "5%" change, then it
	 * updates the $current_percent at 0%, 5%, 10%, 15%, 20%, ..., 100%.
	 *
	 * @param int $total_count       Total number of steps.
	 * @param int $current_count     Current step, starting from 1.
	 * @param int $percent_increment The percentage increment by which the progress update should be done.
	 * @param int $current_percent   Current percentage progress.
	 *
	 * @return void
	 */
	public function get_progress_percentage( $total_count, $current_count, $percent_increment, &$current_percent = null ) {

		// Initialize 0%.
		if ( is_null( $current_percent ) ) {
			$current_percent = 0;
		}

		// Get what the next regular increase in percentage will be.
		$next_percent_increase = $current_percent + $percent_increment;
		$next_percent_increase = $next_percent_increase >= 100 ? 100 : $next_percent_increase;

		// Get actual precentage at this count.
		$current_percent_actual = $current_count * 100 / $total_count;

		// First check if $current_count ($current_percent_actual) has already exceeded the regular $next_percent_increase.
		if ( $current_percent_actual > $next_percent_increase ) {
			// Speed up to $current_percent_actual.
			while ( ( $current_percent + $percent_increment ) <= $current_percent_actual ) {
				$current_percent += $percent_increment;
			}
		} else {
			// Get which "current count" number will make the percentage increase to the $next_percent_increase amount.
			$required_current_count_for_increase = $next_percent_increase * $total_count / 100;

			// Increase percentage if reached.
			if ( $current_count >= $required_current_count_for_increase ) {
				$current_percent = $next_percent_increase;
			}
		}
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

	/**
	 * Escapes special characters in string to be used in PHP regex patterns/expressions.
	 *
	 * @param string $subject Subject.
	 *
	 * @return string
	 */
	private function escape_regex_pattern_string( string $subject ): string {
		$special_chars   = [ '.', '\\', '+', '*', '?', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':' ];
		$subject_escaped = $subject;
		foreach ( $special_chars as $special_char ) {
			$subject_escaped = str_replace( $special_char, '\\' . $special_char, $subject_escaped );
		}

		// Space.
		$subject_escaped = str_replace( ' ', '\s', $subject_escaped );

		return $subject_escaped;
	}

	/**
	 * Logs error message to file.
	 *
	 * @param string $file Path to log file.
	 * @param string $msg  Error message.
	 */
	public function log( $file, $msg ) {
		file_put_contents( $file, $msg . "\n", FILE_APPEND ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
	}
}
