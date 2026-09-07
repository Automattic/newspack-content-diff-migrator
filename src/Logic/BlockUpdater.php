<?php
/**
 * Block Updater - handles updating attachment IDs in Gutenberg blocks.
 *
 * Extracted from ContentDiffLogic for better testability and single responsibility.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Logic;

use Newspack\MigrationTools\Logic\Shortcodes;
use NewspackContentConverter\ContentPatcher\ElementManipulators\HtmlElementManipulator;
use NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;

/**
 * Handles updating attachment IDs in various Gutenberg block types.
 */
class BlockUpdater {

	/**
	 * WpBlockManipulator instance.
	 *
	 * @var WpBlockManipulator
	 */
	private WpBlockManipulator $wp_block_manipulator;

	/**
	 * HtmlElementManipulator instance.
	 *
	 * @var HtmlElementManipulator
	 */
	private HtmlElementManipulator $html_element_manipulator;

	/**
	 * Shortcodes instance.
	 *
	 * @var Shortcodes
	 */
	private Shortcodes $shortcodes;

	/**
	 * Callback for resolving attachment URL to post ID.
	 *
	 * @var callable|null Callback that takes (url, aliases) and returns post ID.
	 */
	private $attachment_url_to_postid_resolver = null;

	/**
	 * Constructor.
	 *
	 * @param callable $attachment_url_to_postid_resolver Callback that takes ( string $attachment_url, array $local_hostname_aliases ) and
	 *                                                    returns int|null attachment post ID (or null if not found).
	 *                                                    This resolver is used to look up local attachment IDs by URL when updating block content.
	 *                                                    @see resolve_new_attachment_id().
	 */
	public function __construct( callable $attachment_url_to_postid_resolver ) {
		$this->wp_block_manipulator              = new WpBlockManipulator();
		$this->html_element_manipulator          = new HtmlElementManipulator();
		$this->shortcodes                        = new Shortcodes();
		$this->attachment_url_to_postid_resolver = $attachment_url_to_postid_resolver;
	}

	/**
	 * Sets the attachment URL resolver callback.
	 *
	 * @param callable $resolver Callback that takes (url, aliases) and returns post ID.
	 */
	public function set_attachment_url_to_postid_resolver( callable $resolver ): void {
		$this->attachment_url_to_postid_resolver = $resolver;
	}

	/**
	 * Updates attachment IDs in all supported block types.
	 *
	 * Supported block types:
	 * - wp:image (also covers native wp:gallery block)
	 * - wp:audio
	 * - wp:video
	 * - wp:file
	 * - wp:cover
	 * - wp:media-text
	 * - wp:jetpack/tiled-gallery
	 * - wp:jetpack/slideshow
	 * - wp:jetpack/image-compare
	 * - wp:block (pattern references)
	 * - classic `[gallery ids="..."]` shortcode
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference, will be updated.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_all_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$content = $this->update_image_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_audio_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_video_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_file_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_cover_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_mediatext_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_jetpacktiledgallery_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_jetpackslideshow_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_jetpackimagecompare_blocks_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_patterns_wp_block_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );
		$content = $this->update_gallery_shortcode_ids( $content, $known_attachment_ids_updates, $local_hostname_aliases );

		return $content;
	}

	/**
	 * Updates attachment IDs in wp:image blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_image_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:image', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_inner_html_updated   = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			if ( ! isset( $block_updated['attrs']['id'] ) ) {
				continue;
			}

			$att_id = $block_updated['attrs']['id'];
			if ( ! $att_id ) {
				return $content_updated;
			}

			// Get the first <img> element from innerHTML.
			$img_html = $this->get_first_img_element( $block_inner_html_updated );
			if ( ! $img_html ) {
				continue;
			}

			$src        = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );
			$new_att_id = $this->resolve_new_attachment_id( $att_id, $src, $known_attachment_ids_updates, $local_hostname_aliases );
			if ( is_null( $new_att_id ) || $att_id === $new_att_id ) {
				continue;
			}

			$new_att_id = $this->cast_to_int_if_numeric( $new_att_id );

			// Update ID in image element class attribute.
			$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html );

			// Update the img HTML in block.
			$block_inner_html_updated   = str_replace( $img_html, $img_html_updated, $block_inner_html_updated );
			$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );

			$block_updated['innerHTML']       = $block_inner_html_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;
			$block_updated['attrs']['id']     = $new_att_id;

			// Replace block in content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in wp:audio blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_audio_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:audio', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html       = $match[0];
			$block            = parse_blocks( $block_html )[0];
			$block_updated    = $block;
			$block_inner_html = $block['innerHTML'];

			$att_id = $block_updated['attrs']['id'] ?? null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			$audio_matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'audio', $block_inner_html );
			if ( is_null( $audio_matches ) || ! isset( $audio_matches[0][0][0] ) || empty( $audio_matches[0][0][0] ) ) {
				continue;
			}
			$audio_html = $audio_matches[0][0][0];

			$src = $this->html_element_manipulator->get_attribute_value( 'src', $audio_html );

			$new_att_id = $this->resolve_new_attachment_id( $att_id, $src, $known_attachment_ids_updates, $local_hostname_aliases );
			if ( is_null( $new_att_id ) || $att_id === $new_att_id ) {
				continue;
			}

			$new_att_id = $this->cast_to_int_if_numeric( $new_att_id );

			$block_updated['attrs']['id'] = $new_att_id;

			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in wp:video blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_video_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:video', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html               = $match[0];
			$block                    = parse_blocks( $block_html )[0];
			$block_updated            = $block;
			$block_inner_html_updated = $block['innerHTML'];

			$att_id = $block_updated['attrs']['id'] ?? null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			$video_matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'video', $block_inner_html_updated );
			if ( is_null( $video_matches ) || ! isset( $video_matches[0][0][0] ) || empty( $video_matches[0][0][0] ) ) {
				continue;
			}
			$video_html = $video_matches[0][0][0];

			$src = $this->html_element_manipulator->get_attribute_value( 'src', $video_html );

			$new_att_id = $this->resolve_new_attachment_id( $att_id, $src, $known_attachment_ids_updates, $local_hostname_aliases );
			if ( is_null( $new_att_id ) || $att_id === $new_att_id ) {
				continue;
			}

			$new_att_id = $this->cast_to_int_if_numeric( $new_att_id );

			$block_updated['attrs']['id'] = $new_att_id;

			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in wp:file blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_file_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:file', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html    = $match[0];
			$block         = parse_blocks( $block_html )[0];
			$block_updated = $block;

			$att_id = $block_updated['attrs']['id'] ?? null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			// Get href from file block.
			$href = $block_updated['attrs']['href'] ?? null;

			$new_att_id = $this->resolve_new_attachment_id( $att_id, $href, $known_attachment_ids_updates, $local_hostname_aliases );
			if ( is_null( $new_att_id ) || $att_id === $new_att_id ) {
				continue;
			}

			$new_att_id = $this->cast_to_int_if_numeric( $new_att_id );

			$block_updated['attrs']['id'] = $new_att_id;

			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in wp:cover blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_cover_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:cover', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_inner_html_updated   = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			$att_id = $block_updated['attrs']['id'] ?? null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			$img_html = $this->get_first_img_element( $block_inner_html_updated );
			if ( ! $img_html ) {
				continue;
			}

			$url        = $block_updated['attrs']['url'] ?? null;
			$new_att_id = $this->resolve_new_attachment_id( $att_id, $url, $known_attachment_ids_updates, $local_hostname_aliases );
			if ( is_null( $new_att_id ) || $att_id === $new_att_id ) {
				continue;
			}

			$new_att_id = $this->cast_to_int_if_numeric( $new_att_id );

			// Update ID in image element class attribute.
			$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html );

			$block_inner_html_updated   = str_replace( $img_html, $img_html_updated, $block_inner_html_updated );
			$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );

			$block_updated['innerHTML']       = $block_inner_html_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;
			$block_updated['attrs']['id']     = $new_att_id;

			// Replace block in content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in wp:media-text blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_mediatext_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:media-text', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_inner_html_updated   = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			$att_id = $block_updated['attrs']['mediaId'] ?? null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			$img_html = $this->get_first_img_element( $block_inner_html_updated );
			if ( ! $img_html ) {
				continue;
			}

			$src        = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );
			$new_att_id = $this->resolve_new_attachment_id( $att_id, $src, $known_attachment_ids_updates, $local_hostname_aliases );
			if ( is_null( $new_att_id ) || $att_id === $new_att_id ) {
				continue;
			}

			$new_att_id = $this->cast_to_int_if_numeric( $new_att_id );

			// Update ID in image element class attribute.
			$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html );

			$block_inner_html_updated   = str_replace( $img_html, $img_html_updated, $block_inner_html_updated );
			$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );

			$block_updated['innerHTML']        = $block_inner_html_updated;
			$block_updated['innerContent'][0]  = $block_innercontent_updated;
			$block_updated['attrs']['mediaId'] = $new_att_id;

			// Replace block in content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in wp:jetpack/tiled-gallery blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_jetpacktiledgallery_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:jetpack/tiled-gallery', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_inner_html_updated   = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get all images in this block.
			$img_elements = $this->get_all_img_elements( $block_inner_html_updated );
			if ( empty( $img_elements ) ) {
				continue;
			}

			foreach ( $img_elements as $img_html ) {
				$att_id = $this->html_element_manipulator->get_attribute_value( 'data-id', $img_html );
				if ( ! $att_id ) {
					continue;
				}

				$img_html_updated = $this->update_single_image_element_ids(
					$img_html,
					(int) $att_id,
					$known_attachment_ids_updates,
					$local_hostname_aliases,
					'data-id'
				);

				if ( ! $img_html_updated ) {
					continue;
				}

				$block_inner_html_updated   = str_replace( $img_html, $img_html_updated, $block_inner_html_updated );
				$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );
			}

			$block_updated['innerHTML']       = $block_inner_html_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update IDs array in block header.
			if ( isset( $block_updated['attrs']['ids'] ) && is_array( $block_updated['attrs']['ids'] ) ) {
				$block_ids_updated = $block_updated['attrs']['ids'];
				foreach ( $block_ids_updated as $key => $id ) {
					// Skip if current ID is already a valid local ID, i.e. was already updated (prevents ID collision/overlap on subsequent runs).
					if ( in_array( (int) $id, array_values( $known_attachment_ids_updates ), true ) ) {
						continue;
					}
					$block_ids_updated[ $key ] = $known_attachment_ids_updates[ $id ] ?? $id;
				}
				$block_updated['attrs']['ids'] = $block_ids_updated;
			}

			// Replace block in content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in wp:jetpack/slideshow blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_jetpackslideshow_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:jetpack/slideshow', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_inner_html_updated   = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get all images in this block.
			$img_elements = $this->get_all_img_elements( $block_inner_html_updated );
			if ( empty( $img_elements ) ) {
				continue;
			}

			foreach ( $img_elements as $img_html ) {
				$att_id = $this->html_element_manipulator->get_attribute_value( 'data-id', $img_html );
				if ( ! $att_id ) {
					continue;
				}

				$img_html_updated = $this->update_single_image_element_ids(
					$img_html,
					(int) $att_id,
					$known_attachment_ids_updates,
					$local_hostname_aliases,
					'data-id'
				);

				if ( ! $img_html_updated ) {
					continue;
				}

				$block_inner_html_updated   = str_replace( $img_html, $img_html_updated, $block_inner_html_updated );
				$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );
			}

			$block_updated['innerHTML']       = $block_inner_html_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update IDs array in block header.
			if ( isset( $block_updated['attrs']['ids'] ) && is_array( $block_updated['attrs']['ids'] ) ) {
				$block_ids_updated = $block_updated['attrs']['ids'];
				foreach ( $block_ids_updated as $key => $id ) {
					// Skip if current ID is already a valid local ID, i.e. was already updated (prevents ID collision/overlap on subsequent runs).
					if ( in_array( (int) $id, array_values( $known_attachment_ids_updates ), true ) ) {
						continue;
					}
					$block_ids_updated[ $key ] = $known_attachment_ids_updates[ $id ] ?? $id;
				}
				$block_updated['attrs']['ids'] = $block_ids_updated;
			}

			// Replace block in content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in wp:jetpack/image-compare blocks.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return string Updated content.
	 */
	public function update_jetpackimagecompare_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:jetpack/image-compare', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		$content_updated = $content;
		foreach ( $matches[0] as $match ) {
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_inner_html_updated   = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get all images in this block.
			$img_elements = $this->get_all_img_elements( $block_inner_html_updated );
			if ( empty( $img_elements ) ) {
				continue;
			}

			foreach ( $img_elements as $img_html ) {
				$att_id = $this->html_element_manipulator->get_attribute_value( 'id', $img_html );
				if ( ! $att_id ) {
					continue;
				}

				$img_html_updated = $this->update_single_image_element_ids(
					$img_html,
					(int) $att_id,
					$known_attachment_ids_updates,
					$local_hostname_aliases,
					'id'
				);

				if ( ! $img_html_updated ) {
					continue;
				}

				$block_inner_html_updated   = str_replace( $img_html, $img_html_updated, $block_inner_html_updated );
				$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );
			}

			$block_updated['innerHTML']       = $block_inner_html_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update IDs in block header for imageBefore and imageAfter.
			if ( isset( $block_updated['attrs']['imageBefore']['id'] ) ) {
				$before_id = $block_updated['attrs']['imageBefore']['id'];
				// Skip if current ID is already a valid local ID, i.e. was already updated (prevents ID collision/overlap on subsequent runs).
				if ( ! in_array( (int) $before_id, array_values( $known_attachment_ids_updates ), true ) ) {
					$block_updated['attrs']['imageBefore']['id'] = $known_attachment_ids_updates[ $before_id ] ?? $before_id;
				}
			}
			if ( isset( $block_updated['attrs']['imageAfter']['id'] ) ) {
				$after_id = $block_updated['attrs']['imageAfter']['id'];
				// Skip if current ID is already a valid local ID (prevents ID collision).
				if ( ! in_array( (int) $after_id, array_values( $known_attachment_ids_updates ), true ) ) {
					$block_updated['attrs']['imageAfter']['id'] = $known_attachment_ids_updates[ $after_id ] ?? $after_id;
				}
			}

			// Replace block in content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates pattern IDs in wp:block blocks.
	 *
	 * Pattern block format:
	 * <!-- wp:block {"ref":28} /-->
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new).
	 * @param array  $local_hostname_aliases       Hostnames to treat as local (unused for patterns).
	 *
	 * @return string Updated content.
	 */
	public function update_patterns_wp_block_ids( string $content, array $known_attachment_ids_updates, array $local_hostname_aliases = [] ): string { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		// Pattern to match complete self-closing wp:block: <!-- wp:block {"ref":123} /-->
		$pattern = '/<!--\s+wp:block\s+\{"ref":(\d+)\}\s+\/-->/';

		$content_updated = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $known_attachment_ids_updates ) {
				$old_id = (int) $matches[1];

				// Skip if current ID is already a valid local ID (prevents ID collision on subsequent runs).
				if ( in_array( $old_id, array_values( $known_attachment_ids_updates ), true ) ) {
					// Return unchanged.
					return $matches[0];
				}

				// Check if we have a mapping for this pattern ID.
				if ( ! isset( $known_attachment_ids_updates[ $old_id ] ) ) {
					return $matches[0]; // Return unchanged.
				}

				$new_id = $known_attachment_ids_updates[ $old_id ];
				if ( $old_id === $new_id ) {
					return $matches[0]; // Return unchanged.
				}

				$new_id = $this->cast_to_int_if_numeric( $new_id );

				// Return the updated block.
				return sprintf( '<!-- wp:block {"ref":%d} /-->', $new_id );
			},
			$content
		);

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in classic `[gallery ids="..."]` shortcodes.
	 *
	 * Only the `ids` attribute of the core `[gallery]` shortcode is handled. The CSV value is
	 * remapped through the known ID map; the surrounding shortcode string (quote style, spacing,
	 * other attributes) is preserved by replacing only the CSV substring.
	 *
	 * @param string $content                      Post content.
	 * @param array  $known_attachment_ids_updates Known ID mappings (old => new). Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local. Unused here; kept for signature parity.
	 *
	 * @return string Updated content.
	 */
	public function update_gallery_shortcode_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed.
		if ( ! $this->shortcodes->has_shortcode( 'gallery', $content ) ) {
			return $content;
		}

		$content_updated = $content;
		$shortcodes      = $this->shortcodes->get_all_shortcodes_from_content( 'gallery', $content );
		foreach ( $shortcodes as $shortcode ) {
			$ids_csv = $this->shortcodes->get_shortcode_attribute( 'ids', $shortcode );

			// Skip `[gallery]` with no `ids` (pulls all post children -- must be a no-op) or an empty `ids`.
			if ( ! is_string( $ids_csv ) || '' === $ids_csv ) {
				continue;
			}

			$old_ids = array_map( 'trim', explode( ',', $ids_csv ) );
			$new_ids = [];
			foreach ( $old_ids as $old_id ) {
				$old_id_int = (int) $old_id;

				// Skip if current ID is already a valid local ID, i.e. was already updated (prevents ID collision/overlap on subsequent runs).
				if ( in_array( $old_id_int, array_values( $known_attachment_ids_updates ), true ) ) {
					$new_ids[] = $old_id;
					continue;
				}

				$new_ids[] = (string) ( $known_attachment_ids_updates[ $old_id_int ] ?? $old_id_int );
			}

			if ( $new_ids === $old_ids ) {
				continue;
			}

			$new_ids_csv       = implode( ',', $new_ids );
			$shortcode_updated = str_replace( $ids_csv, $new_ids_csv, $shortcode );
			$content_updated   = str_replace( $shortcode, $shortcode_updated, $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates <img> element's attribute value.
	 *
	 * @param string $attribute_name Name of attribute to update.
	 * @param array  $value_update   Key is old value, value is new value.
	 * @param string $content        HTML content.
	 *
	 * @return string Updated content.
	 */
	public function update_image_element_attribute( string $attribute_name, array $value_update, string $content ): string {
		$content_updated = $content;

		$pattern = '|
			(
				\<img
				[^\>]*        # zero or more characters except closing angle bracket
				' . $attribute_name . '="
			)
			(
				\d+           # attribute value
			)
			(
				"             # value closing double quote
				[^\>]*        # zero or more characters except closing angle bracket
				\>            # closing angle bracket
			)
		|xims';

		$matches = [];
		preg_match_all( $pattern, $content, $matches );
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {
			foreach ( $matches[2] as $key_match => $id ) {
				$id_new = $value_update[ $id ] ?? null;

				if ( ! is_null( $id_new ) ) {
					$matched_block_header         = $matches[0][ $key_match ];
					$matched_block_header_updated = str_replace(
						sprintf( '%s="%d"', $attribute_name, $id ),
						sprintf( '%s="%d"', $attribute_name, $id_new ),
						$matched_block_header
					);

					$content_updated = str_replace(
						$matched_block_header,
						$matched_block_header_updated,
						$content_updated
					);
				}
			}
		}

		return $content_updated;
	}

	/**
	 * Updates the ID in <img> element's class attribute (wp-image-123).
	 *
	 * @param array  $ids_updates Keys are old IDs, values are new IDs.
	 * @param string $content     HTML content.
	 *
	 * @return string Updated content.
	 */
	public function update_image_element_class_attribute( array $ids_updates, string $content ): string {
		$content_updated = $content;

		$pattern_img_class_id = '|
			(
				\<img
				[^\>]*       # zero or more characters except closing angle bracket
				class="
				[^"]*        # zero or more characters except class closing double quote
				wp-image-
			)
			(
				\b(\d+)(?!\d).*?\b   # ID not followed by any other digits
			)
			(
				[^\>]*       # zero or more characters except closing angle bracket
				/\>          # closing angle bracket
			)
		|xims';

		$matches = [];
		preg_match_all( $pattern_img_class_id, $content, $matches );
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {
			foreach ( $matches[2] as $key_match => $id ) {
				$id_new = $ids_updates[ $id ] ?? null;

				if ( ! is_null( $id_new ) ) {
					$matched_block_header         = $matches[0][ $key_match ];
					$matched_block_header_updated = str_replace(
						sprintf( 'wp-image-%d', $id ),
						sprintf( 'wp-image-%d', $id_new ),
						$matched_block_header
					);

					$content_updated = str_replace(
						$matched_block_header,
						$matched_block_header_updated,
						$content_updated
					);
				}
			}
		}

		return $content_updated;
	}

	/**
	 * Updates attachment ID in Gutenberg block headers with single ID.
	 *
	 * @param string $block_designation Block name (e.g., 'wp:image').
	 * @param array  $id_update         Key is old ID, value is new ID.
	 * @param string $content           HTML content.
	 *
	 * @return string Updated content.
	 */
	public function update_gutenberg_blocks_headers_single_id( string $block_designation, array $id_update, string $content ): string {
		$content_updated = $content;

		$block_designation_escaped = $this->escape_regex_pattern_string( $block_designation );
		$pattern_block_id_sprintf  = '|
			(
				\<\!--       # beginning of the block element
				\s           # followed by a space
				%s           # element name/designation
				\s           # followed by a space
				{            # opening brace
				[^}]*        # zero or more characters except closing brace
				"id"\:       # id attribute
			)
			(
				\d+          # id value
			)
			(
				[^\d\>]+     # any following char except numeric and comment closing angle bracket
			)
		|xims';

		$matches = [];
		preg_match_all( sprintf( $pattern_block_id_sprintf, $block_designation_escaped ), $content, $matches );
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {
			foreach ( $matches[2] as $key_match => $id ) {
				$id_new = $id_update[ $id ] ?? null;

				if ( ! is_null( $id_new ) ) {
					$matched_block_header         = $matches[0][ $key_match ];
					$matched_block_header_updated = str_replace(
						sprintf( '"id":%d', $id ),
						sprintf( '"id":%d', $id_new ),
						$matched_block_header
					);

					$content_updated = str_replace(
						$matched_block_header,
						$matched_block_header_updated,
						$content_updated
					);
				}
			}
		}

		return $content_updated;
	}

	/**
	 * Updates attachment IDs in Gutenberg block headers with multiple CSV IDs.
	 *
	 * @param array  $imported_attachment_ids Keys are old IDs, values are new IDs.
	 * @param string $content                 HTML content.
	 *
	 * @return string Updated content.
	 */
	public function update_gutenberg_blocks_headers_multiple_ids( array $imported_attachment_ids, string $content ): string {
		$pattern_csv_ids = '|
			(
				\<\!--       # beginning of the block element
				\s           # followed by a space
				wp\:[^\s]+   # element name/designation
				\s           # followed by a space
				{            # opening brace
				[^}]*        # zero or more characters except closing brace
				"ids"\:      # ids attribute
				\[           # opening square bracket containing CSV IDs
			)
			(
				 [\d,]+      # comma separated IDs
			)
			(
				\]           # closing square bracket containing CSV IDs
				[^\d\>]+     # any following char except numeric and comment closing angle bracket
			)
		|xims';

		preg_match_all( $pattern_csv_ids, $content, $matches );
		$ids_csv_replacements = [];
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {
			foreach ( $matches[2] as $key_match => $ids_csv ) {
				$ids         = explode( ',', $ids_csv );
				$ids_updated = [];
				foreach ( $ids as $key_id => $id ) {
					$ids_updated[ $key_id ] = $imported_attachment_ids[ $id ] ?? $id;
				}

				if ( $ids_updated != $ids ) {
					$ids_csv_replacements[ $key_match ] = [
						'before_csv_ids' => implode( ',', $ids ),
						'after_csv_ids'  => implode( ',', $ids_updated ),
					];
				}
			}
		}

		$content_updated = $content;
		foreach ( $ids_csv_replacements as $key_match => $changes ) {
			$ids_csv_before = $changes['before_csv_ids'];
			$ids_csv_after  = $changes['after_csv_ids'];

			$matched_block_header         = $matches[0][ $key_match ];
			$matched_block_header_updated = str_replace(
				sprintf( '"ids":[%s]', $ids_csv_before ),
				sprintf( '"ids":[%s]', $ids_csv_after ),
				$matched_block_header
			);

			$content_updated = str_replace(
				$matched_block_header,
				$matched_block_header_updated,
				$content_updated
			);
		}

		return $content_updated;
	}

	/**
	 * Resolves the new attachment ID for a given old ID.
	 *
	 * @param int    $att_id                       Original attachment ID.
	 * @param string $src                          Source URL.
	 * @param array  $known_attachment_ids_updates Known ID mappings. Passed by reference.
	 * @param array  $local_hostname_aliases       Hostnames to treat as local.
	 *
	 * @return int|null New attachment ID or null if not found.
	 */
	private function resolve_new_attachment_id( int $att_id, ?string $src, array &$known_attachment_ids_updates, array $local_hostname_aliases ): ?int {
		// Skip if current ID is already a valid local ID, i.e. was already updated (prevents ID collision/overlap on subsequent runs).
		if ( in_array( $att_id, array_values( $known_attachment_ids_updates ), true ) ) {
			return null;
		}

		// Check if we already know the mapping.
		if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
			return $known_attachment_ids_updates[ $att_id ];
		}

		// Try to resolve via URL lookup.
		if ( $src && $this->attachment_url_to_postid_resolver ) {
			if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
				$src_cleaned = $this->clean_attachment_url_for_query( $src );
				$new_att_id  = call_user_func( $this->attachment_url_to_postid_resolver, $src_cleaned, $local_hostname_aliases );

				if ( $new_att_id && $new_att_id > 0 && $new_att_id != $att_id ) {
					$known_attachment_ids_updates[ $att_id ] = $new_att_id;
					return $new_att_id;
				}
			}
		}

		return null;
	}

	/**
	 * Checks if URL should be queried as local attachment.
	 *
	 * @param string $url                    Attachment URL.
	 * @param array  $local_hostname_aliases Hostnames to treat as local.
	 *
	 * @return bool
	 */
	private function should_url_be_queried_as_local_attachment( string $url, array $local_hostname_aliases ): bool {
		$url_parsed = wp_parse_url( $url );
		$url_host   = $url_parsed['host'] ?? '';

		$siteurl        = get_option( 'siteurl' );
		$siteurl_parsed = wp_parse_url( $siteurl );
		$siteurl_host   = $siteurl_parsed['host'] ?? '';

		return $siteurl_host === $url_host || in_array( $url_host, $local_hostname_aliases, true );
	}

	/**
	 * Cleans attachment URL for database query.
	 *
	 * @param string $url Attachment URL.
	 *
	 * @return string Cleaned URL.
	 */
	private function clean_attachment_url_for_query( string $url ): string {
		$parsed_url = wp_parse_url( $url );

		$cleaned_url = sprintf(
			'%s://%s%s',
			$parsed_url['scheme'] ?? 'https',
			$parsed_url['host'] ?? '',
			$parsed_url['path'] ?? ''
		);

		return $cleaned_url;
	}

	/**
	 * Casts value to int if numeric.
	 *
	 * @param mixed $value Value to cast.
	 *
	 * @return mixed
	 */
	private function cast_to_int_if_numeric( mixed $value ): mixed {
		return ( is_numeric( $value ) && (int) $value == $value ) ? (int) $value : $value;
	}

	/**
	 * Escapes special regex characters in a string.
	 *
	 * @param string $subject String to escape.
	 *
	 * @return string Escaped string.
	 */
	private function escape_regex_pattern_string( string $subject ): string {
		$special_chars   = [ '.', '\\', '+', '*', '?', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':' ];
		$subject_escaped = $subject;
		foreach ( $special_chars as $special_char ) {
			$subject_escaped = str_replace( $special_char, '\\' . $special_char, $subject_escaped );
		}

		$subject_escaped = str_replace( ' ', '\s', $subject_escaped );

		return $subject_escaped;
	}

	/**
	 * Gets the first img element from block innerHTML.
	 *
	 * @param string $inner_html Block innerHTML.
	 *
	 * @return string|null First img HTML or null if not found.
	 */
	private function get_first_img_element( string $inner_html ): ?string {
		$img_matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $inner_html );
		if ( is_null( $img_matches ) || ! isset( $img_matches[0][0][0] ) || empty( $img_matches[0][0][0] ) ) {
			return null;
		}
		return $img_matches[0][0][0];
	}

	/**
	 * Gets all img elements from block innerHTML.
	 *
	 * @param string $inner_html Block innerHTML.
	 *
	 * @return array Array of img HTML strings, or empty array if none found.
	 */
	private function get_all_img_elements( string $inner_html ): array {
		$img_matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $inner_html );
		if ( is_null( $img_matches ) || ! isset( $img_matches[0] ) || empty( $img_matches[0] ) ) {
			return [];
		}
		return array_column( $img_matches[0], 0 );
	}

	/**
	 * Updates a single image element's ID attributes.
	 *
	 * Resolves the new attachment ID, then updates class and optionally data-id/id attributes.
	 *
	 * @param string      $img_html                      Original img HTML.
	 * @param int         $att_id                        Current attachment ID.
	 * @param array       $known_attachment_ids_updates  Known ID mappings (passed by reference).
	 * @param array       $local_hostname_aliases        Hostnames to treat as local.
	 * @param string|null $id_attribute                  Optional: attribute name to update ('data-id', 'id', etc).
	 *
	 * @return string|null Updated img HTML, or null if no update needed.
	 */
	private function update_single_image_element_ids(
		string $img_html,
		int $att_id,
		array &$known_attachment_ids_updates,
		array $local_hostname_aliases,
		?string $id_attribute = null
	): ?string {
		$src = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );

		$new_att_id = $this->resolve_new_attachment_id( $att_id, $src, $known_attachment_ids_updates, $local_hostname_aliases );
		if ( is_null( $new_att_id ) || $att_id === $new_att_id ) {
			return null;
		}

		$new_att_id       = $this->cast_to_int_if_numeric( $new_att_id );
		$img_html_updated = $img_html;

		// Update the specified ID attribute if provided.
		if ( $id_attribute ) {
			$img_html_updated = $this->update_image_element_attribute( $id_attribute, [ $att_id => $new_att_id ], $img_html_updated );
		}

		// Always update the wp-image- class.
		$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html_updated );

		return $img_html_updated;
	}
}
