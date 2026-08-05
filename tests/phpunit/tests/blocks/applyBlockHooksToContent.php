<?php
/**
 * Tests for the apply_block_hooks_to_content function.
 *
 * @package WordPress
 * @subpackage Blocks
 *
 * @since 6.7.0
 *
 * @group blocks
 * @group block-hooks
 *
 * @covers ::apply_block_hooks_to_content
 */
class Tests_Blocks_ApplyBlockHooksToContent extends WP_UnitTestCase {
	/**
	 * Set up.
	 *
	 * @ticket 61902.
	 * @ticket 63287.
	 */
	public static function wpSetUpBeforeClass() {
		register_block_type(
			'tests/hooked-block',
			array(
				'block_hooks' => array(
					'core/post-content' => 'after',
				),
			)
		);

		register_block_type(
			'tests/hooked-block-with-multiple-false',
			array(
				'block_hooks' => array(
					'tests/other-anchor-block' => 'after',
				),
				'supports'    => array(
					'multiple' => false,
				),
			)
		);

		register_block_type(
			'tests/dynamically-hooked-block-with-multiple-false',
			array(
				'supports' => array(
					'multiple' => false,
				),
			)
		);
	}

	/**
	 * Tear down.
	 *
	 * @ticket 61902.
	 */
	public static function wpTearDownAfterClass() {
		$registry = WP_Block_Type_Registry::get_instance();

		$registry->unregister( 'tests/hooked-block' );
		$registry->unregister( 'tests/hooked-block-with-multiple-false' );
		$registry->unregister( 'tests/dynamically-hooked-block-with-multiple-false' );
	}

	/**
	 * @ticket 61902
	 */
	public function test_apply_block_hooks_to_content_sets_theme_attribute_on_template_part_block() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:template-part /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );
		$this->assertSame(
			sprintf( '<!-- wp:template-part {"theme":"%s"} /-->', get_stylesheet() ),
			$actual
		);
	}

	/**
	 * Inserting a hooked block must not rewrite the anchor block's empty object attributes.
	 *
	 * @ticket 63325
	 */
	public function test_apply_block_hooks_to_content_preserves_empty_object_attributes() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:post-content {"layout":{"columns":{}},"list":[]} /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );

		$this->assertSame(
			'<!-- wp:post-content {"layout":{"columns":{}},"list":[]} /--><!-- wp:tests/hooked-block /-->',
			$actual
		);
	}

	/**
	 * The anchor block handed to filter callbacks keeps its historical all-array shape,
	 * while the block that gets serialized keeps the empty object.
	 *
	 * @ticket 63325
	 */
	public function test_apply_block_hooks_to_content_passes_array_shaped_anchor_block_to_filters() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:post-content {"layout":{"columns":{}}} /-->';

		$observed = null;
		$callback = static function ( $parsed_hooked_block, $hooked_block_type, $relative_position, $parsed_anchor_block ) use ( &$observed ) {
			$observed = $parsed_anchor_block;
			return $parsed_hooked_block;
		};

		add_filter( 'hooked_block', $callback, 10, 4 );
		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );
		remove_filter( 'hooked_block', $callback, 10 );

		$this->assertNotNull( $observed, 'The hooked_block filter should have run.' );
		$this->assertIsArray(
			$observed['attrs']['layout']['columns'],
			'Filter callbacks should not see the preserved object.'
		);
		$this->assertStringContainsString(
			'"columns":{}',
			$actual,
			'The serialized markup should still contain the empty object.'
		);
	}

	/**
	 * An empty `metadata` object must not fatal when read for ignored hooked blocks.
	 *
	 * @ticket 63325
	 */
	public function test_apply_block_hooks_to_content_handles_object_shaped_metadata() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:post-content {"metadata":{}} /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );

		$this->assertSame(
			'<!-- wp:post-content {"metadata":{}} /--><!-- wp:tests/hooked-block /-->',
			$actual
		);
	}

	/**
	 * Recording ignored hooked blocks must rebuild an empty `metadata` object as an array.
	 *
	 * @ticket 63325
	 */
	public function test_apply_block_hooks_to_content_records_ignored_blocks_in_object_shaped_metadata() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:post-content {"metadata":{}} /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'set_ignored_hooked_blocks_metadata' );

		$this->assertStringContainsString(
			'"ignoredHookedBlocks":["tests/hooked-block"]',
			$actual,
			'The hooked block should be recorded as ignored.'
		);
		$this->assertStringNotContainsString(
			'"metadata":{}',
			$actual,
			'Metadata holding a list is no longer an empty object.'
		);
	}

	/**
	 * An empty object inside `ignoredHookedBlocks` must not fatal in `array_unique()`.
	 *
	 * @ticket 63325
	 */
	public function test_apply_block_hooks_to_content_handles_an_object_inside_ignored_hooked_blocks() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:post-content {"metadata":{"ignoredHookedBlocks":[{}]}} /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'set_ignored_hooked_blocks_metadata' );

		$this->assertStringContainsString(
			'"ignoredHookedBlocks":["tests/hooked-block"]',
			$actual,
			'A non-string entry should be dropped rather than fatal.'
		);
	}

	/**
	 * The template part `theme` attribute injection must leave empty objects alone.
	 *
	 * @ticket 63325
	 */
	public function test_apply_block_hooks_to_content_preserves_empty_objects_beside_theme_attribute() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:template-part {"extra":{}} /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );

		$this->assertSame(
			sprintf( '<!-- wp:template-part {"extra":{},"theme":"%s"} /-->', get_stylesheet() ),
			$actual
		);
	}

	/**
	 * @ticket 61902
	 * @ticket 63287
	 */
	public function test_apply_block_hooks_to_content_inserts_hooked_block() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:post-content /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );
		$this->assertSame(
			'<!-- wp:post-content /--><!-- wp:tests/hooked-block /-->',
			$actual
		);
	}

	/**
	 * @ticket 61074
	 * @ticket 63287
	 */
	public function test_apply_block_hooks_to_content_with_context_set_to_null() {
		$content = '<!-- wp:post-content /-->';

		/*
		 * apply_block_hooks_to_content() will fall back to the global $post object (via get_post())
		 * if the $context parameter is null. However, we'd also like to ensure that the function
		 * works as expected even when get_post() returns null.
		 */
		$this->assertNull( get_post() );

		$actual = apply_block_hooks_to_content( $content, null, 'insert_hooked_blocks' );
		$this->assertSame(
			'<!-- wp:post-content /--><!-- wp:tests/hooked-block /-->',
			$actual
		);
	}

	/**
	 * @ticket 61902
	 */
	public function test_apply_block_hooks_to_content_respect_multiple_false() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:tests/hooked-block-with-multiple-false /--><!-- wp:tests/other-anchor-block /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );
		$this->assertSame(
			'<!-- wp:tests/hooked-block-with-multiple-false /--><!-- wp:tests/other-anchor-block /-->',
			$actual
		);
	}

	/**
	 * @ticket 61902
	 */
	public function test_apply_block_hooks_to_content_respect_multiple_false_after_inserting_once() {
		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:tests/other-anchor-block /--><!-- wp:tests/other-block /--><!-- wp:tests/other-anchor-block /-->';

		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );
		$this->assertSame(
			'<!-- wp:tests/other-anchor-block /--><!-- wp:tests/hooked-block-with-multiple-false /--><!-- wp:tests/other-block /--><!-- wp:tests/other-anchor-block /-->',
			$actual
		);
	}

	/**
	 * @ticket 61902
	 */
	public function test_apply_block_hooks_to_content_respect_multiple_false_with_filter() {
		$filter = function ( $hooked_block_types, $relative_position, $anchor_block_type ) {
			if ( 'tests/yet-another-anchor-block' === $anchor_block_type && 'after' === $relative_position ) {
				$hooked_block_types[] = 'tests/dynamically-hooked-block-with-multiple-false';
			}

			return $hooked_block_types;
		};

		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:tests/dynamically-hooked-block-with-multiple-false /--><!-- wp:tests/yet-another-anchor-block /-->';

		add_filter( 'hooked_block_types', $filter, 10, 3 );
		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );
		remove_filter( 'hooked_block_types', $filter, 10 );

		$this->assertSame(
			'<!-- wp:tests/dynamically-hooked-block-with-multiple-false /--><!-- wp:tests/yet-another-anchor-block /-->',
			$actual
		);
	}

	/**
	 * @ticket 61902
	 */
	public function test_apply_block_hooks_to_content_respect_multiple_false_after_inserting_once_with_filter() {
		$filter = function ( $hooked_block_types, $relative_position, $anchor_block_type ) {
			if ( 'tests/yet-another-anchor-block' === $anchor_block_type && 'after' === $relative_position ) {
				$hooked_block_types[] = 'tests/dynamically-hooked-block-with-multiple-false';
			}

			return $hooked_block_types;
		};

		$context          = new WP_Block_Template();
		$context->content = '<!-- wp:tests/yet-another-anchor-block /--><!-- wp:tests/other-block /--><!-- wp:tests/yet-another-anchor-block /-->';

		add_filter( 'hooked_block_types', $filter, 10, 3 );
		$actual = apply_block_hooks_to_content( $context->content, $context, 'insert_hooked_blocks' );
		remove_filter( 'hooked_block_types', $filter, 10 );

		$this->assertSame(
			'<!-- wp:tests/yet-another-anchor-block /--><!-- wp:tests/dynamically-hooked-block-with-multiple-false /--><!-- wp:tests/other-block /--><!-- wp:tests/yet-another-anchor-block /-->',
			$actual
		);
	}
}
