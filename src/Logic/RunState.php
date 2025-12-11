<?php
/**
 * RunState utility for managing execution state in formatted files in the "run-state" directory.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Logic;

/**
 * Manages migration run-state data. This data is kept in formatted files in $run_state_dir path.
 * Run-state data is information about all the IDs/objects which will be migrated, have already been migrated,
 * so in case the migration is interrupted, it will be resumed based on this info.
 */
class RunState {

	// Run-state filenames.
	private const FILE_NEW_IDS              = 'new_ids.json';
	private const FILE_MODIFIED_IDS         = 'modified_ids.json';
	private const FILE_MANIFEST             = 'manifest.json';
	private const FILE_IMPORTED_POSTS       = 'imported_posts.jsonl';
	private const FILE_UPDATED_PARENTS      = 'updated_parents.jsonl';
	private const FILE_UPDATED_FEATURED     = 'updated_featured.jsonl';
	private const FILE_UPDATED_BLOCKS       = 'updated_blocks.jsonl';
	private const FILE_DELETED_MODIFIED_IDS = 'deleted_modified_ids.jsonl';

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
	 * Gets new IDs to be migrated.
	 *
	 * @return array|null Array of IDs or null if file doesn't exist.
	 */
	public function read_new_ids(): ?array {
		return $this->read_json( self::FILE_NEW_IDS );
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
	 * Gets modified IDs ('live_id', 'local_id' pairs).
	 *
	 * @return array Array of modified ID pairs.
	 */
	public function read_modified_ids(): array {
		return $this->read_json( self::FILE_MODIFIED_IDS ) ?? [];
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
	 *
	 * @param array $manifest Manifest data.
	 *
	 * @return bool Success.
	 */
	public function write_manifest( array $manifest ): bool {
		return $this->write_json( self::FILE_MANIFEST, $manifest );
	}

	/**
	 * Gets imported posts.
	 *
	 * @return array Array of imported post records.
	 */
	public function read_imported_posts(): array {
		return $this->read_jsonl( self::FILE_IMPORTED_POSTS );
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
	 * Gets updated parent IDs.
	 *
	 * @return array Array of updated parent records.
	 */
	public function read_updated_parents(): array {
		return $this->read_jsonl( self::FILE_UPDATED_PARENTS );
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
	 * Gets updated featured image IDs.
	 *
	 * @return array Array of updated featured image records.
	 */
	public function read_updated_featured(): array {
		return $this->read_jsonl( self::FILE_UPDATED_FEATURED );
	}

	/**
	 * Gets updated block IDs.
	 *
	 * @return array Array of updated block records.
	 */
	public function read_updated_blocks(): array {
		return $this->read_jsonl( self::FILE_UPDATED_BLOCKS );
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
