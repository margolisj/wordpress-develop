<?php
/**
 * Tests for the insert_hooked_blocks function.
 *
 * @package WordPress
 * @subpackage Blocks
 *
 * @since 6.5.0
 *
 * @group blocks
 * @group block-hooks
 *
 * @covers ::insert_hooked_blocks
 */
class Tests_Blocks_InsertHookedBlocks extends WP_UnitTestCase {
	const ANCHOR_BLOCK_TYPE       = 'tests/anchor-block';
	const HOOKED_BLOCK_TYPE       = 'tests/hooked-block';
	const OTHER_HOOKED_BLOCK_TYPE = 'tests/other-hooked-block';

	const HOOKED_BLOCKS = array(
		self::ANCHOR_BLOCK_TYPE => array(
			'after'  => array( self::HOOKED_BLOCK_TYPE ),
			'before' => array( self::OTHER_HOOKED_BLOCK_TYPE ),
		),
	);

	/**
	 * @ticket 59572
	 * @ticket 60126
	 * @ticket 60506
	 */
	public function test_insert_hooked_blocks_returns_correct_markup() {
		$anchor_block = array(
			'blockName' => self::ANCHOR_BLOCK_TYPE,
		);

		$actual = insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );
		$this->assertSame(
			'<!-- wp:' . self::HOOKED_BLOCK_TYPE . ' /-->',
			$actual,
			"Markup for hooked block wasn't generated correctly."
		);
	}

	/**
	 * @ticket 59572
	 * @ticket 60126
	 * @ticket 60506
	 */
	public function test_insert_hooked_blocks_if_block_is_ignored() {
		$anchor_block = array(
			'blockName' => 'tests/anchor-block',
			'attrs'     => array(
				'metadata' => array(
					'ignoredHookedBlocks' => array( self::HOOKED_BLOCK_TYPE ),
				),
			),
		);

		$actual = insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );
		$this->assertSame(
			'',
			$actual,
			"No markup should've been generated for ignored hooked block."
		);
	}

	/**
	 * @ticket 59572
	 * @ticket 60126
	 * @ticket 60506
	 */
	public function test_insert_hooked_blocks_if_other_block_is_ignored() {
		$anchor_block = array(
			'blockName' => 'tests/anchor-block',
			'attrs'     => array(
				'metadata' => array(
					'ignoredHookedBlocks' => array( self::HOOKED_BLOCK_TYPE ),
				),
			),
		);

		$actual = insert_hooked_blocks( $anchor_block, 'before', self::HOOKED_BLOCKS, array() );
		$this->assertSame(
			'<!-- wp:' . self::OTHER_HOOKED_BLOCK_TYPE . ' /-->',
			$actual,
			"Markup for newly hooked block should've been generated."
		);
	}

	/**
	 * @ticket 59572
	 * @ticket 60126
	 * @ticket 60506
	 */
	public function test_insert_hooked_blocks_filter_can_set_attributes() {
		$anchor_block = array(
			'blockName'    => self::ANCHOR_BLOCK_TYPE,
			'attrs'        => array(
				'layout' => array(
					'type' => 'constrained',
				),
			),
			'innerContent' => array(),
		);

		$filter = function ( $parsed_hooked_block, $hooked_block_type, $relative_position, $parsed_anchor_block ) {
			// Is the hooked block adjacent to the anchor block?
			if ( 'before' !== $relative_position && 'after' !== $relative_position ) {
				return $parsed_hooked_block;
			}

			// Does the anchor block have a layout attribute?
			if ( isset( $parsed_anchor_block['attrs']['layout'] ) ) {
				// Copy the anchor block's layout attribute to the hooked block.
				$parsed_hooked_block['attrs']['layout'] = $parsed_anchor_block['attrs']['layout'];
			}

			return $parsed_hooked_block;
		};
		add_filter( 'hooked_block_' . self::HOOKED_BLOCK_TYPE, $filter, 10, 4 );
		$actual = insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );
		remove_filter( 'hooked_block_' . self::HOOKED_BLOCK_TYPE, $filter );

		$this->assertSame(
			'<!-- wp:' . self::HOOKED_BLOCK_TYPE . ' {"layout":{"type":"constrained"}} /-->',
			$actual,
			"Markup wasn't generated correctly for hooked block with attribute set by filter."
		);
	}

	/**
	 * @ticket 59572
	 * @ticket 60126
	 * @ticket 60506
	 */
	public function test_insert_hooked_blocks_filter_can_wrap_block() {
		$anchor_block = array(
			'blockName'    => self::ANCHOR_BLOCK_TYPE,
			'attrs'        => array(
				'layout' => array(
					'type' => 'constrained',
				),
			),
			'innerContent' => array(),
		);

		$filter = function ( $parsed_hooked_block ) {
			if ( self::HOOKED_BLOCK_TYPE !== $parsed_hooked_block['blockName'] ) {
				return $parsed_hooked_block;
			}

			// Wrap the block in a Group block.
			return array(
				'blockName'    => 'core/group',
				'attrs'        => array(),
				'innerBlocks'  => array( $parsed_hooked_block ),
				'innerContent' => array(
					'<div class="wp-block-group">',
					null,
					'</div>',
				),
			);
		};
		add_filter( 'hooked_block_' . self::HOOKED_BLOCK_TYPE, $filter, 10, 3 );
		$actual = insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );
		remove_filter( 'hooked_block_' . self::HOOKED_BLOCK_TYPE, $filter );

		$this->assertSame(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:' . self::HOOKED_BLOCK_TYPE . ' /--></div><!-- /wp:group -->',
			$actual,
			"Markup wasn't generated correctly for hooked block wrapped in Group block by filter."
		);
	}

	/**
	 * @ticket 60580
	 *
	 */
	public function test_insert_hooked_blocks_filter_can_suppress_hooked_block() {
		$anchor_block = array(
			'blockName'    => self::ANCHOR_BLOCK_TYPE,
			'attrs'        => array(
				'layout' => array(
					'type' => 'flex',
				),
			),
			'innerContent' => array(),
		);

		$filter = function ( $parsed_hooked_block, $hooked_block_type, $relative_position, $parsed_anchor_block ) {
			// Is the hooked block adjacent to the anchor block?
			if ( 'before' !== $relative_position && 'after' !== $relative_position ) {
				return $parsed_hooked_block;
			}

			if (
				isset( $parsed_anchor_block['attrs']['layout']['type'] ) &&
				'flex' === $parsed_anchor_block['attrs']['layout']['type']
			) {
				return null;
			}

			return $parsed_hooked_block;
		};
		add_filter( 'hooked_block_' . self::HOOKED_BLOCK_TYPE, $filter, 10, 4 );
		$actual = insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );
		remove_filter( 'hooked_block_' . self::HOOKED_BLOCK_TYPE, $filter );

		$this->assertSame( '', $actual, "No markup should've been generated for hooked block suppressed by filter." );
	}

	/**
	 * `metadata` arrives as an empty object on the empty object preserving parse path.
	 *
	 * @ticket 63325
	 */
	public function test_insert_hooked_blocks_with_object_shaped_metadata() {
		$anchor_block = array(
			'blockName' => self::ANCHOR_BLOCK_TYPE,
			'attrs'     => array( 'metadata' => new stdClass() ),
		);

		$actual = insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );

		$this->assertSame(
			'<!-- wp:' . self::HOOKED_BLOCK_TYPE . ' /-->',
			$actual,
			'An empty metadata object ignores nothing, so the hooked block should be inserted.'
		);
	}

	/**
	 * @ticket 63325
	 */
	public function test_insert_hooked_blocks_with_object_shaped_ignored_hooked_blocks() {
		$anchor_block = array(
			'blockName' => self::ANCHOR_BLOCK_TYPE,
			'attrs'     => array(
				'metadata' => array( 'ignoredHookedBlocks' => new stdClass() ),
			),
		);

		$actual = insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );

		$this->assertSame(
			'<!-- wp:' . self::HOOKED_BLOCK_TYPE . ' /-->',
			$actual,
			'An empty ignored blocks object ignores nothing.'
		);
	}

	/**
	 * @ticket 63325
	 */
	public function test_insert_hooked_blocks_still_honors_a_list_containing_a_non_string() {
		$anchor_block = array(
			'blockName' => self::ANCHOR_BLOCK_TYPE,
			'attrs'     => array(
				'metadata' => array(
					'ignoredHookedBlocks' => array( new stdClass(), self::HOOKED_BLOCK_TYPE ),
				),
			),
		);

		$actual = insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );

		$this->assertSame(
			'',
			$actual,
			'A named ignored block should still be honored beside a non-string entry.'
		);
	}

	/**
	 * @ticket 63325
	 */
	public function test_insert_hooked_blocks_passes_array_shaped_anchor_block_to_filters() {
		$anchor_block = array(
			'blockName' => self::ANCHOR_BLOCK_TYPE,
			'attrs'     => array( 'layout' => array( 'columns' => new stdClass() ) ),
		);

		$observed = null;
		$filter   = static function ( $parsed_hooked_block, $hooked_block_type, $relative_position, $parsed_anchor_block ) use ( &$observed ) {
			$observed = $parsed_anchor_block;
			return $parsed_hooked_block;
		};

		add_filter( 'hooked_block', $filter, 10, 4 );
		insert_hooked_blocks( $anchor_block, 'after', self::HOOKED_BLOCKS, array() );
		remove_filter( 'hooked_block', $filter );

		$this->assertNotNull( $observed, 'The hooked_block filter should have run.' );
		$this->assertIsArray(
			$observed['attrs']['layout']['columns'],
			'Filter callbacks should not see the preserved object.'
		);
		$this->assertInstanceOf(
			'stdClass',
			$anchor_block['attrs']['layout']['columns'],
			'The anchor block itself should keep the preserved object.'
		);
	}

	/**
	 * @ticket 63325
	 */
	public function test_insert_hooked_blocks_returns_empty_string_when_nothing_is_hooked() {
		$anchor_block = array(
			'blockName' => self::ANCHOR_BLOCK_TYPE,
		);

		$actual = insert_hooked_blocks( $anchor_block, 'first_child', self::HOOKED_BLOCKS, array() );

		$this->assertSame( '', $actual, 'No markup should be generated when no block types are hooked.' );
	}
}
