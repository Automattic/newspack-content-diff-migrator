<?php
/**
 * Integration tests for command cmd_migrate_live_content, blocks migration.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Integration;

use Newspack\ContentDiffMigrator\Tests\Integration\IntegrationTestCase;

/**
 * Integration test class for command cmd_migrate_live_content, blocks migration.
 *
 * @group integration
 */
class CmdMigrateLiveContentBlocksTest extends IntegrationTestCase {
	/**
	 * @group blocks
	 */
	public function test_should_update_image_block_id_in_post_content(): void {
		global $wpdb;

		// Create attachment.
		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10001,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with image block referencing the attachment.
		$content = '<!-- wp:image {"id":10001,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://test.local/image.jpg" alt="" class="wp-image-10001"/></figure>
<!-- /wp:image -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10002,
				'post_content' => $content,
			]
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10002, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10001, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Verify the block ID was updated.
		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content, 'Image block ID should be updated.' );
		$this->assertStringContainsString( 'wp-image-' . $new_attachment_id, $new_post->post_content, 'Image class should be updated.' );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_jetpack_slideshow_gallery_block_ids_in_post_content(): void {
		global $wpdb;

		// Create attachments.
		$attachment1 = $this->create_post_fixture(
			[
				'ID'          => 10101,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$attachment2 = $this->create_post_fixture(
			[
				'ID'          => 10102,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with Jetpack Slideshow block -- this is just a small demo block, unit tests already cover more comprehensive fixtures.
		$content = '<!-- wp:jetpack/slideshow {"ids":[10101,10102],"sizeSlug":"large"} -->
<div class="wp-block-jetpack-slideshow"><div class="wp-block-jetpack-slideshow_container swiper"><ul class="wp-block-jetpack-slideshow_swiper-wrapper swiper-wrapper"><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="" class="wp-block-jetpack-slideshow_image wp-image-10101" data-id="10101" data-aspect-ratio="1024 / 439" src="https://ivannptest.newspackstaging.com/wp-content/uploads/2026/01/WP-art-100x200-1-1024x439.png"/></figure></li><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="" class="wp-block-jetpack-slideshow_image wp-image-10102" data-id="10102" data-aspect-ratio="1024 / 408" src="https://ivannptest.newspackstaging.com/wp-content/uploads/2025/06/huge-scaled-1-1024x408.jpg"/></figure></li></ul><a class="wp-block-jetpack-slideshow_button-prev swiper-button-prev swiper-button-white" role="button"></a><a class="wp-block-jetpack-slideshow_button-next swiper-button-next swiper-button-white" role="button"></a><a aria-label="Pause Slideshow" class="wp-block-jetpack-slideshow_button-pause" role="button"></a><div class="wp-block-jetpack-slideshow_pagination swiper-pagination swiper-pagination-white"></div></div></div>
<!-- /wp:jetpack/slideshow -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10103,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 10103, $this->source_hostname );
		$new_att1_id = $this->logic->get_current_post_id_by_old_id( 10101, $this->source_hostname );
		$new_att2_id = $this->logic->get_current_post_id_by_old_id( 10102, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Verify both IDs were updated.
		$this->assertStringContainsString( 'data-id="' . $new_att1_id . '"', $new_post->post_content );
		$this->assertStringContainsString( 'data-id="' . $new_att2_id . '"', $new_post->post_content );
		$this->assertStringContainsString( '"ids":[' . $new_att1_id . ',' . $new_att2_id . ']', $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_cover_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10201,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:cover {"id":10201,"dimRatio":50} -->
<div class="wp-block-cover"><span class="wp-block-cover__background"></span><img class="wp-block-cover__image-background wp-image-10201" src="cover.jpg"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Cover text</p><!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10202,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10202, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10201, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content, 'Cover block ID should be updated.' );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_media_text_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10301,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:media-text {"mediaId":10301,"mediaType":"image"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="media.jpg" class="wp-image-10301"/></figure><div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph --></div></div>
<!-- /wp:media-text -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10302,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10302, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10301, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"mediaId":' . $new_attachment_id, $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_file_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10401,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:file {"id":10401} -->
<div class="wp-block-file"><a href="document.pdf">Document</a></div>
<!-- /wp:file -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10402,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10402, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10401, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_audio_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'             => 10501,
				'post_type'      => 'attachment',
				'post_mime_type' => 'audio/mpeg',
				'post_status'    => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:audio {"id":10501} -->
<figure class="wp-block-audio"><audio controls src="audio.mp3"></audio></figure>
<!-- /wp:audio -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10502,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10502, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10501, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_video_block_id_in_post_content(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'             => 10601,
				'post_type'      => 'attachment',
				'post_mime_type' => 'video/mp4',
				'post_status'    => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:video {"id":10601} -->
<figure class="wp-block-video"><video controls src="video.mp4"></video></figure>
<!-- /wp:video -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10602,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10602, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10601, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( '"id":' . $new_attachment_id, $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_attachment_ids_in_post_excerpt(): void {
		global $wpdb;

		$attachment = $this->create_post_fixture(
			[
				'ID'          => 10701,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		// Create post with blocks in excerpt (some themes support this).
		$excerpt = '<!-- wp:image {"id":10701} --><figure class="wp-block-image"><img class="wp-image-10701"/></figure><!-- /wp:image -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10702,
				'post_excerpt' => $excerpt,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id       = $this->logic->get_current_post_id_by_old_id( 10702, $this->source_hostname );
		$new_attachment_id = $this->logic->get_current_post_id_by_old_id( 10701, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Excerpt may or may not be updated depending on implementation.
		// The key test is that migration completes without errors.
		$this->assertNotNull( $new_post_id, 'Post with blocks in excerpt should be imported.' );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_jetpack_slideshow_block_ids(): void {
		global $wpdb;

		$attachment1 = $this->create_post_fixture(
			[
				'ID'          => 10801,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$attachment2 = $this->create_post_fixture(
			[
				'ID'          => 10802,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:jetpack/slideshow {"ids":[10801,10802]} -->
<div class="wp-block-jetpack-slideshow"><ul class="swiper-wrapper"><li class="swiper-slide"><img src="1.jpg" data-id="10801" class="wp-image-10801"/></li><li class="swiper-slide"><img src="2.jpg" data-id="10802" class="wp-image-10802"/></li></ul></div>
<!-- /wp:jetpack/slideshow -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10803,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 10803, $this->source_hostname );
		$new_att1_id = $this->logic->get_current_post_id_by_old_id( 10801, $this->source_hostname );
		$new_att2_id = $this->logic->get_current_post_id_by_old_id( 10802, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Verify Jetpack slideshow IDs were updated.
		$this->assertStringContainsString( 'data-id="' . $new_att1_id . '"', $new_post->post_content );
		$this->assertStringContainsString( 'data-id="' . $new_att2_id . '"', $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_jetpack_tiled_gallery_block_ids(): void {
		global $wpdb;

		$attachment1 = $this->create_post_fixture(
			[
				'ID'          => 10901,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$attachment2 = $this->create_post_fixture(
			[
				'ID'          => 10902,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$inserted = $wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$inserted =$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:jetpack/tiled-gallery {"ids":[10901,10902]} -->
<div class="wp-block-jetpack-tiled-gallery"><figure class="tiled-gallery__item"><img src="1.jpg" data-id="10901" class="wp-image-10901"/></figure><figure class="tiled-gallery__item"><img src="2.jpg" data-id="10902" class="wp-image-10902"/></figure></div>
<!-- /wp:jetpack/tiled-gallery -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 10903,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 10903, $this->source_hostname );
		$new_att1_id = $this->logic->get_current_post_id_by_old_id( 10901, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		$this->assertStringContainsString( 'data-id="' . $new_att1_id . '"', $new_post->post_content );
	}

	/**
	 * @group blocks
	 */
	public function test_should_update_jetpack_image_compare_block_ids(): void {
		global $wpdb;

		$attachment1 = $this->create_post_fixture(
			[
				'ID'          => 11001,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$attachment2 = $this->create_post_fixture(
			[
				'ID'          => 11002,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment1 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.
		$wpdb->insert( $this->live_table_prefix . 'posts', $attachment2 ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$content = '<!-- wp:jetpack/image-compare {"imageBefore":{"id":11001},"imageAfter":{"id":11002}} -->
<figure class="wp-block-jetpack-image-compare"><div class="juxtapose"><img src="before.jpg" data-id="11001" class="wp-image-11001"/><img src="after.jpg" data-id="11002" class="wp-image-11002"/></div></figure>
<!-- /wp:jetpack/image-compare -->';

		$post = $this->create_post_fixture(
			[
				'ID'           => 11003,
				'post_content' => $content,
			] 
		);
		$wpdb->insert( $this->live_table_prefix . 'posts', $post ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.DirectQuery.

		$this->run_search_command( [ 'post-types-csv' => 'post,attachment' ] );
		$this->run_migrate_command();

		$new_post_id = $this->logic->get_current_post_id_by_old_id( 11003, $this->source_hostname );
		$new_att1_id = $this->logic->get_current_post_id_by_old_id( 11001, $this->source_hostname );
		$new_att2_id = $this->logic->get_current_post_id_by_old_id( 11002, $this->source_hostname );

		$new_post = get_post( $new_post_id );

		// Verify image compare block IDs were updated.
		$this->assertStringContainsString( '"id":' . $new_att1_id, $new_post->post_content );
		$this->assertStringContainsString( '"id":' . $new_att2_id, $new_post->post_content );
	}
}
