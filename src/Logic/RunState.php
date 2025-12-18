<?php
/**
 * Manages migration run-state data and progress.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Logic;

/**
 * RunState keeps track of IDs/objects which needs to be migrated, and the progress of the migration.
 * If the migration is interrupted, it will be resumed based on this info.
 * This data is kept in formatted JSON/JSONL files in $run_state_dir path.
 */
class RunState {

	// Run-state filenames.
	public const FILE_NEW_IDS              = 'new_ids.json';
	public const FILE_MODIFIED_IDS         = 'modified_ids.json';
	public const FILE_MANIFEST             = 'manifest.json';
	public const FILE_IMPORTED_POSTS       = 'imported_posts.jsonl';
	public const FILE_UPDATED_PARENTS      = 'updated_parents.jsonl';
	public const FILE_UPDATED_FEATURED     = 'updated_featured.jsonl';
	public const FILE_UPDATED_BLOCKS       = 'updated_blocks.jsonl';
	public const FILE_DELETED_MODIFIED_IDS = 'deleted_modified_ids.jsonl';

	/**
	 * Run-state directory path.
	 *
	 * @var string
	 */
	private $run_state_dir;

	/**
	 * Constructor.
	 *
	 * @param string $run_state_dir Full path to run-state data directory, expected to be, '{--data-dir}/{--source-hostname}/run-state'.
	 */
	public function __construct( string $run_state_dir ) {
		$this->run_state_dir = rtrim( $run_state_dir, '/' );
		if ( ! is_dir( $this->run_state_dir ) ) {
			wp_mkdir_p( $this->run_state_dir );
		}
	}

	/**
	 * Writes new IDs to be migrated.
	 *
	 * @param array $ids Array of IDs.
	 *
	 * @return bool Success.
	 */
	public function write_new_ids( array $ids ): bool {
		return $this->write_json( self::FILE_NEW_IDS, $ids );
	}

	/**
	 * Gets new IDs to be migrated.
	 *
	 * @return array|null Array of IDs, or null if file doesn't exist.
	 */
	public function get_new_ids(): ?array {
		$new_ids = $this->read_json( self::FILE_NEW_IDS );
		if ( null === $new_ids ) {
			return null;
		}
		$new_ids = array_map( 'intval', $new_ids );
		return $new_ids;
	}

	/**
	 * Gets modified IDs ('live_id', 'local_id' pairs).
	 *
	 * @return array|null Array of modified ID pairs or null if file doesn't exist.
	 */
	public function read_modified_ids(): ?array {
		$modified_ids = $this->read_json( self::FILE_MODIFIED_IDS );
		if ( null === $modified_ids ) {
			return null;
		}
		array_map( 'intval', $modified_ids );
		return $modified_ids;
	}

	/**
	 * Gets a map of "old => new" modified IDs.
	 *
	 * @return array|null Keys are old live modified IDs, values are new local modified IDs, or null if file with modified IDs doesn't exist.
	 */
	public function get_modified_ids_map(): ?array {
		$modified_ids_data = $this->read_modified_ids();
		if ( null === $modified_ids_data ) {
			return null;
		}
		
		$ids_map = [];
		foreach ( $modified_ids_data as $entry ) {
			$ids_map[ $entry['live_id'] ] = $entry['local_id'];
		}

		return $ids_map;
	}

	/**
	 * Writes modified IDs.
	 *
	 * @param array $modified_ids Array of arrays with 'live_id' and 'local_id' keys.
	 *
	 * @return bool Success.
	 */
	public function write_modified_ids( array $modified_ids ): bool {
		return $this->write_json( self::FILE_MODIFIED_IDS, $modified_ids );
	}

	/**
	 * Writes migration manifest data.
	 * It's basically just a "migration Table of Contents",
	 * so that it's easy to review when the migration was done and what was migrated.
	 *
	 * @param array $manifest Manifest data.
	 *
	 * @return bool Success.
	 */
	public function write_manifest( array $manifest ): bool {
		return $this->write_json( self::FILE_MANIFEST, $manifest );
	}


	/**
	 * Appends a deleted modified ID record.
	 *
	 * @param array $deleted_data Deleted data with 'local_id' key.
	 *
	 * @return bool Success.
	 */
	public function append_deleted_modified_id( array $deleted_data ): bool {
		return $this->append_jsonl( self::FILE_DELETED_MODIFIED_IDS, $deleted_data );
	}

	/**
	 * Gets deleted modified IDs.
	 *
	 * @return array Array of deleted modified ID records.
	 */
	private function read_deleted_modified_ids(): array {
		return $this->read_jsonl( self::FILE_DELETED_MODIFIED_IDS );
	}

	/**
	 * Gets a map of "old => new" already deleted modified IDs from the run-state file.
	 *
	 * @return array|null Keys are old live deleted IDs, values are new local deleted IDs, or null if file with deleted modified IDs doesn't exist.
	 */
	public function get_deleted_modified_ids_map(): ?array {
		$deleted_modified_ids_data = $this->read_deleted_modified_ids();
		if ( null === $deleted_modified_ids_data ) {
			return null;
		}

		$deleted_modified_ids_map = [];
		foreach ( $deleted_modified_ids_data as $entry ) {
			$deleted_modified_ids_map[ (int) $entry['live_id'] ] = (int) $entry['local_id'];
		}

		return $deleted_modified_ids_map;
	}

	/**
	 * Gets imported posts.
	 *
	 * @return array Array of imported post records.
	 */
	private function read_imported_posts(): array {
		return $this->read_jsonl( self::FILE_IMPORTED_POSTS );
	}

	/**
	 * Gets a map of "old => new" already imported post IDs.
	 *
	 * @return array|null Keys are old live imported IDs, values are new local imported IDs, or null if file with imported posts doesn't exist.
	 */
	public function get_imported_post_ids_map(): ?array {
		$imported_posts_data = $this->read_imported_posts();
		if ( null === $imported_posts_data ) {
			return null;
		}
		$imported_post_ids_map = [];
		foreach ( $imported_posts_data as $entry ) {
			$imported_post_ids_map[ (int) $entry['id_old'] ] = (int) $entry['id_new'];
		}
		return $imported_post_ids_map;
	}

	/**
	 * Appends an imported post record.
	 *
	 * @param array $post_data Post data with 'post_type', 'id_old', 'id_new' keys.
	 *
	 * @return bool Success.
	 */
	public function append_imported_post( array $post_data ): bool {
		return $this->append_jsonl( self::FILE_IMPORTED_POSTS, $post_data );
	}

	/**
	 * Appends an updated parent record.
	 *
	 * @param array $parent_data Parent data with 'id_old', 'id_new', optionally 'parent_id_old', 'parent_id_new'.
	 *
	 * @return bool Success.
	 */
	public function append_updated_parent( array $parent_data ): bool {
		return $this->append_jsonl( self::FILE_UPDATED_PARENTS, $parent_data );
	}

	/**
	 * Returns a map of "old => new" post IDs which already had their post_parent updated.
	 * 
	 * The updated parent run-state data contains some more keys and values, not all are returned here:
	 * - id_old: int Old Live ID.
	 * - id_new: int New Local ID.
	 * - parent_id_old: int Old Live Parent ID.
	 * - parent_id_new: int New Local Parent ID.
	 * 
	 * @return array|null Keys are old live post IDs, values are new local post IDs, or null if file with updated parents doesn't exist.
	 */
	public function get_updated_parents_post_ids_map(): ?array {
		$updated_parents_data = $this->read_updated_parents();
		if ( null === $updated_parents_data ) {
			return null;
		}
		$updated_parents_post_ids_map = [];
		foreach ( $updated_parents_data as $entry ) {
			$updated_parents_post_ids_map[ (int) $entry['id_old'] ] = (int) $entry['id_new'];
		}
		return $updated_parents_post_ids_map;
	}

	/**
	 * Gets updated parent IDs.
	 *
	 * @return array Array of updated parent records.
	 */ 
	private function read_updated_parents(): array {
		return $this->read_jsonl( self::FILE_UPDATED_PARENTS );
	}   

	/**
	 * Appends an updated featured image post record.
	 *
	 * @param array $featured_data Featured data with 'id_old' and 'id_new' keys.
	 *
	 * @return bool Success.
	 */
	public function append_updated_featured_image_post( array $featured_data ): bool {
		return $this->append_jsonl( self::FILE_UPDATED_FEATURED, $featured_data );
	}

	/**
	 * Returns a map of "old => new" post IDs which already had their featured images updated.
	 *
	 * @return array Keys are old live post IDs, values are new local post IDs, or empty array if file doesn't exist.
	 */
	public function get_updated_featured_image_post_ids_map(): array {
		$updated_featured_data = $this->read_updated_featured();
		if ( empty( $updated_featured_data ) ) {
			return [];
		}
		$updated_featured_post_ids_map = [];
		foreach ( $updated_featured_data as $entry ) {
			if ( isset( $entry['id_old'] ) && isset( $entry['id_new'] ) ) {
				$updated_featured_post_ids_map[ (int) $entry['id_old'] ] = (int) $entry['id_new'];
			}
		}
		return $updated_featured_post_ids_map;
	}

	/**
	 * Gets updated featured image records.
	 *
	 * @return array Array of updated featured image records.
	 */
	private function read_updated_featured(): array {
		return $this->read_jsonl( self::FILE_UPDATED_FEATURED );
	}

	/**
	 * Appends a block update record to the run-state file.
	 *
	 * @param array $block_data Block data with 'id_old' and 'id_new' keys.
	 *
	 * @return bool Success.
	 */
	public function append_updated_block_post( array $block_data ): bool {
		return $this->append_jsonl( self::FILE_UPDATED_BLOCKS, $block_data );
	}

	/**
	 * Returns a map of "old => new" post IDs which already had their block attachment IDs updated.
	 *
	 * @return array Keys are old live post IDs, values are new local post IDs, or empty array if file doesn't exist.
	 */
	public function get_updated_block_post_ids_map(): array {
		$updated_blocks_data = $this->read_updated_blocks();
		if ( empty( $updated_blocks_data ) ) {
			return [];
		}
		$updated_block_post_ids_map = [];
		foreach ( $updated_blocks_data as $entry ) {
			if ( isset( $entry['id_old'] ) && isset( $entry['id_new'] ) ) {
				$updated_block_post_ids_map[ (int) $entry['id_old'] ] = (int) $entry['id_new'];
			}
		}
		return $updated_block_post_ids_map;
	}

	/**
	 * Gets updated block records.
	 *
	 * @return array Array of updated block records.
	 */
	private function read_updated_blocks(): array {
		return $this->read_jsonl( self::FILE_UPDATED_BLOCKS );
	}

	/**
	 * Gets path to a run-state file.
	 *
	 * @param string $filename Filename (e.g., 'manifest.json', 'new_ids.json').
	 *
	 * @return string Full path.
	 */
	public function get_file_path( string $filename ): string {
		return $this->run_state_dir . '/' . $filename;
	}

	/**
	 * Reads a JSON file.
	 *
	 * @param string $filename Filename.
	 *
	 * @return array|null Decoded JSON array or null if file doesn't exist.
	 */
	private function read_json( string $filename ): ?array {
		$path = $this->get_file_path( $filename );
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$content = file_get_contents( $path ); // phpcs:ignore -- WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( false === $content ) {
			return null;
		}

		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Writes a JSON file (overwrites existing).
	 *
	 * @param string $filename Filename.
	 * @param array  $data     Data to encode.
	 *
	 * @return bool Success.
	 */
	private function write_json( string $filename, array $data ): bool {
		$path = $this->get_file_path( $filename );
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return false !== file_put_contents( $path, $json ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
	}

	/**
	 * Reads a JSONL file (one JSON object per line).
	 *
	 * @param string $filename Filename.
	 *
	 * @return array Array of decoded JSON objects.
	 */
	private function read_jsonl( string $filename ): array {
		$path   = $this->get_file_path( $filename );
		$result = [];

		if ( ! file_exists( $path ) ) {
			return $result;
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fopen
		if ( ! $handle ) {
			return $result;
		}

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$result[] = $decoded;
			}
		}

		fclose( $handle );
		return $result;
	}

	/**
	 * Appends a line to a JSONL file.
	 *
	 * @param string $filename Filename.
	 * @param array  $data     Data to encode and append.
	 *
	 * @return bool Success.
	 */
	private function append_jsonl( string $filename, array $data ): bool {
		$path = $this->get_file_path( $filename );
		$json = wp_json_encode( $data );
		return false !== file_put_contents( $path, $json . "\n", FILE_APPEND ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
	}
}
