<?php
/**
 * Tests for the filter_block_content function.
 *
 * @package WordPress
 * @subpackage Blocks
 *
 * @since 7.1.0
 *
 * @group blocks
 * @group kses
 *
 * @covers ::filter_block_content
 */
class Tests_Blocks_FilterBlockContent extends WP_UnitTestCase {
	/**
	 * Nested empty objects must survive KSES filtering rather than collapsing to `[]`.
	 *
	 * @ticket 63325
	 */
	public function test_filter_block_content_preserves_empty_object_attributes() {
		$markup = '<!-- wp:test {"nested":{"object":{},"array":[]}} /-->';

		$this->assertSame( $markup, filter_block_content( $markup ) );
	}

	/**
	 * @ticket 63325
	 */
	public function test_filter_block_content_preserves_empty_objects_in_inner_blocks() {
		$markup = '<!-- wp:outer --><!-- wp:inner {"empty":{}} /--><!-- /wp:outer -->';

		$this->assertSame( $markup, filter_block_content( $markup ) );
	}

	/**
	 * A non-empty object is still converted to an array, so KSES keeps walking into it.
	 *
	 * @ticket 63325
	 */
	public function test_filter_block_content_still_sanitizes_strings_beside_an_empty_object() {
		$markup = '<!-- wp:test {"config":{"label":"<script>alert(1)</script><strong>Safe</strong>","options":{}}} /-->';

		$actual = filter_block_content( $markup );

		$this->assertStringNotContainsString(
			'script',
			$actual,
			'A disallowed tag inside a non-empty object should be removed.'
		);
		$this->assertStringContainsString(
			'"options":{}',
			$actual,
			'An empty object beside a sanitized string should be preserved.'
		);

		// Assert the sanitized value itself; the delimiter escapes `<` and `>`.
		$attrs = parse_blocks( $actual )[0]['attrs'];

		$this->assertSame(
			'alert(1)<strong>Safe</strong>',
			$attrs['config']['label'],
			'The disallowed tag should be stripped and the allowed one kept.'
		);
	}

	/**
	 * `filter_block_content()` runs on `pre_kses`, so an attribute shape it cannot handle
	 * is a fatal error while saving a post.
	 *
	 * @ticket 63325
	 *
	 * @covers ::filter_block_core_template_part_attributes
	 */
	public function test_filter_block_content_handles_an_object_shaped_template_part_tag_name() {
		$markup = '<!-- wp:template-part {"slug":"header","tagName":{}} /-->';

		$actual = filter_block_content( $markup );

		$this->assertStringContainsString(
			'"tagName":{}',
			$actual,
			'An empty object carries no markup, so it should be left alone rather than fatal.'
		);
	}

	/**
	 * @ticket 63325
	 *
	 * @covers ::filter_block_core_template_part_attributes
	 */
	public function test_filter_block_content_still_sanitizes_a_disallowed_template_part_tag_name() {
		$markup = '<!-- wp:template-part {"slug":"header","tagName":"script"} /-->';

		$actual = filter_block_content( $markup );

		$this->assertStringContainsString(
			'"tagName":""',
			$actual,
			'A disallowed tag name should still be emptied.'
		);
	}

	/**
	 * `empty( '0' )` is true, so the guard order must leave a '0' tag name untouched.
	 *
	 * @ticket 63325
	 *
	 * @covers ::filter_block_core_template_part_attributes
	 */
	public function test_filter_block_content_leaves_a_zero_string_template_part_tag_name_alone() {
		$markup = '<!-- wp:template-part {"slug":"header","tagName":"0"} /-->';

		$actual = filter_block_content( $markup );

		$this->assertStringContainsString(
			'"tagName":"0"',
			$actual,
			'A "0" tag name should be returned verbatim, as it was before.'
		);
	}

	/**
	 * @ticket 63325
	 */
	public function test_filter_block_content_still_sanitizes_top_level_attribute_strings() {
		$markup = '<!-- wp:test {"label":"<script>alert(1)</script>"} /-->';

		$this->assertStringNotContainsString( 'script', filter_block_content( $markup ) );
	}
}
