<?php
/**
 * Data Importer handles importing post-related data (meta, users, comments, taxonomies).
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Logic;

use Newspack\ContentDiffMigrator\Utils\Logger;
use Psr\Log\LogLevel;
use WP_User;
use wpdb;

/**
 * Imports post-related data for ContentDiffLogic.
 */
class DataImporter {

	/**
	 * Global $wpdb.
	 *
	 * @var wpdb $wpdb Global $wpdb.
	 */
	private wpdb $wpdb;

	/**
	 * RunState for tracking imported items.
	 *
	 * @var RunState|null
	 */
	private ?RunState $run_state = null;

	/**
	 * Map of live term_id to local term_id for all taxonomies.
	 * Populated on-the-fly during import by getting/creating, shared by all posts which use the same taxonomies.
	 *
	 * @var array<int, int>
	 */
	private array $taxonomy_term_id_map = [];

	/**
	 * Set of live term_ids for which termmeta has already been imported.
	 * Prevents duplicate termmeta inserts when the same term is encountered across multiple posts.
	 *
	 * @var array<int, bool>
	 */
	private array $termmeta_imported = [];

	/**
	 * DataImporter constructor.
	 *
	 * @param wpdb          $wpdb      Global $wpdb.
	 * @param RunState|null $run_state Optional RunState for tracking imported items (used for reports).
	 */
	public function __construct( wpdb $wpdb, ?RunState $run_state = null ) {
		$this->wpdb      = $wpdb;
		$this->run_state = $run_state;
	}

	/**
	 * Sets the RunState instance for tracking imported items.
	 *
	 * RunState depends on --data-dir CLI argument which is available after the command parses arguments and only then creates the RunState.
	 *
	 * @param RunState $run_state RunState instance.
	 */
	public function set_run_state( RunState $run_state ): void {
		$this->run_state = $run_state;
	}

	/**
	 * Tracks a modified user in RunState for reports.
	 *
	 * @param int $live_id  Live user ID.
	 * @param int $local_id Local user ID.
	 */
	public function track_modified_user( int $live_id, int $local_id ): void {
		if ( null !== $this->run_state ) {
			$this->run_state->append_imported_user(
				[
					'id_old' => $live_id,
					'id_new' => $local_id,
					'status' => 'modified',
				]
			);
		}
	}

	/**
	 * Imports all post-related data (meta, author, comments, taxonomies, termmeta).
	 *
	 * @param int    $post_id               Post ID.
	 * @param array  $data {
	 *     Post data array from ContentDiffLogic::get_post_data().
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
	 * @param string $live_table_prefix     Live database table prefix.
	 * @param array  $taxonomies_to_migrate List of taxonomies allowed to be migrated.
	 * @param string $source_hostname       Source hostname.
	 */
	public function import_post_data( int $post_id, array $data, string $live_table_prefix, array $taxonomies_to_migrate, string $source_hostname ): void {
		$this->import_post_meta( $data, $post_id );
		$this->import_author( $data, $post_id, $source_hostname );
		$this->import_comments( $data, $post_id, $source_hostname );
		$this->import_taxonomies( $data, $post_id, $live_table_prefix, $taxonomies_to_migrate, $source_hostname );
	}

	/**
	 * Imports post meta.
	 *
	 * @param array $data    Post data array.
	 * @param int   $post_id New post ID.
	 */
	private function import_post_meta( array $data, int $post_id ): void {
		$id_old = $data[ ContentDiffLogic::DATAKEY_POST ]['ID'];
		foreach ( $data[ ContentDiffLogic::DATAKEY_POSTMETA ] as $postmeta_row ) {
			try {
				$this->insert_postmeta_row( $postmeta_row, $post_id );
			} catch ( \Exception $e ) {
				Logger::instance()->log_brief_and_verbose(
					LogLevel::ERROR,
					sprintf( 'import_post_meta error: %s', $e->getMessage() ),
					[
						'id_old'       => $id_old,
						'id_new'       => $post_id,
						'postmeta_row' => $postmeta_row,
					] 
				);
			}
		}
	}

	/**
	 * Gets or creates the post author user, then updates the post's author ID if needed.
	 *
	 * @param array  $data            Post data array.
	 * @param int    $post_id         New post ID.
	 * @param string $source_hostname Source hostname.
	 */
	private function import_author( array $data, int $post_id, string $source_hostname ): void {
		$id_old = $data[ ContentDiffLogic::DATAKEY_POST ]['ID'];

		// Get existing Author User or create a new one.
		$author_id_old = $data[ ContentDiffLogic::DATAKEY_POST ]['post_author'];
		$author_row    = ! is_null( $author_id_old ) ? $this->filter_array_element( $data[ ContentDiffLogic::DATAKEY_USERS ], 'ID', $author_id_old ) : [];
		$usermeta_rows = is_array( $author_row ) && array_key_exists( 'ID', $author_row ) ? $this->filter_array_elements( $data[ ContentDiffLogic::DATAKEY_USERMETA ], 'user_id', $author_row['ID'] ) : [];

		// Get or create author (returns null for invalid/empty author_row, which means author_id = 0).
		try {
			$author_id_new = is_array( $author_row ) ? $this->get_or_create_user( $author_row, $usermeta_rows, $source_hostname ) : null;
		} catch ( \Exception $e ) {
			Logger::instance()->log_brief_and_verbose(
				LogLevel::ERROR,
				sprintf( 'import_author get_or_create_user error: %s', $e->getMessage() ),
				[
					'id_old'        => $id_old,
					'id_new'        => $post_id,
					'author_row'    => $author_row,
					'usermeta_rows' => $usermeta_rows,
				] 
			);
			$author_id_new = null;
		}

		// Some source posts might have author value 0 or invalid author.
		if ( is_null( $author_id_new ) ) {
			$author_id_new = 0;
		}

		// Update inserted Post's Author.
		if ( $author_id_new != $author_id_old ) {
			try {
				$this->update_post_author( $post_id, $author_id_new );
			} catch ( \Exception $e ) {
				Logger::instance()->log_brief_and_verbose(
					LogLevel::ERROR,
					sprintf( 'import_author update_post_author error: %s', $e->getMessage() ),
					[
						'id_old'        => $id_old,
						'id_new'        => $post_id,
						'author_id_new' => $author_id_new,
					] 
				);
			}
		}
	}

	/**
	 * Imports comments and comment meta, and updates comment parent IDs.
	 *
	 * @param array  $data            Post data array.
	 * @param int    $post_id         New post ID.
	 * @param string $source_hostname Source hostname.
	 */
	private function import_comments( array $data, int $post_id, string $source_hostname ): void {
		$id_old = $data[ ContentDiffLogic::DATAKEY_POST ]['ID'];

		// Insert all comments and collect old_id => new_id mapping.
		$comment_ids_map = [];
		foreach ( $data[ ContentDiffLogic::DATAKEY_COMMENTS ] as $comment_row ) {
			$new_comment_id = $this->import_single_comment( $comment_row, $data, $post_id, $id_old, $source_hostname );
			if ( null !== $new_comment_id ) {
				$comment_ids_map[ (int) $comment_row['comment_ID'] ] = $new_comment_id;
			}
		}

		// Update parent IDs for all inserted comments.
		$this->update_imported_comment_parents( $comment_ids_map, $data[ ContentDiffLogic::DATAKEY_COMMENTS ], $id_old, $post_id );
	}

	/**
	 * Imports a single comment with its user and commentmeta.
	 *
	 * @param array  $comment_row     Comment row from live DB.
	 * @param array  $data            Full post data array.
	 * @param int    $post_id         New post ID.
	 * @param int    $id_old          Original post ID (for logging).
	 * @param string $source_hostname Source hostname.
	 *
	 * @return int|null New comment ID, or null on error.
	 */
	private function import_single_comment( array $comment_row, array $data, int $post_id, int $id_old, string $source_hostname ): ?int {
		$comment_id_old = (int) $comment_row['comment_ID'];

		// Get or create Comment User.
		$comment_user_id_new = $this->get_comment_user_id( $comment_row, $data, $post_id, $id_old, $source_hostname );

		// Insert Comment and Comment Metas.
		$commentmeta_rows = $this->filter_array_elements( $data[ ContentDiffLogic::DATAKEY_COMMENTMETA ], 'comment_id', $comment_id_old );
		try {
			$comment_id_new = $this->insert_comment( $comment_row, $post_id, $comment_user_id_new );
			foreach ( $commentmeta_rows as $commentmeta_row ) {
				$this->insert_commentmeta_row( $commentmeta_row, $comment_id_new );
			}
			return $comment_id_new;
		} catch ( \Exception $e ) {
			Logger::instance()->log_brief_and_verbose(
				LogLevel::ERROR,
				sprintf( 'import_single_comment error: %s', $e->getMessage() ),
				[
					'id_old'              => $id_old,
					'id_new'              => $post_id,
					'comment_id_old'      => $comment_id_old,
					'comment_user_id_new' => $comment_user_id_new,
					'comment_row'         => $comment_row,
					'commentmeta_rows'    => $commentmeta_rows,
				]
			);
			return null;
		}
	}

	/**
	 * Gets or creates the user for a comment.
	 *
	 * @param array  $comment_row     Comment row from live DB.
	 * @param array  $data            Full post data array.
	 * @param int    $post_id         New post ID (for logging).
	 * @param int    $id_old          Original post ID (for logging).
	 * @param string $source_hostname Source hostname.
	 *
	 * @return int User ID (0 if no user or user couldn't be created).
	 */
	private function get_comment_user_id( array $comment_row, array $data, int $post_id, int $id_old, string $source_hostname ): int {
		$comment_user_id_old = (int) $comment_row['user_id'];
		$comment_id_old      = (int) $comment_row['comment_ID'];

		if ( 0 === $comment_user_id_old ) {
			return 0;
		}

		$comment_user_row      = $this->filter_array_element( $data[ ContentDiffLogic::DATAKEY_USERS ], 'ID', $comment_user_id_old );
		$comment_usermeta_rows = ! is_null( $comment_user_row ) ? $this->filter_array_elements( $data[ ContentDiffLogic::DATAKEY_USERMETA ], 'user_id', $comment_user_row['ID'] ) : [];

		try {
			$comment_user_id_new = ! is_null( $comment_user_row ) ? $this->get_or_create_user( $comment_user_row, $comment_usermeta_rows, $source_hostname ) : null;
		} catch ( \Exception $e ) {
			Logger::instance()->log_brief_and_verbose(
				LogLevel::ERROR,
				sprintf( 'get_comment_user_id error: %s', $e->getMessage() ),
				[
					'id_old'                => $id_old,
					'id_new'                => $post_id,
					'comment_id_old'        => $comment_id_old,
					'comment_user_row'      => $comment_user_row,
					'comment_usermeta_rows' => $comment_usermeta_rows,
				]
			);
			$comment_user_id_new = null;
		}

		// If user couldn't be found/created, default to 0.
		return $comment_user_id_new ?? 0;
	}

	/**
	 * Updates parent IDs for all imported comments.
	 *
	 * @param array $comment_ids_map Map of old comment ID => new comment ID.
	 * @param array $comments_data   Original comments data array.
	 * @param int   $id_old          Original post ID (for logging).
	 * @param int   $post_id         New post ID (for logging).
	 */
	private function update_imported_comment_parents( array $comment_ids_map, array $comments_data, int $id_old, int $post_id ): void {
		foreach ( $comment_ids_map as $comment_id_old => $comment_id_new ) {
			$comment_row        = $this->filter_array_element( $comments_data, 'comment_ID', $comment_id_old );
			$comment_parent_old = $comment_row['comment_parent'];
			$comment_parent_new = $comment_ids_map[ $comment_parent_old ] ?? null;

			// Only update if parent exists and has changed.
			if ( ( $comment_parent_old > 0 ) && $comment_parent_new && ( $comment_parent_old != $comment_parent_new ) ) {
				try {
					$this->update_comment_parent( $comment_id_new, $comment_parent_new );
				} catch ( \Exception $e ) {
					Logger::instance()->log_brief_and_verbose(
						LogLevel::ERROR,
						sprintf( 'update_imported_comment_parents error: %s', $e->getMessage() ),
						[
							'id_old'             => $id_old,
							'id_new'             => $post_id,
							'comment_id_new'     => $comment_id_new,
							'comment_parent_new' => $comment_parent_new,
						]
					);
				}
			}
		}
	}

	/**
	 * Imports taxonomies and term relationships.
	 *
	 * @param array  $data                  Post data array.
	 * @param int    $post_id               New post ID.
	 * @param string $live_table_prefix     Live database table prefix.
	 * @param array  $taxonomies_to_migrate List of taxonomies allowed to be migrated.
	 * @param string $source_hostname       Source hostname for old_id meta.
	 */
	private function import_taxonomies( array $data, int $post_id, string $live_table_prefix, array $taxonomies_to_migrate, string $source_hostname ): void {
		$id_old = $data[ ContentDiffLogic::DATAKEY_POST ]['ID'];

		// Track inserted term_taxonomy_ids to avoid duplicates.
		$inserted_term_taxonomy_ids = [];

		foreach ( $data[ ContentDiffLogic::DATAKEY_TERMRELATIONSHIPS ] as $term_relationship_row ) {
			$inserted_term_taxonomy_ids = $this->import_single_term_relationship(
				$term_relationship_row,
				$data,
				$post_id,
				$id_old,
				$live_table_prefix,
				$taxonomies_to_migrate,
				$inserted_term_taxonomy_ids,
				$source_hostname
			);
		}
	}

	/**
	 * Imports a single term relationship with its term and termmeta.
	 *
	 * @param array  $term_relationship_row      Term relationship row from live DB.
	 * @param array  $data                       Full post data array.
	 * @param int    $post_id                    New post ID.
	 * @param int    $id_old                     Original post ID (for logging).
	 * @param string $live_table_prefix          Live database table prefix.
	 * @param array  $taxonomies_to_migrate      List of taxonomies allowed to be migrated.
	 * @param array  $inserted_term_taxonomy_ids Tracks already inserted term_taxonomy_ids.
	 * @param string $source_hostname            Source hostname for old_id meta.
	 *
	 * @return array Updated $inserted_term_taxonomy_ids array.
	 */
	private function import_single_term_relationship(
		array $term_relationship_row,
		array $data,
		int $post_id,
		int $id_old,
		string $live_table_prefix,
		array $taxonomies_to_migrate,
		array $inserted_term_taxonomy_ids,
		string $source_hostname
	): array {
		$live_term_taxonomy_id  = $term_relationship_row['term_taxonomy_id'];
		$live_term_taxonomy_row = $this->filter_array_element( $data[ ContentDiffLogic::DATAKEY_TERMTAXONOMY ], 'term_taxonomy_id', $live_term_taxonomy_id );
		$live_term_id           = $live_term_taxonomy_row['term_id'];
		$live_term_row          = $this->filter_array_element( $data[ ContentDiffLogic::DATAKEY_TERMS ], 'term_id', $live_term_id );
		$taxonomy_name          = $live_term_taxonomy_row['taxonomy'];

		// Skip taxonomies not in the allowed list.
		if ( ! in_array( $taxonomy_name, $taxonomies_to_migrate, true ) ) {
			return $inserted_term_taxonomy_ids;
		}

		// Validate live term row, it could be missing or invalid.
		if ( is_null( $live_term_row ) ) {
			Logger::instance()->log_brief_and_verbose(
				LogLevel::ERROR,
				'import_single_term_relationship: term_id does not exist in live DB term table, skipping',
				[
					'id_old'                 => $id_old,
					'id_new'                 => $post_id,
					'live_term_taxonomy_id'  => $live_term_taxonomy_id,
					'live_term_taxonomy_row' => $live_term_taxonomy_row,
					'live_term_id'           => $live_term_id,
					'live_term_row'          => $live_term_row,
				]
			);
			return $inserted_term_taxonomy_ids;
		}

		// Get or create term (works for both hierarchical and non-hierarchical - non-hierarchical just has parent=0).
		if ( ! isset( $this->taxonomy_term_id_map[ $live_term_id ] ) ) {
			// Merge term data (name, slug) with term_taxonomy data for get_or_create_local_term.
			$merged_term_data = array_merge( $live_term_taxonomy_row, $live_term_row );
			$local_term_id    = $this->get_or_create_local_term( $merged_term_data, $data, $post_id, $id_old, $live_table_prefix, $source_hostname );
			if ( null === $local_term_id ) {
				return $inserted_term_taxonomy_ids; // Error already logged in get_or_create_local_term.
			}
			$this->taxonomy_term_id_map[ $live_term_id ] = $local_term_id;
		}

		$local_term_id = $this->taxonomy_term_id_map[ $live_term_id ];

		// Get local term_taxonomy data.
		$local_term_taxonomy_data = $this->get_term_and_taxonomy_array( $this->wpdb->prefix, [ 'term_id' => $local_term_id ], $taxonomy_name );
		if ( is_null( $local_term_taxonomy_data ) ) {
			Logger::instance()->log_brief_and_verbose(
				LogLevel::ERROR,
				'import_single_term_relationship: local term_taxonomy not found after creation',
				[
					'id_old'                   => $id_old,
					'id_new'                   => $post_id,
					'live_term_id'             => $live_term_id,
					'live_term_taxonomy_id'    => $live_term_taxonomy_id,
					'live_term_taxonomy_row'   => $live_term_taxonomy_row,
					'local_term_id'            => $local_term_id,
					'local_term_taxonomy_data' => $local_term_taxonomy_data,
					'taxonomy'                 => $taxonomy_name,
				]
			);
			return $inserted_term_taxonomy_ids;
		}

		$local_term_taxonomy_id    = $local_term_taxonomy_data['term_taxonomy_id'];
		$local_term_taxonomy_count = $local_term_taxonomy_data['count'];

		/**
		 * We need to check if the same $local_term_taxonomy_id has already been inserted. This can happen if there are two
		 * terms which have the same name but different case, e.g. first term with name 'reseñas' and second with name 'Reseñas'.
		 * WP distinguishes these Terms, but we should clean them up as we get the chance and merge them.
		 */
		$term_relationship_is_double = in_array( $local_term_taxonomy_id, $inserted_term_taxonomy_ids );

		if ( ! is_null( $local_term_taxonomy_id ) && ! $term_relationship_is_double ) {
			// Insert the Term Relationship record.
			$this->insert_term_relationship( $post_id, $local_term_taxonomy_id );

			// Increment wp_term_taxonomy.count, and update wp_term_taxonomy.description.
			$this->wpdb->update(
				$this->wpdb->term_taxonomy,
				[
					'count'       => ( (int) $local_term_taxonomy_count + 1 ),
					'description' => $live_term_taxonomy_row['description'],
				],
				[ 'term_taxonomy_id' => $local_term_taxonomy_id ]
			);
			// Not handling update error, because terms will get recounted when migration is finished.

			$inserted_term_taxonomy_ids[] = $local_term_taxonomy_id;
		}

		return $inserted_term_taxonomy_ids;
	}

	/**
	 * Gets or creates a local term from live term taxonomy data.
	 *
	 * @param array  $live_term_taxonomy_row Live term taxonomy row.
	 * @param array  $data                   Full post data array.
	 * @param int    $post_id                New post ID (for logging).
	 * @param int    $id_old                 Original post ID (for logging).
	 * @param string $live_table_prefix      Live database table prefix.
	 * @param string $source_hostname        Source hostname for old_id meta.
	 *
	 * @return int|null Local term ID, or null on error.
	 */
	private function get_or_create_local_term( array $live_term_taxonomy_row, array $data, int $post_id, int $id_old, string $live_table_prefix, string $source_hostname ): ?int {
		$taxonomy_name = $live_term_taxonomy_row['taxonomy'];
		$live_term_id  = $live_term_taxonomy_row['term_id'];

		try {
			// Register taxonomy if not registered (init action not executed at this point).
			$this->ensure_taxonomy_registered( $taxonomy_name, $live_term_taxonomy_row );

			// Build and recreate the taxonomy tree.
			$live_tree    = $this->get_taxonomy_tree( $live_table_prefix, $live_term_taxonomy_row );
			$created_tree = $this->get_or_create_taxonomy_tree( $this->wpdb->prefix, $live_tree );

			// Import termmeta for this term (only once per term across all posts).
			$this->import_termmeta( $data, $live_term_id, $created_tree['term_id'], $id_old, $post_id, $source_hostname );

			return $created_tree['term_id'];
		} catch ( \Exception $e ) {
			Logger::instance()->log_brief_and_verbose(
				LogLevel::ERROR,
				sprintf( 'get_or_create_local_term error: %s', $e->getMessage() ),
				[
					'id_old'                 => $id_old,
					'id_new'                 => $post_id,
					'live_term_id'           => $live_term_id,
					'taxonomy'               => $taxonomy_name,
					'live_term_taxonomy_row' => $live_term_taxonomy_row,
				]
			);
			return null;
		}
	}

	/**
	 * Ensures a taxonomy is registered if it doesn't exist.
	 *
	 * @param string $taxonomy_name          Taxonomy name.
	 * @param array  $live_term_taxonomy_row Live term taxonomy row (used to determine if hierarchical).
	 */
	private function ensure_taxonomy_registered( string $taxonomy_name, array $live_term_taxonomy_row ): void {
		if ( taxonomy_exists( $taxonomy_name ) ) {
			return;
		}

		// Check if live taxonomy is hierarchical by looking at parent field in live data.
		$is_hierarchical = ! empty( $live_term_taxonomy_row['parent'] ) && '0' != $live_term_taxonomy_row['parent'];
		$registered      = register_taxonomy( $taxonomy_name, 'post', [ 'hierarchical' => $is_hierarchical ] );

		if ( is_wp_error( $registered ) ) {
			Logger::instance()->log(
				Logger::OUTPUT_FILE,
				LogLevel::WARNING,
				sprintf( 'Failed to register taxonomy %s: %s', $taxonomy_name, $registered->get_error_message() ),
				[
					'taxonomy'        => $taxonomy_name,
					'is_hierarchical' => $is_hierarchical,
				]
			);
			// Don't throw - continue processing, may work anyway.
		}
	}

	/**
	 * Imports termmeta for a term.
	 *
	 * @param array  $data            Post data array containing termmeta.
	 * @param int    $live_term_id    Term ID from live DB.
	 * @param int    $local_term_id   New local term ID.
	 * @param int    $id_old          Original post ID (for logging).
	 * @param int    $post_id         New post ID (for logging).
	 * @param string $source_hostname Source hostname for old_id meta.
	 */
	private function import_termmeta( array $data, int $live_term_id, int $local_term_id, int $id_old, int $post_id, string $source_hostname ): void {
		// Skip if termmeta has already been imported for this term in this run.
		if ( isset( $this->termmeta_imported[ $live_term_id ] ) ) {
			return;
		}

		// Get termmeta rows for this term.
		$termmeta_rows = $this->filter_array_elements( $data[ ContentDiffLogic::DATAKEY_TERMMETA ], 'term_id', $live_term_id );

		foreach ( $termmeta_rows as $termmeta_row ) {
			try {
				$this->insert_termmeta_row( $termmeta_row, $local_term_id );
			} catch ( \Exception $e ) {
				Logger::instance()->log_brief_and_verbose(
					LogLevel::ERROR,
					sprintf( 'import_termmeta insert_termmeta_row error: %s', $e->getMessage() ),
					[
						'id_old'        => $id_old,
						'id_new'        => $post_id,
						'live_term_id'  => $live_term_id,
						'local_term_id' => $local_term_id,
						'termmeta_row'  => $termmeta_row,
					]
				);
			}
		}

		// Save old_id termmeta for Migration Data Consistency Standard.
		$meta_key = ContentDiffLogic::get_old_id_meta_key( $source_hostname );

		// Check if already attributed to this source (idempotent - avoids duplicate warnings).
		$existing_meta = get_term_meta( $local_term_id, $meta_key, true );
		if ( ! empty( $existing_meta ) ) {
			// Already attributed from this source, skip.
			$this->termmeta_imported[ $live_term_id ] = true;
			return;
		}

		// Check if term has old_id meta from ANY other source (indicates merge from multiple sources).
		// phpcs:disable -- WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$any_old_id_meta = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT meta_id FROM {$this->wpdb->termmeta} WHERE term_id = %d AND meta_key LIKE %s LIMIT 1",
				$local_term_id,
				ContentDiffLogic::SAVED_META_LIVE_ID_PREFIX . '%'
			)
		);
		// phpcs:enable

		// Insert the source-specific old_id meta.
		$inserted = $this->wpdb->insert(
			$this->wpdb->termmeta,
			[
				'term_id'    => $local_term_id,
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $live_term_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		if ( 1 !== $inserted ) {
			Logger::instance()->log_brief_and_verbose(
				LogLevel::ERROR,
				sprintf( 'Failed to save old_id termmeta for term %d. DB error: %s', $local_term_id, $this->wpdb->last_error ),
				[
					'local_term_id' => $local_term_id,
					'live_term_id'  => $live_term_id,
					'meta_key'      => $meta_key,
				]
			);
		}

		// Log if this term is being merged from another source.
		$term     = get_term( $local_term_id );
		$taxonomy = $term instanceof \WP_Term ? $term->taxonomy : '(unknown)';

		if ( ! empty( $any_old_id_meta ) ) {
			$term_name = $term instanceof \WP_Term ? $term->name : '(unknown)';
			// Log all the cases to file with full context.
			Logger::instance()->log(
				Logger::OUTPUT_FILE,
				LogLevel::DEBUG,
				'merge_term: Term with same name and taxonomy already exists on local, merging/reusing the local term.',
				[
					'term_name'       => $term_name,
					'taxonomy'        => $taxonomy ,
					'local_term_id'   => $local_term_id,
					'live_term_id'    => $live_term_id,
					'source_hostname' => $source_hostname,
				] 
			);
			// Log DEBUG info to CLI only once with the first example.
			static $cli_warned_term_merge = false;
			if ( false === $cli_warned_term_merge ) {
				Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, sprintf( '- merge_term: Some terms already exist on local and are being merged/reused, and an additional meta is set for those terms. See %s for full list (first example: term_name `%s`, taxonomy `%s`, live_term_id=%d, local_term_id=%d).', Logger::instance()->get_log_file_path(), $term_name, $taxonomy, $live_term_id, $local_term_id ) );
				$cli_warned_term_merge = true;
			}

			// Track merged term for reports.
			if ( null !== $this->run_state ) {
				$this->run_state->append_imported_term(
					[
						'term_id_old' => $live_term_id,
						'term_id_new' => $local_term_id,
						'taxonomy'    => $taxonomy,
						'status'      => 'merged',
					]
				);
			}
		} else { // phpcs:ignore -- allow lonely if inside this if/else block, for clarity around test environment Universal.ControlStructures.DisallowLonelyIf.Found.
			// Track imported term for reports (first time this term is encountered from any source).
			if ( null !== $this->run_state ) {
				$this->run_state->append_imported_term(
					[
						'term_id_old' => $live_term_id,
						'term_id_new' => $local_term_id,
						'taxonomy'    => $taxonomy,
						'status'      => 'imported',
					]
				);
			}
		}

		// Mark this term's termmeta as imported.
		$this->termmeta_imported[ $live_term_id ] = true;
	}

	/**
	 * Inserts Post Meta.
	 *
	 * @param array $postmeta_row `postmeta` row.
	 * @param int   $post_id      Post ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted meta_id.
	 */
	private function insert_postmeta_row( array $postmeta_row, int $post_id ): int {
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
	 * Gets existing user by login or creates new one with usermeta.
	 *
	 * @param array  $user_row        User row data from live DB.
	 * @param array  $usermeta_rows   User meta rows from live DB.
	 * @param string $source_hostname Source hostname.
	 *
	 * @return int|null User ID (existing or new), or null if user_row is invalid.
	 */
	public function get_or_create_user( array $user_row, array $usermeta_rows, string $source_hostname ): ?int {
		if ( empty( $user_row ) || ! isset( $user_row['user_login'] ) ) {
			return null;
		}

		// Check if user already exists by login.
		$existing_user = get_user_by( 'login', $user_row['user_login'] );
		if ( $existing_user instanceof WP_User ) {
			$local_user_id = (int) $existing_user->ID;
			$meta_key      = ContentDiffLogic::get_old_id_meta_key( $source_hostname );

			// Check if already attributed to this source (idempotent).
			$existing_meta = get_user_meta( $local_user_id, $meta_key, true );
			if ( empty( $existing_meta ) ) {
				// Add source-specific old_id meta for this merged user.
				update_user_meta( $local_user_id, $meta_key, $user_row['ID'] );

				// Log all cases to file with full context.
				Logger::instance()->log(
					Logger::OUTPUT_FILE,
					LogLevel::DEBUG,
					'merge_user: User with same login already exists on local, merging/reusing the local user.',
					[
						'user_login'      => $user_row['user_login'],
						'live_user_id'    => $user_row['ID'],
						'local_user_id'   => $local_user_id,
						'source_hostname' => $source_hostname,
					] 
				);
				// Log DEBUG info to CLI only once with the first example.
				static $cli_warned_user_merge = false;
				if ( false === $cli_warned_user_merge ) {
					Logger::instance()->log( Logger::OUTPUT_CLI, LogLevel::DEBUG, sprintf( '- merge_user: Some users already exist on local (same login) and are being merged/reused, and an additional meta is set for those users. See %s for full list (first example: user_login=`%s`, live_user_id=%d, local_user_id=%d).', Logger::instance()->get_log_file_path(), $user_row['user_login'], $user_row['ID'], $local_user_id ) );
					$cli_warned_user_merge = true;
				}

				// Track merged user for reports.
				if ( null !== $this->run_state ) {
					$this->run_state->append_imported_user(
						[
							'id_old' => (int) $user_row['ID'],
							'id_new' => $local_user_id,
							'status' => 'merged',
						]
					);
				}
			}

			return $local_user_id;
		}

		// Insert new user with usermeta.
		$new_user_id = $this->insert_user( $user_row, $source_hostname );
		foreach ( $usermeta_rows as $usermeta_row ) {
			$this->insert_usermeta_row( $usermeta_row, $new_user_id );
		}

		return $new_user_id;
	}

	/**
	 * Inserts a User.
	 *
	 * @param array  $user_row        `user` row.
	 * @param string $source_hostname Source hostname.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted User ID.
	 */
	private function insert_user( array $user_row, string $source_hostname ): int {
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
		$inserted = $this->wpdb->insert(
			$this->wpdb->usermeta,
			[
				'user_id'    => $new_user_id,
				'meta_key'   => ContentDiffLogic::get_old_id_meta_key( $source_hostname ),
				'meta_value' => $old_user_id,
			]
		);
		// phpcs:enable
		if ( 1 !== $inserted ) {
			$context = [
				'new_user_id' => $new_user_id,
				'old_user_id' => $old_user_id,
			];
			Logger::instance()->log_brief_and_verbose( LogLevel::ERROR, sprintf( 'Failed to insert old_id usermeta for new user ID %d which may cause duplicate users. DB error: %s', $new_user_id, $this->wpdb->last_error ), $context );
		}

		// Track imported user for reports.
		if ( null !== $this->run_state ) {
			$this->run_state->append_imported_user(
				[
					'id_old' => $old_user_id,
					'id_new' => $new_user_id,
					'status' => 'imported',
				]
			);
		}

		return $new_user_id;
	}

	/**
	 * Inserts User Meta.
	 *
	 * @param array $usermeta_row `usermeta` row.
	 * @param int   $user_id      User ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted umeta_id.
	 */
	public function insert_usermeta_row( array $usermeta_row, int $user_id ): int {
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
	 * @param array $comment_row `comment` row.
	 * @param int   $new_post_id Post ID.
	 * @param int   $new_user_id User ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted comment_id.
	 */
	private function insert_comment( array $comment_row, int $new_post_id, int $new_user_id ): int {
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
	private function insert_commentmeta_row( array $commentmeta_row, int $new_comment_id ): int {
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
	 * Inserts Term Meta with an updated term_id.
	 *
	 * @param array $termmeta_row Termmeta row from live DB.
	 * @param int   $new_term_id  New Term ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted meta_id.
	 */
	private function insert_termmeta_row( array $termmeta_row, int $new_term_id ): int {
		$insert_termmeta_row = $termmeta_row;
		unset( $insert_termmeta_row['meta_id'] );
		$insert_termmeta_row['term_id'] = $new_term_id;

		$inserted = $this->wpdb->insert( $this->wpdb->termmeta, $insert_termmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term meta, $new_term_id %d, $termmeta_row %s', $new_term_id, wp_json_encode( $termmeta_row ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Updates a Comment's parent ID.
	 *
	 * @param int $comment_id         Comment ID.
	 * @param int $comment_parent_new new Comment Parent ID.
	 *
	 * @throws \RuntimeException In case update fails.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	private function update_comment_parent( int $comment_id, int $comment_parent_new ): int|false {
		$updated = $this->wpdb->update( $this->wpdb->comments, [ 'comment_parent' => $comment_parent_new ], [ 'comment_ID' => $comment_id ] );
		if ( 1 != $updated ) {
			throw new \RuntimeException( sprintf( 'Error updating comment parent, $comment_id %d, $comment_parent_new %d', $comment_id, $comment_parent_new ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $updated;
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
	private function wp_insert_term( string $term_name, string $taxonomy, array $args = [] ): array|\WP_Error {
		return \wp_insert_term( $term_name, $taxonomy, $args );
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
	private function insert_term_relationship( int $object_id, int $term_taxonomy_id ): int {
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
	 * @param int $post_id       Post ID.
	 * @param int $new_author_id New Author ID.
	 *
	 * @throws \RuntimeException In case update fails.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	private function update_post_author( int $post_id, int $new_author_id ): int|false {
		$updated = $this->wpdb->update( $this->wpdb->posts, [ 'post_author' => $new_author_id ], [ 'ID' => $post_id ] );
		if ( 1 != $updated ) {
			throw new \RuntimeException( sprintf( 'Error updating post author, $post_id %d, $new_author_id %d', $post_id, $new_author_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $updated;
	}

	/**
	 * Gets a term and taxonomy array from database.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $where        Where clause conditions.
	 * @param string $taxonomy     Taxonomy name.
	 *
	 * @return array|null Term and taxonomy data, or null if not found.
	 */
	private function get_term_and_taxonomy_array( string $table_prefix, array $where, string $taxonomy ): ?array {

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
				FROM {$table_terms} AS t
				INNER JOIN {$table_term_taxonomy} AS tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s {$query_and_clause}",
				$taxonomy,
				$query_and_parameter
			),
			ARRAY_A
		);
		// phpcs:enable

		return $term_taxonomy_data;
	}

	/**
	 * Filters a multidimensional array and searches for a subarray element containing a key and value.
	 *
	 * @param array $data  Array being searched and filtered.
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for.
	 *
	 * @return array|null An array which matches the $key $value filter, or null if nothing is found.
	 */
	private function filter_array_element( array $data, mixed $key, mixed $value ): ?array {
		foreach ( $data as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				return $subarray;
			}
		}

		return null;
	}

	/**
	 * Filters a multidimensional array and searches for all subarray elements containing a key and value.
	 *
	 * @param array $data  Array being searched and filtered.
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for.
	 *
	 * @return array An array with sub-arrays which match the $key $value filter, or an empty array if nothing is found.
	 */
	private function filter_array_elements( array $data, mixed $key, mixed $value ): array {
		$found = [];
		foreach ( $data as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				$found[] = $subarray;
			}
		}

		return $found;
	}

	/**
	 * Fixes hierarchical taxonomies which have invalid/nonexistent parent term_ids by setting them to 0.
	 *
	 * @param string $table_prefix        DB table prefix.
	 * @param array  $taxonomies_to_check Taxonomies to check for invalid parents.
	 *
	 * @return array List of term_taxonomy_ids that were fixed, empty if none.
	 */
	public function fix_hierarchical_taxonomies_parents( string $table_prefix, array $taxonomies_to_check ): array {
		// Get taxonomies with invlid/nonexistent parents.
		$terms         = esc_sql( $table_prefix . 'terms' );
		$term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );
		// phpcs:disable -- wpdb::prepare used and query fully sanitized.
		$taxonomy_placeholders   = implode( ', ', array_fill( 0, count( $taxonomies_to_check ), '%s' ) );
		$hierarchical_taxonomies = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.parent
				FROM {$terms} t
				JOIN {$term_taxonomy} tt
					ON t.term_id = tt.term_id AND tt.taxonomy IN ($taxonomy_placeholders) AND parent <> 0
				LEFT JOIN {$terms} ttparent
					ON ttparent.term_id = tt.parent
				WHERE ttparent.term_id IS NULL;",
				...$taxonomies_to_check
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( empty( $hierarchical_taxonomies ) ) {
			return [];
		}

		// Reset their parents to 0.
		$term_taxonomy_ids = array_column( $hierarchical_taxonomies, 'term_taxonomy_id' );
		if ( empty( $term_taxonomy_ids ) ) {
			return [];
		}
		$term_taxonomy_ids_placeholders = implode( ',', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$term_taxonomy                  = esc_sql( $table_prefix . 'term_taxonomy' );
		// phpcs:disable -- wpdb::prepare used and query fully sanitized.
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$term_taxonomy} SET parent = 0 WHERE term_taxonomy_ID IN ( {$term_taxonomy_ids_placeholders} );",
				...$term_taxonomy_ids
			)
		);
		// phpcs:enable

		return $hierarchical_taxonomies;
	}

	/**
	 * Fetches the hierarchical taxonomy's tree by retrieving all parent taxonomies down to the top parent.
	 *
	 * @param string $table_prefix   DB table prefix.
	 * @param array  $taxonomy_array Taxonomy data array.
	 *
	 * @return array Nested array of taxonomies where 'parent' is either another taxonomy array or '0'.
	 */
	private function get_taxonomy_tree( string $table_prefix, array $taxonomy_array ): array {
		// Start building the taxonomy tree with this taxonomy array, and keep adding parents until reaching the top 'parent' key.
		$taxonomy_tree = $taxonomy_array;

		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		$parent_term_id = $taxonomy_array['parent'];
		if ( 0 != $parent_term_id ) {
			// phpcs:disable -- wpdb::prepare used.
			$parent_row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
					FROM {$table_terms} t
					JOIN {$table_term_taxonomy} tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					AND t.term_id = %s
					ORDER BY tt.parent;",
					[ $taxonomy_array['taxonomy'], $parent_term_id ]
				),
				ARRAY_A
			);
			// phpcs:enable

			if ( 0 == $parent_row['parent'] ) {
				$taxonomy_tree['parent'] = $parent_row;
			} else {
				$taxonomy_tree['parent'] = $this->get_taxonomy_tree( $table_prefix, $parent_row );
			}
		}

		return $taxonomy_tree;
	}

	/**
	 * Rebuilds the full tree of a hierarchical taxonomy. Gets existing or creates new.
	 *
	 * @param string $table_prefix               DB table prefix.
	 * @param array  $taxonomy_tree Nested taxonomy array to rebuild.
	 *
	 * @return array Rebuilt taxonomy tree.
	 */
	private function get_or_create_taxonomy_tree( string $table_prefix, array $taxonomy_tree ): array {
		// If this is the top parent taxonomy, get or create it.
		if ( 0 == $taxonomy_tree['parent'] ) {
			$taxonomy_top_parent_row     = $this->get_taxonomy_array_by_name_and_parent( $table_prefix, $taxonomy_tree['name'], $taxonomy_tree['taxonomy'], 0 );
			$taxonomy_top_parent_term_id = $taxonomy_top_parent_row['term_id'] ?? null;
			if ( ! $taxonomy_top_parent_term_id ) {
				$taxonomy_top_parent_term_id = $this->wp_insert_or_update_term(
					$taxonomy_tree['name'],
					$taxonomy_tree['description'],
					0,
					$taxonomy_tree['taxonomy']
				);
				if ( is_wp_error( $taxonomy_top_parent_term_id ) ) {
					Logger::instance()->log_brief_and_verbose(
						LogLevel::ERROR,
						sprintf( 'import_taxonomies wp_insert_or_update_term error: %s', $taxonomy_top_parent_term_id->get_error_message() ),
						[
							'hierarchical_taxonomy_tree' => $taxonomy_tree,
							'term_parent'                => 0,
						] 
					);
				}
			}
			return $this->get_term_and_taxonomy_array(
				$table_prefix,
				[ 'term_id' => $taxonomy_top_parent_term_id ],
				$taxonomy_tree['taxonomy']
			);
		}

		// Recursively build parent tree first.
		$current_parent_tree = $this->get_or_create_taxonomy_tree( $table_prefix, $taxonomy_tree['parent'] );

		// Get or create this taxonomy.
		$taxonomy_row     = $this->get_taxonomy_array_by_name_and_parent( $table_prefix, $taxonomy_tree['name'], $taxonomy_tree['taxonomy'], $current_parent_tree['term_id'] );
		$taxonomy_term_id = $taxonomy_row['term_id'] ?? null;
		if ( ! $taxonomy_term_id ) {
			$taxonomy_term_id = $this->wp_insert_or_update_term(
				$taxonomy_tree['name'],
				$taxonomy_tree['description'],
				$current_parent_tree['term_id'],
				$taxonomy_tree['taxonomy']
			);
			if ( is_wp_error( $taxonomy_term_id ) ) {
				Logger::instance()->log_brief_and_verbose(
					LogLevel::ERROR,
					sprintf( 'import_taxonomies wp_insert_or_update_term error: %s', $taxonomy_term_id->get_error_message() ),
					[
						'hierarchical_taxonomy_tree' => $taxonomy_tree,
						'current_parent_tree'        => $current_parent_tree,
					] 
				);
			}
		}
		$taxonomy = $this->get_term_and_taxonomy_array(
			$table_prefix,
			[ 'term_id' => $taxonomy_term_id ],
			$taxonomy_tree['taxonomy']
		);

		$rebuilt_taxonomy_tree           = $taxonomy;
		$rebuilt_taxonomy_tree['parent'] = $current_parent_tree;

		return $rebuilt_taxonomy_tree;
	}

	/**
	 * Gets hierarchical taxonomy by term name and parent.
	 *
	 * @param string $table_prefix    DB table prefix.
	 * @param string $term_name       Term name.
	 * @param string $taxonomy_name   Taxonomy type/name.
	 * @param int    $taxonomy_parent Parent term_id.
	 *
	 * @return array|null Taxonomy data or null.
	 */
	private function get_taxonomy_array_by_name_and_parent( string $table_prefix, string $term_name, string $taxonomy_name, $taxonomy_parent ): ?array {
		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		// phpcs:disable -- wpdb::prepare used.
		$taxonomy_data = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
				FROM $table_terms t
				JOIN $table_term_taxonomy tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				AND tt.parent = %s
				AND t.name = %s;",
				$taxonomy_name,
				$taxonomy_parent,
				$term_name
			),
			ARRAY_A
		);
		// phpcs:enable

		return $taxonomy_data;
	}

	/**
	 * Creates or updates a term in a taxonomy.
	 *
	 * @param string $term_name        Term name.
	 * @param string $term_description Term description.
	 * @param int    $term_parent      Parent term_id.
	 * @param string $taxonomy         Taxonomy name.
	 *
	 * @return int|\WP_Error Term ID on success, WP_Error on failure.
	 */
	private function wp_insert_or_update_term( string $term_name, string $term_description, int $term_parent, string $taxonomy ): int|\WP_Error {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.term_exists_term_exists
		$term_exists = term_exists( $term_name, $taxonomy, $term_parent );

		if ( ! $term_exists ) {
			$result = $this->wp_insert_term(
				$term_name,
				$taxonomy,
				[
					'description' => $term_description,
					'parent'      => $term_parent,
				]
			);

			if ( is_wp_error( $result ) ) {
				/** @var \WP_Error $result */
				return $result;
			}
			return (int) $result['term_id'];
		}

		$term_id     = (int) $term_exists['term_id'];
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
}
