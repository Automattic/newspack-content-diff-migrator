<?php
/**
 * Unit tests for BlockUpdater.
 *
 * Pure unit tests - no database required.
 * Tests string manipulation and ID replacement logic for Gutenberg blocks.
 *
 * @package Newspack_Content_Diff_Migrator
 */

namespace Newspack\ContentDiffMigrator\Tests\Unit\Logic;

use Newspack\ContentDiffMigrator\Logic\BlockUpdater;
use PHPUnit\Framework\TestCase;

/**
 * Unit test class for BlockUpdater.
 */
class BlockUpdaterTest extends TestCase {

	/**
	 * BlockUpdater instance.
	 *
	 * @var BlockUpdater
	 */
	private BlockUpdater $updater;

	/**
	 * Fixtures directory path.
	 *
	 * @var string
	 */
	private string $fixtures_dir;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->updater      = new BlockUpdater(
			function ( string $url ) { // phpcs:ignore -- leave unused $url parameter for readability Generic.CodeAnalysis.UnusedFunctionParameter.Found.
				return 999;
			} 
		);
		$this->fixtures_dir = dirname( __DIR__, 2 ) . '/fixtures/blocks';
	}

	// =========================================================================
	// HELPER METHODS
	// =========================================================================

	/**
	 * Load an HTML fixture file.
	 *
	 * @param string $name Fixture name without extension.
	 * @return string File contents.
	 */
	private function load_fixture( string $name ): string {
		return file_get_contents( $this->fixtures_dir . '/' . $name . '.html' );
	}

	// =========================================================================
	// ATTACHMENT URL RESOLVER TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::set_attachment_url_resolver
	 */
	public function attachment_url_resolver_can_be_injected(): void {
		// Create a resolver that always returns a specific ID.
		$resolver = function ( string $url ) { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.Found.
			return 999;
		};

		$updater = new BlockUpdater( $resolver );

		$content = <<<'HTML'
<!-- wp:image {"id":111} -->
<figure class="wp-block-image"><img src="https://example.com/test.jpg" class="wp-image-111"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [];
		$result    = $updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertIsString( $result );
	}

	// =========================================================================
	// IMAGE BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_updates_id_in_header_and_class(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":111111,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://example.com/image.jpg" alt="" class="wp-image-111111"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [ 111111 => 999999 ];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":999999', $result );
		$this->assertStringContainsString( 'wp-image-999999', $result );
		$this->assertStringNotContainsString( '"id":111111', $result );
		$this->assertStringNotContainsString( 'wp-image-111111', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_returns_unchanged_when_id_not_in_mapping(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":111111} -->
<figure class="wp-block-image"><img src="test.jpg" class="wp-image-111111"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [ 999999 => 888888 ]; // Different ID.
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":111111', $result );
		$this->assertStringContainsString( 'wp-image-111111', $result );
		$this->assertStringNotContainsString( '"id":999999', $result );
		$this->assertStringNotContainsString( 'wp-image-999999', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_blocks_nested_in_gallery_get_updated(): void {
		$content = $this->load_fixture( 'image-blocks' );

		$known_ids = [
			111111 => 999111,
			222222 => 999222,
			333333 => 999333,
		];

		$result = $this->updater->update_image_blocks_ids( $content, $known_ids );

		// All IDs should be updated.
		$this->assertStringContainsString( '"id":999111', $result );
		$this->assertStringContainsString( '"id":999222', $result );
		$this->assertStringContainsString( '"id":999333', $result );

		// Old IDs should be gone.
		$this->assertStringNotContainsString( '"id":111111', $result );
		$this->assertStringNotContainsString( '"id":222222', $result );
		$this->assertStringNotContainsString( '"id":333333', $result );

		// All classes 
		$this->assertStringContainsString( 'wp-image-999111', $result );
		$this->assertStringContainsString( 'wp-image-999222', $result );
		$this->assertStringContainsString( 'wp-image-999333', $result );

		// Old classes should be gone.
		$this->assertStringNotContainsString( 'wp-image-111111', $result );
		$this->assertStringNotContainsString( 'wp-image-222222', $result );
		$this->assertStringNotContainsString( 'wp-image-333333', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_wrapped_in_group_gets_updated(): void {
		$content = <<<'HTML'
<!-- wp:group -->
<!-- wp:image {"id":12345} -->
<figure class="wp-block-image"><img src="test.jpg" class="wp-image-12345"/></figure>
<!-- /wp:image -->
<!-- /wp:group -->
HTML;

		$known_ids = [ 12345 => 67890 ];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":67890', $result );
		$this->assertStringContainsString( 'wp-image-67890', $result );
		$this->assertStringNotContainsString( '"id":12345', $result );
		$this->assertStringNotContainsString( 'wp-image-12345', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_element_attribute
	 */
	public function update_image_element_attribute_updates_data_id(): void {
		$content = '<img src="test.jpg" data-id="11111" class="wp-image-11111"/>';
		$id_map  = [ 11111 => 99999 ];
		$result  = $this->updater->update_image_element_attribute( 'data-id', $id_map, $content );

		$this->assertStringContainsString( 'data-id="99999"', $result );
		$this->assertStringNotContainsString( 'data-id="11111"', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_element_class_attribute
	 */
	public function update_image_element_class_updates_wp_image_class(): void {
		$content = '<img src="test.jpg" class="wp-image-11111"/>';
		$id_map  = [ 11111 => 99999 ];
		$result  = $this->updater->update_image_element_class_attribute( $id_map, $content );

		$this->assertStringContainsString( 'wp-image-99999', $result );
		$this->assertStringNotContainsString( 'wp-image-11111', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_element_class_attribute
	 */
	public function update_image_element_class_preserves_other_classes(): void {
		$content = '<img src="test.jpg" class="aligncenter wp-image-11111 size-large"/>';
		$id_map  = [ 11111 => 99999 ];
		$result  = $this->updater->update_image_element_class_attribute( $id_map, $content );

		$this->assertStringContainsString( 'aligncenter', $result );
		$this->assertStringContainsString( 'size-large', $result );
		$this->assertStringContainsString( 'wp-image-99999', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_element_class_attribute
	 */
	public function update_image_element_class_handles_class_at_start(): void {
		$content = '<img src="test.jpg" class="wp-image-11111 otherclass"/>';
		$id_map  = [ 11111 => 99999 ];
		$result  = $this->updater->update_image_element_class_attribute( $id_map, $content );

		$this->assertStringContainsString( 'wp-image-99999', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_element_class_attribute
	 */
	public function update_image_element_class_handles_class_at_end(): void {
		$content = '<img src="test.jpg" class="otherclass wp-image-11111"/>';
		$id_map  = [ 11111 => 99999 ];
		$result  = $this->updater->update_image_element_class_attribute( $id_map, $content );

		$this->assertStringContainsString( 'wp-image-99999', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_with_mapping_to_different_value_updates(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":1000,"sizeSlug":"large"} -->
<figure class="wp-block-image"><img src="https://example.com/image.jpg" class="wp-image-1000"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringContainsString( 'wp-image-2000', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
		$this->assertStringNotContainsString( 'wp-image-1000', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_with_mapping_to_same_value_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":1000,"sizeSlug":"large"} -->
<figure class="wp-block-image"><img src="https://example.com/image.jpg" class="wp-image-1000"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [ 1000 => 1000 ]; // Maps to itself.
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		// Should remain unchanged.
		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":1000} -->
<figure class="wp-block-image"><img src="https://example.com/image.jpg" class="wp-image-1000"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = []; // No mapping available.
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_without_id_attribute_is_skipped(): void {
		$content = <<<'HTML'
<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image"><img src="https://example.com/image.jpg"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_resolver_gets_used_when_no_known_id_is_found_and_known_ids_gets_updated(): void {

		// Create a resolver that returns a specific ID for the first image and a different ID for the second image.
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) {
				if ( 'https://example.com/image1.jpg' === $url ) {
						return 99999;
				} elseif ( 'https://example.com/image2.jpg' === $url ) {
					return 88888;
				}
			
				// This should never be called, because image3.jpg is not a local hostname alias, but still leaving it as bait.
				if ( 'https://not-local-hostname-alias.com/image3.jpg' === $url ) {
					return 77777;
				}

				return null;
			} 
		);

		$content = <<<'HTML'
<!-- wp:image {"id":1000} -->
<figure class="wp-block-image"><img src="https://example.com/image1.jpg" class="wp-image-1000"/></figure>
<!-- /wp:image -->

<!-- wp:image {"id":2000} -->
<figure class="wp-block-image"><img src="https://not-local-hostname-alias.com/image3.jpg" class="wp-image-2000"/></figure>
<!-- /wp:image -->

<!-- wp:image {"id":1001} -->
<figure class="wp-block-image"><img src="https://example.com/image2.jpg" class="wp-image-1001"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_image_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Images 1 and 3 should be updated.
		$this->assertStringContainsString( '"id":99999', $result );
		$this->assertStringContainsString( '"id":88888', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
		$this->assertStringNotContainsString( '"id":1001', $result );
		$this->assertStringContainsString( 'wp-image-99999', $result );
		$this->assertStringContainsString( 'wp-image-88888', $result );
		$this->assertStringNotContainsString( 'wp-image-1000', $result );
		$this->assertStringNotContainsString( 'wp-image-1001', $result );

		// Image 2 should not be updated because it is not a local hostname alias.
		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringContainsString( 'wp-image-2000', $result );
		$this->assertStringNotContainsString( '"id":77777', $result );
		$this->assertStringNotContainsString( 'wp-image-77777', $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	// =========================================================================
	// AUDIO BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_updates_id(): void {
		$content = $this->load_fixture( 'audio-block' );

		$known_ids = [ 1111 => 9999 ];
		$result    = $this->updater->update_audio_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":9999', $result );
		$this->assertStringNotContainsString( '"id":1111', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_multiple_blocks_updated(): void {
		$content = <<<'HTML'
<!-- wp:audio {"id":1111} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio1.mp3"></audio></figure>
<!-- /wp:audio -->

<!-- wp:audio {"id":2222} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio2.mp3"></audio></figure>
<!-- /wp:audio -->
HTML;

		$known_ids = [
			1111 => 9111,
			2222 => 9222,
		];
		$result    = $this->updater->update_audio_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":9111', $result );
		$this->assertStringContainsString( '"id":9222', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_with_mapping_to_different_value_updates(): void {
		$content = <<<'HTML'
<!-- wp:audio {"id":1000} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio.mp3"></audio></figure>
<!-- /wp:audio -->
HTML;

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_audio_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_with_mapping_to_same_value_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:audio {"id":1000} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio.mp3"></audio></figure>
<!-- /wp:audio -->
HTML;

		$known_ids = [ 1000 => 1000 ];
		$result    = $this->updater->update_audio_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:audio {"id":1000} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio.mp3"></audio></figure>
<!-- /wp:audio -->
HTML;

		$known_ids = [];
		$result    = $this->updater->update_audio_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_resolver_gets_used_when_no_known_id_is_found_and_known_ids_gets_updated(): void {

		// Create a resolver that returns a specific ID for the first audio and a different ID for the second audio.
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) {
				if ( 'https://example.com/audio1.mp3' === $url ) {
						return 99999;
				} elseif ( 'https://example.com/audio2.mp3' === $url ) {
					return 88888;
				}
			
				// This should never be called, because audio3.mp3 is not a local hostname alias, but still leaving it as bait.
				if ( 'https://not-local-hostname-alias.com/audio3.mp3' === $url ) {
					return 77777;
				}

				return null;
			} 
		);

		$content = <<<'HTML'
<!-- wp:audio {"id":1000} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio1.mp3"></audio></figure>
<!-- /wp:audio -->

<!-- wp:audio {"id":2000} -->
<figure class="wp-block-audio"><audio controls src="https://not-local-hostname-alias.com/audio3.mp3"></audio></figure>
<!-- /wp:audio -->

<!-- wp:audio {"id":1001} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio2.mp3"></audio></figure>
<!-- /wp:audio -->
HTML;

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_audio_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Audios 1 and 3 should be updated.
		$this->assertStringContainsString( '"id":99999', $result );
		$this->assertStringContainsString( '"id":88888', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
		$this->assertStringNotContainsString( '"id":1001', $result );

		// Audio 2 should not be updated because it is not a local hostname alias.
		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringNotContainsString( '"id":77777', $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	// =========================================================================
	// VIDEO BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_updates_id(): void {
		$content = $this->load_fixture( 'video-block' );

		$known_ids = [ 2222 => 8888 ];
		$result    = $this->updater->update_video_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":8888', $result );
		$this->assertStringNotContainsString( '"id":2222', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_does_not_update_other_block_types(): void {
		$content = <<<'HTML'
<!-- wp:video {"id":1111} -->
<figure class="wp-block-video"><video controls src="video.mp4"></video></figure>
<!-- /wp:video -->

<!-- wp:somecustomblock {"id":1111} -->
<div>Custom block with same ID</div>
<!-- /wp:somecustomblock -->
HTML;

		$known_ids = [ 1111 => 9999 ];
		$result    = $this->updater->update_video_blocks_ids( $content, $known_ids );

		// Video block should be updated.
		$this->assertStringContainsString( '<!-- wp:video {"id":9999}', $result );
		// Custom block should NOT be updated.
		$this->assertStringContainsString( '<!-- wp:somecustomblock {"id":1111}', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_with_mapping_to_different_value_updates(): void {
		$content = <<<'HTML'
<!-- wp:video {"id":1000} -->
<figure class="wp-block-video"><video controls src="https://example.com/video.mp4"></video></figure>
<!-- /wp:video -->
HTML;

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_video_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_with_mapping_to_same_value_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:video {"id":1000} -->
<figure class="wp-block-video"><video controls src="https://example.com/video.mp4"></video></figure>
<!-- /wp:video -->
HTML;

		$known_ids = [ 1000 => 1000 ];
		$result    = $this->updater->update_video_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:video {"id":1000} -->
<figure class="wp-block-video"><video controls src="https://example.com/video.mp4"></video></figure>
<!-- /wp:video -->
HTML;

		$known_ids = [];
		$result    = $this->updater->update_video_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_resolver_gets_used_when_no_known_id_is_found_and_known_ids_gets_updated(): void {

		// Create a resolver that returns a specific ID for the first video and a different ID for the second video.
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) {
				if ( 'https://example.com/video1.mp4' === $url ) {
						return 99999;
				} elseif ( 'https://example.com/video2.mp4' === $url ) {
					return 88888;
				}
			
				// This should never be called, because video3.mp4 is not a local hostname alias, but still leaving it as bait.
				if ( 'https://not-local-hostname-alias.com/video3.mp4' === $url ) {
					return 77777;
				}

				return null;
			} 
		);

		$content = <<<'HTML'
<!-- wp:video {"id":1000} -->
<figure class="wp-block-video"><video controls src="https://example.com/video1.mp4"></video></figure>
<!-- /wp:video -->

<!-- wp:video {"id":2000} -->
<figure class="wp-block-video"><video controls src="https://not-local-hostname-alias.com/video3.mp4"></video></figure>
<!-- /wp:video -->

<!-- wp:video {"id":1001} -->
<figure class="wp-block-video"><video controls src="https://example.com/video2.mp4"></video></figure>
<!-- /wp:video -->
HTML;

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_video_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Videos 1 and 3 should be updated.
		$this->assertStringContainsString( '"id":99999', $result );
		$this->assertStringContainsString( '"id":88888', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
		$this->assertStringNotContainsString( '"id":1001', $result );

		// Video 2 should not be updated because it is not a local hostname alias.
		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringNotContainsString( '"id":77777', $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	// =========================================================================
	// FILE BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_file_blocks_ids
	 */
	public function file_block_updates_id(): void {
		$content = $this->load_fixture( 'file-block' );

		$known_ids = [ 3333 => 7777 ];
		$result    = $this->updater->update_file_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":7777', $result );
		$this->assertStringNotContainsString( '"id":3333', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_file_blocks_ids
	 */
	public function file_block_with_mapping_to_different_value_updates(): void {
		$content = <<<'HTML'
<!-- wp:file {"id":1000,"href":"https://example.com/document.pdf"} -->
<div class="wp-block-file"><a href="https://example.com/document.pdf">Download</a></div>
<!-- /wp:file -->
HTML;

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_file_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_file_blocks_ids
	 */
	public function file_block_with_mapping_to_same_value_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:file {"id":1000,"href":"https://example.com/document.pdf"} -->
<div class="wp-block-file"><a href="https://example.com/document.pdf">Download</a></div>
<!-- /wp:file -->
HTML;

		$known_ids = [ 1000 => 1000 ];
		$result    = $this->updater->update_file_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_file_blocks_ids
	 */
	public function file_block_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:file {"id":1000,"href":"https://example.com/document.pdf"} -->
<div class="wp-block-file"><a href="https://example.com/document.pdf">Download</a></div>
<!-- /wp:file -->
HTML;

		$known_ids = [];
		$result    = $this->updater->update_file_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_file_blocks_ids
	 */
	public function file_block_resolver_gets_used_when_no_known_id_is_found_and_known_ids_gets_updated(): void {

		// Create a resolver that returns a specific ID for the first file and a different ID for the second file.
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) {
				if ( 'https://example.com/file1.pdf' === $url ) {
						return 99999;
				} elseif ( 'https://example.com/file2.pdf' === $url ) {
					return 88888;
				}
			
				// This should never be called, because file3.pdf is not a local hostname alias, but still leaving it as bait.
				if ( 'https://not-local-hostname-alias.com/file3.pdf' === $url ) {
					return 77777;
				}

				return null;
			} 
		);

		$content = <<<'HTML'
<!-- wp:file {"id":1000,"href":"https://example.com/file1.pdf"} -->
<div class="wp-block-file"><a href="https://example.com/file1.pdf">file1.pdf</a></div>
<!-- /wp:file -->

<!-- wp:file {"id":2000,"href":"https://not-local-hostname-alias.com/file3.pdf"} -->
<div class="wp-block-file"><a href="https://not-local-hostname-alias.com/file3.pdf">file3.pdf</a></div>
<!-- /wp:file -->

<!-- wp:file {"id":1001,"href":"https://example.com/file2.pdf"} -->
<div class="wp-block-file"><a href="https://example.com/file2.pdf">file2.pdf</a></div>
<!-- /wp:file -->
HTML;

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_file_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Files 1 and 3 should be updated.
		$this->assertStringContainsString( '"id":99999', $result );
		$this->assertStringContainsString( '"id":88888', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
		$this->assertStringNotContainsString( '"id":1001', $result );

		// File 2 should not be updated because it is not a local hostname alias.
		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringNotContainsString( '"id":77777', $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	// =========================================================================
	// COVER BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_cover_blocks_ids
	 */
	public function cover_block_updates_id_in_header_and_class(): void {
		$content = $this->load_fixture( 'cover-block' );

		$known_ids = [ 4444 => 8888 ];
		$result    = $this->updater->update_cover_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":8888', $result );
		$this->assertStringContainsString( 'wp-image-8888', $result );
		$this->assertStringNotContainsString( '"id":4444', $result );
		$this->assertStringNotContainsString( 'wp-image-4444', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_cover_blocks_ids
	 */
	public function cover_block_with_mapping_to_different_value_updates(): void {
		$content = <<<'HTML'
<!-- wp:cover {"url":"https://example.com/cover.jpg","id":1000} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-1000" src="https://example.com/cover.jpg"/></div>
<!-- /wp:cover -->
HTML;

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_cover_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringContainsString( 'wp-image-2000', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
		$this->assertStringNotContainsString( 'wp-image-1000', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_cover_blocks_ids
	 */
	public function cover_block_with_mapping_to_same_value_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:cover {"url":"https://example.com/cover.jpg","id":1000} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-1000" src="https://example.com/cover.jpg"/></div>
<!-- /wp:cover -->
HTML;

		$known_ids = [ 1000 => 1000 ];
		$result    = $this->updater->update_cover_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_cover_blocks_ids
	 */
	public function cover_block_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:cover {"url":"https://example.com/cover.jpg","id":1000} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-1000" src="https://example.com/cover.jpg"/></div>
<!-- /wp:cover -->
HTML;

		$known_ids = [];
		$result    = $this->updater->update_cover_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_cover_blocks_ids
	 */
	public function cover_block_resolver_gets_used_when_no_known_id_is_found_and_known_ids_gets_updated(): void {

		// Create a resolver that returns a specific ID for the first cover and a different ID for the second cover.
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) {
				if ( 'https://example.com/cover1.jpg' === $url ) {
						return 99999;
				} elseif ( 'https://example.com/cover2.jpg' === $url ) {
					return 88888;
				}
			
				// This should never be called, because cover3.jpg is not a local hostname alias, but still leaving it as bait.
				if ( 'https://not-local-hostname-alias.com/cover3.jpg' === $url ) {
					return 77777;
				}

				return null;
			} 
		);

		$content = <<<'HTML'
<!-- wp:cover {"url":"https://example.com/cover1.jpg","id":1000} -->
<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim"></span><img class="wp-block-cover__image-background wp-image-1000" alt="" src="https://example.com/cover1.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","placeholder":"Write title…","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"></p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->

<!-- wp:cover {"url":"https://not-local-hostname-alias.com/cover3.jpg","id":2000} -->
<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim"></span><img class="wp-block-cover__image-background wp-image-2000" alt="" src="https://not-local-hostname-alias.com/cover3.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","placeholder":"Write title…","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"></p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->

<!-- wp:cover {"url":"https://example.com/cover2.jpg","id":1001} -->
<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim"></span><img class="wp-block-cover__image-background wp-image-1001" alt="" src="https://example.com/cover2.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","placeholder":"Write title…","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"></p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->
HTML;

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_cover_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Covers 1 and 3 should be updated.
		$this->assertStringContainsString( '"id":99999', $result );
		$this->assertStringContainsString( '"id":88888', $result );
		$this->assertStringNotContainsString( '"id":1000', $result );
		$this->assertStringNotContainsString( '"id":1001', $result );
		$this->assertStringContainsString( 'wp-image-99999', $result );
		$this->assertStringContainsString( 'wp-image-88888', $result );
		$this->assertStringNotContainsString( 'wp-image-1000', $result );
		$this->assertStringNotContainsString( 'wp-image-1001', $result );

		// Cover 2 should not be updated because it is not a local hostname alias.
		$this->assertStringContainsString( '"id":2000', $result );
		$this->assertStringContainsString( 'wp-image-2000', $result );
		$this->assertStringNotContainsString( '"id":77777', $result );
		$this->assertStringNotContainsString( 'wp-image-77777', $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	// =========================================================================
	// MEDIA-TEXT BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_mediatext_blocks_ids
	 */
	public function media_text_block_updates_media_id_and_class(): void {
		$content = $this->load_fixture( 'media-text-block' );

		$known_ids = [ 5555 => 9555 ];
		$result    = $this->updater->update_mediatext_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"mediaId":9555', $result );
		$this->assertStringContainsString( 'wp-image-9555', $result );
		$this->assertStringNotContainsString( '"mediaId":5555', $result );
		$this->assertStringNotContainsString( 'wp-image-5555', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_mediatext_blocks_ids
	 */
	public function media_text_block_with_mapping_to_different_value_updates(): void {
		$content = <<<'HTML'
<!-- wp:media-text {"mediaId":1000,"mediaLink":"https://example.com/post/"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://example.com/image.jpg" class="wp-image-1000"/></figure></div>
<!-- /wp:media-text -->
HTML;

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_mediatext_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"mediaId":2000', $result );
		$this->assertStringContainsString( 'wp-image-2000', $result );
		$this->assertStringNotContainsString( '"mediaId":1000', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_mediatext_blocks_ids
	 */
	public function media_text_block_with_mapping_to_same_value_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:media-text {"mediaId":1000,"mediaLink":"https://example.com/post/"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://example.com/image.jpg" class="wp-image-1000"/></figure></div>
<!-- /wp:media-text -->
HTML;

		$known_ids = [ 1000 => 1000 ];
		$result    = $this->updater->update_mediatext_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_mediatext_blocks_ids
	 */
	public function media_text_block_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:media-text {"mediaId":1000,"mediaLink":"https://example.com/post/"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://example.com/image.jpg" class="wp-image-1000"/></figure></div>
<!-- /wp:media-text -->
HTML;

		$known_ids = [];
		$result    = $this->updater->update_mediatext_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_mediatext_blocks_ids
	 */
	public function media_text_block_resolver_gets_used_when_no_known_id_is_found_and_known_ids_gets_updated(): void {

		// Create a resolver that returns a specific ID for the first media-text and a different ID for the second media-text.
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) {
				if ( 'https://example.com/media1.jpg' === $url ) {
						return 99999;
				} elseif ( 'https://example.com/media2.jpg' === $url ) {
					return 88888;
				}
			
				// This should never be called, because media3.jpg is not a local hostname alias, but still leaving it as bait.
				if ( 'https://not-local-hostname-alias.com/media3.jpg' === $url ) {
					return 77777;
				}

				return null;
			} 
		);

		$content = <<<'HTML'
<!-- wp:media-text {"mediaId":1000,"mediaLink":"https://example.com/post/"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://example.com/media1.jpg" class="wp-image-1000"/></figure></div>
<!-- /wp:media-text -->

<!-- wp:media-text {"mediaId":2000,"mediaLink":"https://example.com/post/"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://not-local-hostname-alias.com/media3.jpg" class="wp-image-2000"/></figure></div>
<!-- /wp:media-text -->

<!-- wp:media-text {"mediaId":1001,"mediaLink":"https://example.com/post/"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://example.com/media2.jpg" class="wp-image-1001"/></figure></div>
<!-- /wp:media-text -->
HTML;

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_mediatext_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Media-texts 1 and 3 should be updated.
		$this->assertStringContainsString( '"mediaId":99999', $result );
		$this->assertStringContainsString( '"mediaId":88888', $result );
		$this->assertStringNotContainsString( '"mediaId":1000', $result );
		$this->assertStringNotContainsString( '"mediaId":1001', $result );
		$this->assertStringContainsString( 'wp-image-99999', $result );
		$this->assertStringContainsString( 'wp-image-88888', $result );
		$this->assertStringNotContainsString( 'wp-image-1000', $result );
		$this->assertStringNotContainsString( 'wp-image-1001', $result );

		// Media-text 2 should not be updated because it is not a local hostname alias.
		$this->assertStringContainsString( '"mediaId":2000', $result );
		$this->assertStringContainsString( 'wp-image-2000', $result );
		$this->assertStringNotContainsString( '"mediaId":77777', $result );
		$this->assertStringNotContainsString( 'wp-image-77777', $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	// =========================================================================
	// JETPACK TILED GALLERY TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 */
	public function jetpack_tiled_gallery_updates_all_ids(): void {
		$content = $this->load_fixture( 'jetpack-tiled-gallery' );

		$known_ids = [
			6001 => 9001,
			6002 => 9002,
			6003 => 9003,
		];
		$result    = $this->updater->update_jetpacktiledgallery_blocks_ids( $content, $known_ids );

		// Header IDs array should be updated.
		$this->assertStringContainsString( '"ids":[9001,9002,9003]', $result );

		// data-id attributes should be updated.
		$this->assertStringContainsString( 'data-id="9001"', $result );
		$this->assertStringContainsString( 'data-id="9002"', $result );
		$this->assertStringContainsString( 'data-id="9003"', $result );

		// Old IDs should be gone.
		$this->assertStringNotContainsString( '"ids":[6001,6002,6003]', $result );
		$this->assertStringNotContainsString( 'data-id="6001"', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 */
	public function jetpack_tiled_gallery_with_mapping_to_different_values_updates(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/tiled-gallery {"ids":[1001,1002,1003]} -->
<div class="wp-block-jetpack-tiled-gallery">
<img data-id="1001" src="https://example.com/img1.jpg" class="wp-image-1001"/>
<img data-id="1002" src="https://example.com/img2.jpg" class="wp-image-1002"/>
<img data-id="1003" src="https://example.com/img3.jpg" class="wp-image-1003"/>
</div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$known_ids = [
			1001 => 2001,
			1002 => 2002,
			1003 => 2003,
		];
		$result    = $this->updater->update_jetpacktiledgallery_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"ids":[2001,2002,2003]', $result );
		$this->assertStringContainsString( 'data-id="2001"', $result );
		$this->assertStringContainsString( 'data-id="2002"', $result );
		$this->assertStringContainsString( 'data-id="2003"', $result );
		$this->assertStringContainsString( 'wp-image-2001', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 */
	public function jetpack_tiled_gallery_with_mapping_to_same_values_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/tiled-gallery {"ids":[1001,1002]} -->
<div><img data-id="1001" class="wp-image-1001"/></div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$known_ids = [
			1001 => 1001,
			1002 => 1002,
		];
		$result    = $this->updater->update_jetpacktiledgallery_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 */
	public function jetpack_tiled_gallery_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/tiled-gallery {"ids":[1001,1002]} -->
<div><img data-id="1001" class="wp-image-1001"/></div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$known_ids = [];
		$result    = $this->updater->update_jetpacktiledgallery_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 */
	public function jetpack_tiled_gallery_resolver_gets_used_when_no_known_id_is_found_and_known_ids_gets_updated(): void {

		// Create a resolver that returns a specific ID for the first image and a different ID for the second image.
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) {
				if ( 'https://example.com/gallery1.jpg' === $url ) {
						return 99999;
				} elseif ( 'https://example.com/gallery2.jpg' === $url ) {
					return 88888;
				}
			
				// This should never be called, because gallery3.jpg is not a local hostname alias, but still leaving it as bait.
				if ( 'https://not-local-hostname-alias.com/gallery3.jpg' === $url ) {
					return 77777;
				}

				return null;
			} 
		);

		$content = <<<'HTML'
<!-- wp:jetpack/tiled-gallery {"ids":[1000,1001,2000]} -->
<div><img data-id="1000" src="https://example.com/gallery1.jpg" class="wp-image-1000"/></div>
<div><img data-id="2000" src="https://not-local-hostname-alias.com/gallery3.jpg" class="wp-image-2000"/></div>
<div><img data-id="1001" src="https://example.com/gallery2.jpg" class="wp-image-1001"/></div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_jetpacktiledgallery_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Images 1 and 3 should be updated.
		$this->assertStringContainsString( 'data-id="99999"', $result );
		$this->assertStringContainsString( 'data-id="88888"', $result );
		$this->assertStringNotContainsString( 'data-id="1000"', $result );
		$this->assertStringNotContainsString( 'data-id="1001"', $result );
		$this->assertStringContainsString( 'wp-image-99999', $result );
		$this->assertStringContainsString( 'wp-image-88888', $result );
		$this->assertStringNotContainsString( 'wp-image-1000', $result );
		$this->assertStringNotContainsString( 'wp-image-1001', $result );

		// Image 2 should not be updated because it is not a local hostname alias.
		$this->assertStringContainsString( 'data-id="2000"', $result );
		$this->assertStringContainsString( 'wp-image-2000', $result );
		$this->assertStringNotContainsString( 'data-id="77777"', $result );
		$this->assertStringNotContainsString( 'wp-image-77777', $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	// =========================================================================
	// JETPACK SLIDESHOW TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackslideshow_blocks_ids
	 */
	public function jetpack_slideshow_updates_all_ids(): void {
		$content = $this->load_fixture( 'jetpack-slideshow' );

		$known_ids = [
			7001 => 9701,
			7002 => 9702,
			7003 => 9703,
		];
		$result    = $this->updater->update_jetpackslideshow_blocks_ids( $content, $known_ids );

		// Header IDs array should be updated.
		$this->assertStringContainsString( '"ids":[9701,9702,9703]', $result );

		// data-id and class attributes should be updated.
		$this->assertStringContainsString( 'data-id="9701"', $result );
		$this->assertStringContainsString( 'wp-image-9701', $result );
		$this->assertStringContainsString( 'data-id="9702"', $result );
		$this->assertStringContainsString( 'data-id="9703"', $result );

		// Old IDs should be gone.
		$this->assertStringNotContainsString( 'data-id="7001"', $result );
		$this->assertStringNotContainsString( 'wp-image-7001', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackslideshow_blocks_ids
	 */
	public function jetpack_slideshow_with_mapping_to_different_values_updates(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/slideshow {"ids":[1001,1002,1003]} -->
<div class="wp-block-jetpack-slideshow">
<img data-id="1001" src="https://example.com/img1.jpg" class="wp-image-1001"/>
<img data-id="1002" src="https://example.com/img2.jpg" class="wp-image-1002"/>
<img data-id="1003" src="https://example.com/img3.jpg" class="wp-image-1003"/>
</div>
<!-- /wp:jetpack/slideshow -->
HTML;

		$known_ids = [
			1001 => 2001,
			1002 => 2002,
			1003 => 2003,
		];
		$result    = $this->updater->update_jetpackslideshow_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"ids":[2001,2002,2003]', $result );
		$this->assertStringContainsString( 'data-id="2001"', $result );
		$this->assertStringContainsString( 'data-id="2002"', $result );
		$this->assertStringContainsString( 'wp-image-2001', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackslideshow_blocks_ids
	 */
	public function jetpack_slideshow_with_mapping_to_same_values_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/slideshow {"ids":[1001,1002]} -->
<div><img data-id="1001" class="wp-image-1001"/></div>
<!-- /wp:jetpack/slideshow -->
HTML;

		$known_ids = [
			1001 => 1001,
			1002 => 1002,
		];
		$result    = $this->updater->update_jetpackslideshow_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackslideshow_blocks_ids
	 */
	public function jetpack_slideshow_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/slideshow {"ids":[1001,1002]} -->
<div><img data-id="1001" class="wp-image-1001"/></div>
<!-- /wp:jetpack/slideshow -->
HTML;

		$known_ids = [];
		$result    = $this->updater->update_jetpackslideshow_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackslideshow_blocks_ids
	 */
	public function jetpack_slideshow_resolver_gets_used_when_no_known_id_is_found_and_known_ids_gets_updated(): void {

		// Create a resolver that returns a specific ID for the first image and a different ID for the second image.
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) {
				if ( 'https://example.com/slide1.jpg' === $url ) {
						return 99999;
				} elseif ( 'https://example.com/slide2.jpg' === $url ) {
					return 88888;
				}
			
				// This should never be called, because slide3.jpg is not a local hostname alias, but still leaving it as bait.
				if ( 'https://not-local-hostname-alias.com/slide3.jpg' === $url ) {
					return 77777;
				}

				return null;
			} 
		);

		$content = <<<'HTML'
<!-- wp:jetpack/slideshow {"ids":[1000,1001,2000]} -->
<div><img data-id="1000" src="https://example.com/slide1.jpg" class="wp-image-1000"/></div>
<div><img data-id="2000" src="https://not-local-hostname-alias.com/slide3.jpg" class="wp-image-2000"/></div>
<div><img data-id="1001" src="https://example.com/slide2.jpg" class="wp-image-1001"/></div>
<!-- /wp:jetpack/slideshow -->
HTML;

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_jetpackslideshow_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Images 1 and 3 should be updated.
		$this->assertStringContainsString( 'data-id="99999"', $result );
		$this->assertStringContainsString( 'data-id="88888"', $result );
		$this->assertStringNotContainsString( 'data-id="1000"', $result );
		$this->assertStringNotContainsString( 'data-id="1001"', $result );
		$this->assertStringContainsString( 'wp-image-99999', $result );
		$this->assertStringContainsString( 'wp-image-88888', $result );
		$this->assertStringNotContainsString( 'wp-image-1000', $result );
		$this->assertStringNotContainsString( 'wp-image-1001', $result );

		// Image 2 should not be updated because it is not a local hostname alias.
		$this->assertStringContainsString( 'data-id="2000"', $result );
		$this->assertStringContainsString( 'wp-image-2000', $result );
		$this->assertStringNotContainsString( 'data-id="77777"', $result );
		$this->assertStringNotContainsString( 'wp-image-77777', $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	// =========================================================================
	// JETPACK IMAGE COMPARE TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackimagecompare_blocks_ids
	 */
	public function jetpack_image_compare_updates_both_ids(): void {
		$content = $this->load_fixture( 'jetpack-image-compare' );

		$known_ids = [
			8001 => 9801,
			8002 => 9802,
		];
		$result    = $this->updater->update_jetpackimagecompare_blocks_ids( $content, $known_ids );

		// Header imageBefore and imageAfter should be updated.
		$this->assertStringContainsString( '"id":9801', $result );
		$this->assertStringContainsString( '"id":9802', $result );

		// HTML element id attributes should be updated.
		$this->assertStringContainsString( 'id="9801"', $result );
		$this->assertStringContainsString( 'id="9802"', $result );

		// Old IDs should be gone.
		$this->assertStringNotContainsString( '"id":8001', $result );
		$this->assertStringNotContainsString( '"id":8002', $result );
		$this->assertStringNotContainsString( 'id="8001"', $result );
		$this->assertStringNotContainsString( 'id="8002"', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackimagecompare_blocks_ids
	 */
	public function jetpack_image_compare_with_mapping_to_different_values_updates_both_images(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/image-compare {"imageBefore":{"id":1001,"url":"https://example.com/before.jpg"},"imageAfter":{"id":1002,"url":"https://example.com/after.jpg"}} -->
<figure class="wp-block-jetpack-image-compare">
<img id="1001" src="https://example.com/before.jpg" class="image-compare__image-before wp-image-1001"/>
<img id="1002" src="https://example.com/after.jpg" class="image-compare__image-after wp-image-1002"/>
</figure>
<!-- /wp:jetpack/image-compare -->
HTML;

		$known_ids = [
			1001 => 2001,
			1002 => 2002,
		];
		$result    = $this->updater->update_jetpackimagecompare_blocks_ids( $content, $known_ids );

		// Block header attributes.
		$this->assertStringContainsString( '"id":2001', $result );
		$this->assertStringContainsString( '"id":2002', $result );
		// HTML id attributes.
		$this->assertStringContainsString( 'id="2001"', $result );
		$this->assertStringContainsString( 'id="2002"', $result );
		// HTML class attributes.
		$this->assertStringContainsString( 'wp-image-2001', $result );
		$this->assertStringContainsString( 'wp-image-2002', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackimagecompare_blocks_ids
	 */
	public function jetpack_image_compare_with_mapping_to_same_values_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/image-compare {"imageBefore":{"id":1001},"imageAfter":{"id":1002}} -->
<figure><img id="1001" class="wp-image-1001"/><img id="1002" class="wp-image-1002"/></figure>
<!-- /wp:jetpack/image-compare -->
HTML;

		$known_ids = [
			1001 => 1001,
			1002 => 1002,
		];
		$result    = $this->updater->update_jetpackimagecompare_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_jetpackimagecompare_blocks_ids
	 */
	public function jetpack_image_compare_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/image-compare {"imageBefore":{"id":1001},"imageAfter":{"id":1002}} -->
<figure><img id="1001" class="wp-image-1001"/><img id="1002" class="wp-image-1002"/></figure>
<!-- /wp:jetpack/image-compare -->
HTML;

		$known_ids = [];
		$result    = $this->updater->update_jetpackimagecompare_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	// =========================================================================
	// GUTENBERG BLOCK HEADER TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_gutenberg_blocks_headers_single_id
	 */
	public function update_headers_single_id_updates_specific_block_type(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":1111,"sizeSlug":"large"} -->
<figure>Image</figure>
<!-- /wp:image -->

<!-- wp:audio {"id":2222} -->
<figure>Audio</figure>
<!-- /wp:audio -->
HTML;

		$id_map = [ 1111 => 9999 ];
		$result = $this->updater->update_gutenberg_blocks_headers_single_id( 'wp:image', $id_map, $content );

		// Image block should be updated.
		$this->assertStringContainsString( '<!-- wp:image {"id":9999', $result );
		// Audio block should NOT be updated (different block type).
		$this->assertStringContainsString( '<!-- wp:audio {"id":2222', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_gutenberg_blocks_headers_multiple_ids
	 */
	public function update_headers_multiple_ids_updates_ids_array(): void {
		$content = '<!-- wp:jetpack/slideshow {"ids":[1111,2222,3333],"sizeSlug":"large"} -->';

		$id_map = [
			1111 => 9111,
			2222 => 9222,
			3333 => 9333,
		];
		$result = $this->updater->update_gutenberg_blocks_headers_multiple_ids( $id_map, $content );

		$this->assertStringContainsString( '"ids":[9111,9222,9333]', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_gutenberg_blocks_headers_multiple_ids
	 */
	public function update_headers_multiple_ids_partial_update(): void {
		$content = '<!-- wp:jetpack/tiled-gallery {"ids":[1111,2222,3333]} -->';

		// Only map some IDs.
		$id_map = [
			1111 => 9111,
			// 2222 not mapped - should stay as is.
			3333 => 9333,
		];
		$result = $this->updater->update_gutenberg_blocks_headers_multiple_ids( $id_map, $content );

		$this->assertStringContainsString( '"ids":[9111,2222,9333]', $result );
	}


	// =========================================================================
	// UPDATE ALL BLOCKS TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers BlockUpdater::update_all_blocks_ids
	 */
	public function update_all_blocks_ids_processes_all_block_types(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":1111} -->
<figure class="wp-block-image"><img src="img.jpg" class="wp-image-1111"/></figure>
<!-- /wp:image -->

<!-- wp:audio {"id":2222} -->
<figure class="wp-block-audio"><audio controls src="audio.mp3"></audio></figure>
<!-- /wp:audio -->

<!-- wp:video {"id":3333} -->
<figure class="wp-block-video"><video controls src="video.mp4"></video></figure>
<!-- /wp:video -->
HTML;

		$known_ids = [
			1111 => 9111,
			2222 => 9222,
			3333 => 9333,
		];
		$result    = $this->updater->update_all_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":9111', $result );
		$this->assertStringContainsString( '"id":9222', $result );
		$this->assertStringContainsString( '"id":9333', $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_all_blocks_ids
	 */
	public function update_all_blocks_returns_unchanged_when_no_blocks(): void {
		$content = '<p>Just some plain text without any blocks.</p>';

		$known_ids = [ 1111 => 9999 ];
		$result    = $this->updater->update_all_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers BlockUpdater::update_all_blocks_ids
	 */
	public function update_all_blocks_returns_unchanged_with_empty_content(): void {
		$known_ids = [ 1111 => 9999 ];
		$result    = $this->updater->update_all_blocks_ids( '', $known_ids );

		$this->assertEquals( '', $result );
	}

	// =========================================================================
	// EDGE CASES AND REGRESSION TESTS
	// =========================================================================

	/**
	 * @test
	 */
	public function handles_multiple_same_ids_in_content(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":12345} -->
<figure class="wp-block-image"><img src="img1.jpg" class="wp-image-12345"/></figure>
<!-- /wp:image -->

<!-- wp:gallery {"linkTo":"none"} -->
<figure class="wp-block-gallery"><!-- wp:image {"id":12345} -->
<figure class="wp-block-image"><img src="img1.jpg" class="wp-image-12345"/></figure>
<!-- /wp:image --></figure>
<!-- /wp:gallery -->
HTML;

		$known_ids = [ 12345 => 99999 ];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		// Both occurrences should be updated.
		$this->assertEquals( 2, substr_count( $result, '"id":99999' ) );
		$this->assertEquals( 2, substr_count( $result, 'wp-image-99999' ) );
		$this->assertEquals( 0, substr_count( $result, '"id":12345' ) );
	}

	/**
	 * @test
	 */
	public function handles_large_ids(): void {
		$content = '<!-- wp:image {"id":999999999} --><figure class="wp-block-image"><img src="test.jpg" class="wp-image-999999999"/></figure><!-- /wp:image -->';

		$known_ids = [ 999999999 => 888888888 ];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":888888888', $result );
		$this->assertStringContainsString( 'wp-image-888888888', $result );
	}

	/**
	 * @test
	 */
	public function preserves_other_block_attributes(): void {
		$content = '<!-- wp:image {"id":111,"sizeSlug":"large","linkDestination":"media","align":"center"} --><figure></figure><!-- /wp:image -->';

		$known_ids = [ 111 => 999 ];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"sizeSlug":"large"', $result );
		$this->assertStringContainsString( '"linkDestination":"media"', $result );
		$this->assertStringContainsString( '"align":"center"', $result );
	}

	/**
	 * @test
	 */
	public function known_ids_array_is_updated_by_reference(): void {
		$content = '<!-- wp:image {"id":111} --><figure class="wp-block-image"><img src="test.jpg" class="wp-image-111"/></figure><!-- /wp:image -->';

		// Pre-populate known IDs.
		$known_ids = [ 111 => 999 ];
		$this->updater->update_image_blocks_ids( $content, $known_ids );

		// The mapping should still be there.
		$this->assertEquals( 999, $known_ids[111] );
	}

	/**
	 * @test
	 */
	public function empty_known_ids_does_not_cause_error(): void {
		$content = '<!-- wp:image {"id":111} --><figure></figure><!-- /wp:image -->';

		$known_ids = [];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		// Should return content unchanged (no mapping available).
		$this->assertStringContainsString( '"id":111', $result );
	}
}
