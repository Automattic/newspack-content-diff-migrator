<?php
/**
 * Unit tests for BlockUpdater.
 *
 * These tests assert/validate that the entire content and block strings are valid,
 * not just individual ID updates, but the entire block content and the surrounding HTML.
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::__construct
	 */
	public function attachment_url_resolver_can_be_injected(): void {

		// Create a resolver that always returns a specific ID.
		$resolver = function ( string $url ) { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.Found.
			return 999;
		};

		$updater = new BlockUpdater( $resolver );

		$template = <<<'HTML'
<!-- wp:image {"id":%d} -->
<figure class="wp-block-image"><img src="https://example.com/test.jpg" class="wp-image-%d"/></figure>
<!-- /wp:image -->
HTML;

		$content_before         = sprintf( $template, 111, 111 );
		$content_after_expected = sprintf( $template, 999, 999 );

		// Must provide local_hostname_aliases to tell the logic that example.com should be queried as local.
		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $updater->update_image_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );
	}

	// =========================================================================
	// IMAGE BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_updates_id_in_header_and_class(): void {
		$template = <<<'HTML'
<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://example.com/image.jpg" alt="" class="wp-image-%d"/></figure>
<!-- /wp:image -->
HTML;

		$content_before         = sprintf( $template, 111111, 111111 );
		$content_after_expected = sprintf( $template, 999999, 999999 );

		$known_ids                               = [ 111111 => 999999 ];
		$imported_local_attachment_and_block_ids = array_flip( array_map( 'intval', array_values( $known_ids ) ) );
		$result                                  = $this->updater->update_image_blocks_ids( $content_before, $known_ids, $imported_local_attachment_and_block_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_returns_unchanged_when_id_not_in_mapping(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":111111} -->
<figure class="wp-block-image"><img src="test.jpg" class="wp-image-111111"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [ 999999 => 888888 ]; // Different ID.
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 */
	public function image_blocks_nested_in_gallery_get_updated(): void {
		$content_before = $this->load_fixture( 'image-blocks' );

		// Validate fixture contains expected IDs.
		$this->assertStringContainsString( '111111', $content_before, 'Fixture image-blocks.html does not contain expected ID 111111' );
		$this->assertStringContainsString( '222222', $content_before, 'Fixture image-blocks.html does not contain expected ID 222222' );
		$this->assertStringContainsString( '333333', $content_before, 'Fixture image-blocks.html does not contain expected ID 333333' );

		$content_after_expected = str_replace(
			[ '111111', '222222', '333333' ],
			[ '999111', '999222', '999333' ],
			$content_before
		);

		$known_ids = [
			111111 => 999111,
			222222 => 999222,
			333333 => 999333,
		];

		$result = $this->updater->update_image_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_wrapped_in_group_gets_updated(): void {
		$template = <<<'HTML'
<!-- wp:group -->
<!-- wp:image {"id":%d} -->
<figure class="wp-block-image"><img src="test.jpg" class="wp-image-%d"/></figure>
<!-- /wp:image -->
<!-- /wp:group -->
HTML;

		$content_before         = sprintf( $template, 12345, 12345 );
		$content_after_expected = sprintf( $template, 67890, 67890 );

		$known_ids = [ 12345 => 67890 ];
		$result    = $this->updater->update_image_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_element_attribute
	 */
	public function update_image_element_attribute_updates_data_id(): void {
		$content_before         = '<img src="test.jpg" data-id="11111" class="wp-image-11111"/>';
		$content_after_expected = '<img src="test.jpg" data-id="99999" class="wp-image-11111"/>';

		$id_map = [ 11111 => 99999 ];
		$result = $this->updater->update_image_element_attribute( 'data-id', $id_map, $content_before );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_element_class_attribute
	 */
	public function update_image_element_class_updates_wp_image_class(): void {
		$content_before         = '<img src="test.jpg" class="wp-image-11111"/>';
		$content_after_expected = '<img src="test.jpg" class="wp-image-99999"/>';

		$id_map = [ 11111 => 99999 ];
		$result = $this->updater->update_image_element_class_attribute( $id_map, $content_before );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_element_class_attribute
	 */
	public function update_image_element_class_preserves_other_classes(): void {
		$content_before         = '<img src="test.jpg" class="aligncenter wp-image-11111 size-large"/>';
		$content_after_expected = '<img src="test.jpg" class="aligncenter wp-image-99999 size-large"/>';

		$id_map = [ 11111 => 99999 ];
		$result = $this->updater->update_image_element_class_attribute( $id_map, $content_before );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_element_class_attribute
	 */
	public function update_image_element_class_handles_class_at_start(): void {
		$content_before         = '<img src="test.jpg" class="wp-image-11111 otherclass"/>';
		$content_after_expected = '<img src="test.jpg" class="wp-image-99999 otherclass"/>';

		$id_map = [ 11111 => 99999 ];
		$result = $this->updater->update_image_element_class_attribute( $id_map, $content_before );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_element_class_attribute
	 */
	public function update_image_element_class_handles_class_at_end(): void {
		$content_before         = '<img src="test.jpg" class="otherclass wp-image-11111"/>';
		$content_after_expected = '<img src="test.jpg" class="otherclass wp-image-99999"/>';

		$id_map = [ 11111 => 99999 ];
		$result = $this->updater->update_image_element_class_attribute( $id_map, $content_before );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_with_mapping_to_different_value_updates(): void {
		$template = <<<'HTML'
<!-- wp:image {"id":%d,"sizeSlug":"large"} -->
<figure class="wp-block-image"><img src="https://example.com/image.jpg" class="wp-image-%d"/></figure>
<!-- /wp:image -->
HTML;

		$content_before         = sprintf( $template, 1000, 1000 );
		$content_after_expected = sprintf( $template, 2000, 2000 );

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_image_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 */
	public function image_block_with_mapping_to_same_value_skips_update(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":1000,"sizeSlug":"large"} -->
<figure class="wp-block-image"><img src="https://example.com/image.jpg" class="wp-image-1000"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [ 1000 => 1000 ]; // Maps to itself.
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 *
	 * Regression test for the ID-collision false-skip removed from BlockUpdater.
	 *
	 * The previous defensive `in_array( $id, array_values( $map ), true )` check assumed
	 * that any ID also appearing in the map's values was already a correctly-remapped
	 * local ID. In practice this conflated two scenarios:
	 *   - Legitimate chain protection (the ID was previously remapped).
	 *   - False positive (the ID is a stale live ID whose numeric value coincides with
	 *     the local ID assigned to a different imported attachment).
	 * On sites with accumulated NCDM history (multiple cross-source imports), the false
	 * positive caused images in block content to silently render as the wrong attachment.
	 *
	 * The defensive check has been removed. Chain protection in the normal NCDM flow is
	 * provided by the outer run-state filter in `update_featured_image_ids` / the in-
	 * blocks command loop, which prevents already-processed posts from re-entering the
	 * update phase. For callers that invoke the public block-update methods directly,
	 * passing a stable / fresh `$known_attachment_ids_updates` per call avoids the
	 * theoretical chain re-remap.
	 *
	 * Scenario:
	 *   - $known_ids contains 27478 both as a value (live 52837 -> local 27478) and
	 *     as a key (live 27478 -> local 21949).
	 *   - Post content has an image with id 27478.
	 *   - Because we cannot distinguish "already-remapped local 27478" from "stale
	 *     live 27478 needing remap" without source-side context, the function now
	 *     proceeds with the map lookup: 27478 -> 21949.
	 */
	public function image_block_remaps_chain_id_to_target_when_no_source_context(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":27478,"sizeSlug":"large"} -->
<figure class="wp-block-image"><img src="https://example.com/image.jpg" class="wp-image-27478"/></figure>
<!-- /wp:image -->
HTML;

		$known_ids = [
			52837 => 27478, // Unrelated import.
			27478 => 21949, // The mapping that applies here.
		];

		$result = $this->updater->update_image_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"id":21949', $result );
		$this->assertStringContainsString( 'wp-image-21949', $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
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

		$content_before = <<<'HTML'
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

		$content_after_expected = str_replace(
			[ '{"id":1000}', 'wp-image-1000', '{"id":1001}', 'wp-image-1001' ],
			[ '{"id":99999}', 'wp-image-99999', '{"id":88888}', 'wp-image-88888' ],
			$content_before
		);

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_image_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );

		// Assert that $known_ids gets updated with the new IDs.
		$this->assertEquals(
			[
				1000 => 99999,
				1001 => 88888,
			],
			$known_ids 
		);
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 *
	 * Tests that when an ID is not in the known_ids map AND the URL resolver
	 * returns null (attachment not found by URL), the block remains unchanged.
	 * This is the "fallback" scenario where neither map nor URL lookup can resolve the ID.
	 */
	public function image_block_remains_unchanged_when_resolver_returns_null(): void {
		// Create a resolver that always returns null (attachment not found).
		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.Found.
				return null;
			}
		);

		$content = <<<'HTML'
<!-- wp:image {"id":1000} -->
<figure class="wp-block-image"><img src="https://example.com/unfound-image.jpg" class="wp-image-1000"/></figure>
<!-- /wp:image -->
HTML;

		// No known IDs in map, and resolver returns null.
		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_image_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Content should remain unchanged.
		$this->assertEquals( $content, $result );

		// Known IDs should still be empty (no new mappings discovered).
		$this->assertEmpty( $known_ids, 'Known IDs should remain empty when resolver returns null.' );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 *
	 * Tests that when URL is not a local hostname alias, the resolver is not called
	 * and the block remains unchanged (even if the resolver would return a value).
	 */
	public function image_block_skips_resolver_for_non_local_hostname(): void {
		$resolver_called = false;

		$this->updater->set_attachment_url_to_postid_resolver(
			function ( string $url ) use ( &$resolver_called ) { // phpcs:ignore -- Generic.CodeAnalysis.UnusedFunctionParameter.Found.
				$resolver_called = true;
				return 99999; // Would return a value if called.
			}
		);

		$content = <<<'HTML'
<!-- wp:image {"id":1000} -->
<figure class="wp-block-image"><img src="https://external-site.com/external-image.jpg" class="wp-image-1000"/></figure>
<!-- /wp:image -->
HTML;

		// No known IDs, and external-site.com is NOT in local_hostname_aliases.
		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ]; // Does not include external-site.com.
		$result                 = $this->updater->update_image_blocks_ids( $content, $known_ids, $local_hostname_aliases );

		// Content should remain unchanged.
		$this->assertEquals( $content, $result );

		// Resolver should NOT have been called.
		$this->assertFalse( $resolver_called, 'Resolver should not be called for non-local hostnames.' );
	}

	// =========================================================================
	// CORE GALLERY BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_image_blocks_ids
	 */
	public function core_gallery_block_updates_all_ids_using_update_image_blocks_ids(): void {
		$content_before = $this->load_fixture( 'core-gallery-block' );

		// Validate fixture contains expected IDs.
		$this->assertStringContainsString( '7001', $content_before, 'Fixture core-gallery-block.html does not contain expected ID 7001' );
		$this->assertStringContainsString( '7002', $content_before, 'Fixture core-gallery-block.html does not contain expected ID 7002' );
		$this->assertStringContainsString( '7003', $content_before, 'Fixture core-gallery-block.html does not contain expected ID 7003' );

		$content_after_expected = str_replace(
			[ '7001', '7002', '7003' ],
			[ '99991', '99992', '99993' ],
			$content_before
		);

		$known_ids = [
			7001 => 99991,
			7002 => 99992,
			7003 => 99993,
		];

		$result = $this->updater->update_image_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	// =========================================================================
	// AUDIO BLOCK TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_updates_id(): void {
		$content_before = $this->load_fixture( 'audio-block' );

		// Validate fixture contains expected ID.
		$this->assertStringContainsString( '1111', $content_before, 'Fixture audio-block.html does not contain expected ID 1111' );

		$content_after_expected = str_replace( '1111', '9999', $content_before );

		$known_ids = [ 1111 => 9999 ];
		$result    = $this->updater->update_audio_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_multiple_blocks_updated(): void {
		$content_before = <<<'HTML'
<!-- wp:audio {"id":1111} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio1.mp3"></audio></figure>
<!-- /wp:audio -->

<!-- wp:audio {"id":2222} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio2.mp3"></audio></figure>
<!-- /wp:audio -->
HTML;

		$content_after_expected = str_replace(
			[ '{"id":1111}', '{"id":2222}' ],
			[ '{"id":9111}', '{"id":9222}' ],
			$content_before
		);

		$known_ids = [
			1111 => 9111,
			2222 => 9222,
		];
		$result    = $this->updater->update_audio_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_audio_blocks_ids
	 */
	public function audio_block_with_mapping_to_different_value_updates(): void {
		$template = <<<'HTML'
<!-- wp:audio {"id":%d} -->
<figure class="wp-block-audio"><audio controls src="https://example.com/audio.mp3"></audio></figure>
<!-- /wp:audio -->
HTML;

		$content_before         = sprintf( $template, 1000 );
		$content_after_expected = sprintf( $template, 2000 );

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_audio_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_audio_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_audio_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_audio_blocks_ids
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

		$content_before = <<<'HTML'
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

		$content_after_expected = str_replace(
			[ '{"id":1000}', '{"id":1001}' ],
			[ '{"id":99999}', '{"id":88888}' ],
			$content_before
		);

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_audio_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );

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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_updates_id(): void {
		$content_before = $this->load_fixture( 'video-block' );

		// Validate fixture contains expected ID.
		$this->assertStringContainsString( '2222', $content_before, 'Fixture video-block.html does not contain expected ID 2222' );

		$content_after_expected = str_replace( '2222', '8888', $content_before );

		$known_ids = [ 2222 => 8888 ];
		$result    = $this->updater->update_video_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_does_not_update_other_block_types(): void {
		$content_before = <<<'HTML'
<!-- wp:video {"id":1111} -->
<figure class="wp-block-video"><video controls src="video.mp4"></video></figure>
<!-- /wp:video -->

<!-- wp:somecustomblock {"id":1111} -->
<div>Custom block with same ID</div>
<!-- /wp:somecustomblock -->
HTML;

		$content_after_expected = str_replace(
			'<!-- wp:video {"id":1111} -->',
			'<!-- wp:video {"id":9999} -->',
			$content_before
		);

		$known_ids = [ 1111 => 9999 ];
		$result    = $this->updater->update_video_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_video_blocks_ids
	 */
	public function video_block_with_mapping_to_different_value_updates(): void {
		$template = <<<'HTML'
<!-- wp:video {"id":%d} -->
<figure class="wp-block-video"><video controls src="https://example.com/video.mp4"></video></figure>
<!-- /wp:video -->
HTML;

		$content_before         = sprintf( $template, 1000 );
		$content_after_expected = sprintf( $template, 2000 );

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_video_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_video_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_video_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_video_blocks_ids
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

		$content_before = <<<'HTML'
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

		$content_after_expected = str_replace(
			[ '{"id":1000}', '{"id":1001}' ],
			[ '{"id":99999}', '{"id":88888}' ],
			$content_before
		);

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_video_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );

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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_file_blocks_ids
	 */
	public function file_block_updates_id(): void {
		$content_before = $this->load_fixture( 'file-block' );

		// Validate fixture contains expected ID.
		$this->assertStringContainsString( '3333', $content_before, 'Fixture file-block.html does not contain expected ID 3333' );

		$content_after_expected = str_replace( '3333', '7777', $content_before );

		$known_ids = [ 3333 => 7777 ];
		$result    = $this->updater->update_file_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_file_blocks_ids
	 */
	public function file_block_with_mapping_to_different_value_updates(): void {
		$template = <<<'HTML'
<!-- wp:file {"id":%d,"href":"https://example.com/document.pdf"} -->
<div class="wp-block-file"><a href="https://example.com/document.pdf">Download</a></div>
<!-- /wp:file -->
HTML;

		$content_before         = sprintf( $template, 1000 );
		$content_after_expected = sprintf( $template, 2000 );

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_file_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_file_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_file_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_file_blocks_ids
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

		$content_before = <<<'HTML'
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

		$content_after_expected = str_replace(
			[ '{"id":1000,', '{"id":1001,' ],
			[ '{"id":99999,', '{"id":88888,' ],
			$content_before
		);

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_file_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );

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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_cover_blocks_ids
	 */
	public function cover_block_updates_id_in_header_and_class(): void {
		$content_before = $this->load_fixture( 'cover-block' );

		// Validate fixture contains expected ID.
		$this->assertStringContainsString( '4444', $content_before, 'Fixture cover-block.html does not contain expected ID 4444' );

		$content_after_expected = str_replace( '4444', '8888', $content_before );

		$known_ids = [ 4444 => 8888 ];
		$result    = $this->updater->update_cover_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_cover_blocks_ids
	 */
	public function cover_block_with_mapping_to_different_value_updates(): void {
		$template = <<<'HTML'
<!-- wp:cover {"url":"https://example.com/cover.jpg","id":%d} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-%d" src="https://example.com/cover.jpg"/></div>
<!-- /wp:cover -->
HTML;

		$content_before         = sprintf( $template, 1000, 1000 );
		$content_after_expected = sprintf( $template, 2000, 2000 );

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_cover_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_cover_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_cover_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_cover_blocks_ids
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

		$content_before = <<<'HTML'
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

		$content_after_expected = str_replace(
			[ ',"id":1000}', 'wp-image-1000', ',"id":1001}', 'wp-image-1001' ],
			[ ',"id":99999}', 'wp-image-99999', ',"id":88888}', 'wp-image-88888' ],
			$content_before
		);

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_cover_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );

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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_mediatext_blocks_ids
	 */
	public function media_text_block_updates_media_id_and_class(): void {
		$content_before = $this->load_fixture( 'media-text-block' );

		// Validate fixture contains expected ID.
		$this->assertStringContainsString( '5555', $content_before, 'Fixture media-text-block.html does not contain expected ID 5555' );

		$content_after_expected = str_replace( '5555', '9555', $content_before );

		$known_ids = [ 5555 => 9555 ];
		$result    = $this->updater->update_mediatext_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_mediatext_blocks_ids
	 */
	public function media_text_block_with_mapping_to_different_value_updates(): void {
		$template = <<<'HTML'
<!-- wp:media-text {"mediaId":%d,"mediaLink":"https://example.com/post/"} -->
<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="https://example.com/image.jpg" class="wp-image-%d"/></figure></div>
<!-- /wp:media-text -->
HTML;

		$content_before         = sprintf( $template, 1000, 1000 );
		$content_after_expected = sprintf( $template, 2000, 2000 );

		$known_ids = [ 1000 => 2000 ];
		$result    = $this->updater->update_mediatext_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_mediatext_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_mediatext_blocks_ids
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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_mediatext_blocks_ids
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

		$content_before = <<<'HTML'
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

		$content_after_expected = str_replace(
			[ '{"mediaId":1000,', 'wp-image-1000', '{"mediaId":1001,', 'wp-image-1001' ],
			[ '{"mediaId":99999,', 'wp-image-99999', '{"mediaId":88888,', 'wp-image-88888' ],
			$content_before
		);

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_mediatext_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );

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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 */
	public function jetpack_tiled_gallery_updates_all_ids(): void {
		$content_before = $this->load_fixture( 'jetpack-tiled-gallery' );

		// Validate fixture contains expected IDs.
		$this->assertStringContainsString( '6001', $content_before, 'Fixture jetpack-tiled-gallery.html does not contain expected ID 6001' );
		$this->assertStringContainsString( '6002', $content_before, 'Fixture jetpack-tiled-gallery.html does not contain expected ID 6002' );
		$this->assertStringContainsString( '6003', $content_before, 'Fixture jetpack-tiled-gallery.html does not contain expected ID 6003' );

		$content_after_expected = str_replace(
			[ '6001', '6002', '6003' ],
			[ '9001', '9002', '9003' ],
			$content_before
		);

		$known_ids = [
			6001 => 9001,
			6002 => 9002,
			6003 => 9003,
		];

		$result = $this->updater->update_jetpacktiledgallery_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 */
	public function jetpack_tiled_gallery_with_mapping_to_different_values_updates(): void {
		$content_before = <<<'HTML'
<!-- wp:jetpack/tiled-gallery {"ids":[1001,1002,1003]} -->
<div class="wp-block-jetpack-tiled-gallery">
<img data-id="1001" src="https://example.com/img1.jpg" class="wp-image-1001"/>
<img data-id="1002" src="https://example.com/img2.jpg" class="wp-image-1002"/>
<img data-id="1003" src="https://example.com/img3.jpg" class="wp-image-1003"/>
</div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$content_after_expected = str_replace(
			[ '1001', '1002', '1003' ],
			[ '2001', '2002', '2003' ],
			$content_before
		);

		$known_ids = [
			1001 => 2001,
			1002 => 2002,
			1003 => 2003,
		];

		$result = $this->updater->update_jetpacktiledgallery_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpacktiledgallery_blocks_ids
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

		$result = $this->updater->update_jetpacktiledgallery_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 *
	 * Regression test for the ID-collision false-skip removed from BlockUpdater.
	 *
	 * See the docblock on image_block_remaps_chain_id_to_target_when_no_source_context
	 * for the full rationale. The previous behavior preserved IDs that appeared in the
	 * map's values; that behavior caused real production sites to silently render the
	 * wrong attachment. The map lookup now proceeds for every ID that has a key in the
	 * provided map.
	 *
	 * Scenario: Gallery has ids [27478, 5000]. With the map below, both should be
	 * remapped because both appear as keys.
	 */
	public function jetpack_tiled_gallery_remaps_all_keyed_ids_in_array(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/tiled-gallery {"ids":[27478,5000]} -->
<div><img data-id="27478" class="wp-image-27478"/><img data-id="5000" class="wp-image-5000"/></div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$known_ids = [
			52837 => 27478, // Unrelated import.
			27478 => 21949, // Applies to the first gallery id.
			5000  => 6000,  // Applies to the second gallery id.
		];

		$result = $this->updater->update_jetpacktiledgallery_blocks_ids( $content, $known_ids );

		$this->assertStringContainsString( '"ids":[21949,6000]', $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpacktiledgallery_blocks_ids
	 */
	public function jetpack_tiled_gallery_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/tiled-gallery {"ids":[1001,1002]} -->
<div><img data-id="1001" class="wp-image-1001"/></div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$known_ids = [];

		$result = $this->updater->update_jetpacktiledgallery_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpacktiledgallery_blocks_ids
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

		$content_before = <<<'HTML'
<!-- wp:jetpack/tiled-gallery {"ids":[1000,1001,2000]} -->
<div><img data-id="1000" src="https://example.com/gallery1.jpg" class="wp-image-1000"/></div>
<div><img data-id="2000" src="https://not-local-hostname-alias.com/gallery3.jpg" class="wp-image-2000"/></div>
<div><img data-id="1001" src="https://example.com/gallery2.jpg" class="wp-image-1001"/></div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$content_after_expected = str_replace(
			[ '"ids":[1000,1001,2000]', 'data-id="1000"', 'wp-image-1000', 'data-id="1001"', 'wp-image-1001' ],
			[ '"ids":[99999,88888,2000]', 'data-id="99999"', 'wp-image-99999', 'data-id="88888"', 'wp-image-88888' ],
			$content_before
		);

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_jetpacktiledgallery_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );

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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackslideshow_blocks_ids
	 */
	public function jetpack_slideshow_updates_all_ids(): void {
		$content_before = $this->load_fixture( 'jetpack-slideshow' );

		// Validate fixture contains expected IDs.
		$this->assertStringContainsString( '7001', $content_before, 'Fixture jetpack-slideshow.html does not contain expected ID 7001' );
		$this->assertStringContainsString( '7002', $content_before, 'Fixture jetpack-slideshow.html does not contain expected ID 7002' );
		$this->assertStringContainsString( '7003', $content_before, 'Fixture jetpack-slideshow.html does not contain expected ID 7003' );

		$content_after_expected = str_replace(
			[ '7001', '7002', '7003' ],
			[ '9701', '9702', '9703' ],
			$content_before
		);

		$known_ids = [
			7001 => 9701,
			7002 => 9702,
			7003 => 9703,
		];

		$result = $this->updater->update_jetpackslideshow_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackslideshow_blocks_ids
	 */
	public function jetpack_slideshow_with_mapping_to_different_values_updates(): void {
		$content_before = <<<'HTML'
<!-- wp:jetpack/slideshow {"ids":[1001,1002,1003]} -->
<div class="wp-block-jetpack-slideshow">
<img data-id="1001" src="https://example.com/img1.jpg" class="wp-image-1001"/>
<img data-id="1002" src="https://example.com/img2.jpg" class="wp-image-1002"/>
<img data-id="1003" src="https://example.com/img3.jpg" class="wp-image-1003"/>
</div>
<!-- /wp:jetpack/slideshow -->
HTML;

		$content_after_expected = str_replace(
			[ '1001', '1002', '1003' ],
			[ '2001', '2002', '2003' ],
			$content_before
		);

		$known_ids = [
			1001 => 2001,
			1002 => 2002,
			1003 => 2003,
		];

		$result = $this->updater->update_jetpackslideshow_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackslideshow_blocks_ids
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

		$result = $this->updater->update_jetpackslideshow_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackslideshow_blocks_ids
	 */
	public function jetpack_slideshow_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/slideshow {"ids":[1001,1002]} -->
<div><img data-id="1001" class="wp-image-1001"/></div>
<!-- /wp:jetpack/slideshow -->
HTML;

		$known_ids = [];

		$result = $this->updater->update_jetpackslideshow_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackslideshow_blocks_ids
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

		$content_before = <<<'HTML'
<!-- wp:jetpack/slideshow {"ids":[1000,1001,2000]} -->
<div><img data-id="1000" src="https://example.com/slide1.jpg" class="wp-image-1000"/></div>
<div><img data-id="2000" src="https://not-local-hostname-alias.com/slide3.jpg" class="wp-image-2000"/></div>
<div><img data-id="1001" src="https://example.com/slide2.jpg" class="wp-image-1001"/></div>
<!-- /wp:jetpack/slideshow -->
HTML;

		$content_after_expected = str_replace(
			[ '"ids":[1000,1001,2000]', 'data-id="1000"', 'wp-image-1000', 'data-id="1001"', 'wp-image-1001' ],
			[ '"ids":[99999,88888,2000]', 'data-id="99999"', 'wp-image-99999', 'data-id="88888"', 'wp-image-88888' ],
			$content_before
		);

		$known_ids              = [];
		$local_hostname_aliases = [ 'example.com' ];
		$result                 = $this->updater->update_jetpackslideshow_blocks_ids( $content_before, $known_ids, $local_hostname_aliases );

		$this->assertEquals( $content_after_expected, $result );

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
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackimagecompare_blocks_ids
	 */
	public function jetpack_image_compare_updates_both_ids(): void {
		$content_before = $this->load_fixture( 'jetpack-image-compare' );

		// Validate fixture contains expected IDs.
		$this->assertStringContainsString( '8001', $content_before, 'Fixture jetpack-image-compare.html does not contain expected ID 8001' );
		$this->assertStringContainsString( '8002', $content_before, 'Fixture jetpack-image-compare.html does not contain expected ID 8002' );

		$content_after_expected = str_replace(
			[ '8001', '8002' ],
			[ '9801', '9802' ],
			$content_before
		);

		$known_ids = [
			8001 => 9801,
			8002 => 9802,
		];

		$result = $this->updater->update_jetpackimagecompare_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackimagecompare_blocks_ids
	 */
	public function jetpack_image_compare_with_mapping_to_different_values_updates_both_images(): void {
		$content_before = <<<'HTML'
<!-- wp:jetpack/image-compare {"imageBefore":{"id":1001,"url":"https://example.com/before.jpg"},"imageAfter":{"id":1002,"url":"https://example.com/after.jpg"}} -->
<figure class="wp-block-jetpack-image-compare">
<img id="1001" src="https://example.com/before.jpg" class="image-compare__image-before wp-image-1001"/>
<img id="1002" src="https://example.com/after.jpg" class="image-compare__image-after wp-image-1002"/>
</figure>
<!-- /wp:jetpack/image-compare -->
HTML;

		$content_after_expected = str_replace(
			[ '1001', '1002' ],
			[ '2001', '2002' ],
			$content_before
		);

		$known_ids = [
			1001 => 2001,
			1002 => 2002,
		];

		$result = $this->updater->update_jetpackimagecompare_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackimagecompare_blocks_ids
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

		$result = $this->updater->update_jetpackimagecompare_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_jetpackimagecompare_blocks_ids
	 */
	public function jetpack_image_compare_without_mapping_remains_unchanged(): void {
		$content = <<<'HTML'
<!-- wp:jetpack/image-compare {"imageBefore":{"id":1001},"imageAfter":{"id":1002}} -->
<figure><img id="1001" class="wp-image-1001"/><img id="1002" class="wp-image-1002"/></figure>
<!-- /wp:jetpack/image-compare -->
HTML;

		$known_ids = [];

		$result = $this->updater->update_jetpackimagecompare_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	// =========================================================================
	// GUTENBERG BLOCK HEADER TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_gutenberg_blocks_headers_single_id
	 */
	public function update_headers_single_id_updates_specific_block_type(): void {
		$content_before = <<<'HTML'
<!-- wp:image {"id":1111,"sizeSlug":"large"} -->
<figure>Image</figure>
<!-- /wp:image -->

<!-- wp:audio {"id":2222} -->
<figure>Audio</figure>
<!-- /wp:audio -->
HTML;

		$content_after_expected = str_replace(
			'<!-- wp:image {"id":1111,',
			'<!-- wp:image {"id":9999,',
			$content_before
		);

		$id_map = [ 1111 => 9999 ];
		$result = $this->updater->update_gutenberg_blocks_headers_single_id( 'wp:image', $id_map, $content_before );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_gutenberg_blocks_headers_multiple_ids
	 */
	public function update_headers_multiple_ids_updates_ids_array(): void {
		$content_before         = '<!-- wp:jetpack/slideshow {"ids":[1111,2222,3333],"sizeSlug":"large"} -->';
		$content_after_expected = '<!-- wp:jetpack/slideshow {"ids":[9111,9222,9333],"sizeSlug":"large"} -->';

		$id_map = [
			1111 => 9111,
			2222 => 9222,
			3333 => 9333,
		];

		$result = $this->updater->update_gutenberg_blocks_headers_multiple_ids( $id_map, $content_before );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_gutenberg_blocks_headers_multiple_ids
	 */
	public function update_headers_multiple_ids_partial_update(): void {
		$content_before         = '<!-- wp:jetpack/tiled-gallery {"ids":[1111,2222,3333]} -->';
		$content_after_expected = '<!-- wp:jetpack/tiled-gallery {"ids":[9111,2222,9333]} -->';

		// Only map some IDs.
		$id_map = [
			1111 => 9111,
			// 2222 not mapped - should stay as is.
			3333 => 9333,
		];

		$result = $this->updater->update_gutenberg_blocks_headers_multiple_ids( $id_map, $content_before );

		$this->assertEquals( $content_after_expected, $result );
	}

	// =========================================================================
	// UPDATE ALL BLOCKS TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_all_blocks_ids
	 */
	public function update_all_blocks_ids_processes_all_block_types(): void {
		$content_before = <<<'HTML'
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

		$content_after_expected = str_replace(
			[ '{"id":1111}', 'wp-image-1111', '{"id":2222}', '{"id":3333}' ],
			[ '{"id":9111}', 'wp-image-9111', '{"id":9222}', '{"id":9333}' ],
			$content_before
		);

		$known_ids = [
			1111 => 9111,
			2222 => 9222,
			3333 => 9333,
		];

		$result = $this->updater->update_all_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_all_blocks_ids
	 */
	public function update_all_blocks_returns_unchanged_when_no_blocks(): void {
		$content = '<p>Just some plain text without any blocks.</p>';

		$known_ids = [ 1111 => 9999 ];
		$result    = $this->updater->update_all_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_all_blocks_ids
	 */
	public function update_all_blocks_returns_unchanged_with_empty_content(): void {
		$content = '';

		$known_ids = [ 1111 => 9999 ];
		$result    = $this->updater->update_all_blocks_ids( $content, $known_ids );

		$this->assertEquals( $content, $result );
	}

	// =========================================================================
	// EDGE CASES AND REGRESSION TESTS
	// =========================================================================

	/**
	 * @test
	 */
	public function handles_multiple_same_ids_in_content(): void {
		$template = <<<'HTML'
<!-- wp:image {"id":%d} -->
<figure class="wp-block-image"><img src="img1.jpg" class="wp-image-%d"/></figure>
<!-- /wp:image -->

<!-- wp:gallery {"linkTo":"none"} -->
<figure class="wp-block-gallery"><!-- wp:image {"id":%d} -->
<figure class="wp-block-image"><img src="img1.jpg" class="wp-image-%d"/></figure>
<!-- /wp:image --></figure>
<!-- /wp:gallery -->
HTML;

		$content_before         = sprintf( $template, 12345, 12345, 12345, 12345 );
		$content_after_expected = sprintf( $template, 99999, 99999, 99999, 99999 );

		$known_ids = [ 12345 => 99999 ];
		$result    = $this->updater->update_image_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 */
	public function handles_large_ids(): void {
		$template               = '<!-- wp:image {"id":%d} --><figure class="wp-block-image"><img src="test.jpg" class="wp-image-%d"/></figure><!-- /wp:image -->';
		$content_before         = sprintf( $template, 999999999, 999999999 );
		$content_after_expected = sprintf( $template, 888888888, 888888888 );

		$known_ids = [ 999999999 => 888888888 ];
		$result    = $this->updater->update_image_blocks_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 */
	public function preserves_other_block_attributes(): void {
		// Note: This block has no <img> element, so the ID won't be updated (logic requires img for src attribute).
		// The test validates that other attributes remain intact when block is not updated.
		$content = '<!-- wp:image {"id":111,"sizeSlug":"large","linkDestination":"media","align":"center"} --><figure></figure><!-- /wp:image -->';

		$known_ids = [ 111 => 999 ];
		$result    = $this->updater->update_image_blocks_ids( $content, $known_ids );

		// Content should remain unchanged (no img element to update).
		$this->assertEquals( $content, $result );
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
		$this->assertEquals( $content, $result );
	}

	// =========================================================================
	// PATTERN BLOCK (wp:block) TESTS
	// =========================================================================

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_patterns_wp_block_ids
	 */
	public function pattern_wp_block_updates_ref_attribute(): void {
		$content_before         = '<!-- wp:block {"ref":28} /-->';
		$content_after_expected = '<!-- wp:block {"ref":42} /-->';

		$known_ids = [ 28 => 42 ];
		$result    = $this->updater->update_patterns_wp_block_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_patterns_wp_block_ids
	 */
	public function pattern_wp_block_handles_multiple_patterns(): void {
		$content_before = <<<'HTML'
<!-- wp:block {"ref":10} /-->

<p>Some content between patterns</p>

<!-- wp:block {"ref":20} /-->

<!-- wp:block {"ref":30} /-->
HTML;

		$content_after_expected = str_replace(
			[ '{"ref":10}', '{"ref":20}', '{"ref":30}' ],
			[ '{"ref":100}', '{"ref":200}', '{"ref":300}' ],
			$content_before
		);

		$known_ids = [
			10 => 100,
			20 => 200,
			30 => 300,
		];

		$result = $this->updater->update_patterns_wp_block_ids( $content_before, $known_ids );

		$this->assertEquals( $content_after_expected, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_patterns_wp_block_ids
	 */
	public function pattern_wp_block_preserves_content_when_no_mapping(): void {
		$content = '<!-- wp:block {"ref":99} /-->';

		$known_ids = [
			10 => 100,
			20 => 200,
		]; // No mapping for ID 99.

		$result = $this->updater->update_patterns_wp_block_ids( $content, $known_ids );

		// Should return content unchanged when no mapping exists.
		$this->assertEquals( $content, $result );
	}

	/**
	 * @test
	 * @covers \Newspack\ContentDiffMigrator\Logic\BlockUpdater::update_patterns_wp_block_ids
	 *
	 * Regression test for the ID-collision false-skip removed from BlockUpdater.
	 *
	 * See the docblock on image_block_remaps_chain_id_to_target_when_no_source_context
	 * for the full rationale. The previous defensive skip silently corrupted pattern
	 * references whenever the ref happened to be in the map's values; the corrected
	 * behavior is to follow the map lookup.
	 */
	public function pattern_wp_block_remaps_chain_ref_to_target(): void {
		$content = '<!-- wp:block {"ref":27478} /-->';

		$known_ids = [
			52837 => 27478, // Unrelated import.
			27478 => 21949, // Applies to this ref.
		];

		$result = $this->updater->update_patterns_wp_block_ids( $content, $known_ids );

		$this->assertEquals( '<!-- wp:block {"ref":21949} /-->', $result );
	}
}
