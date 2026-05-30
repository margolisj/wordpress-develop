<?php
/**
 * Tests for block serialization functions.
 *
 * @package WordPress
 * @subpackage Blocks
 *
 * @since 5.3.3
 *
 * @group blocks
 */
class Tests_Blocks_Serialize extends WP_UnitTestCase {
	/**
	 * Empty `{}` object attributes (including nested ones) must survive a
	 * parse -> serialize round-trip when the parser is asked to preserve them,
	 * while empty `[]` array attributes must stay arrays.
	 *
	 * @ticket 63325
	 *
	 * @dataProvider data_serialize_identity_with_preserved_object_types
	 *
	 * @param string $original Original block markup.
	 */
	public function test_serialize_identity_with_preserved_object_types( $original ) {
		$blocks     = parse_blocks( $original, array( 'preserve_empty_object_attributes' => true ) );
		$serialized = serialize_blocks( $blocks );
		$this->assertSame( $original, $serialized );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_serialize_identity_with_preserved_object_types() {
		return array(
			// Empty object attribute value stays an object.
			array( '<!-- wp:test {"object":{}} /-->' ),
			// Empty array attribute value stays an array.
			array( '<!-- wp:test {"array":[]} /-->' ),
			// Empty object nested inside an object (the reported case shape).
			array( '<!-- wp:test {"nested":{"a":{},"b":[]}} /-->' ),
			// The exact attributes from the bug report.
			array( '<!-- wp:test {"blockId":"block-RX3iloq4aK","bgColor":{"desktop":{"type":"solid","solidValue":"#2c80af","gradientValue":""},"tablet":{},"mobile":{},"hover":{}}} /-->' ),
			// Array containing empty objects.
			array( '<!-- wp:test {"list":[{},{}]} /-->' ),
			// Object with numeric string keys stays an object.
			array( '<!-- wp:test {"map":{"0":"x","1":"y"}} /-->' ),
		);
	}

	/**
	 * Without the opt-in option, the historical behavior must be preserved:
	 * an empty object attribute collapses to an empty array on serialization.
	 * This guards against the change leaking into the default parse path.
	 *
	 * @ticket 63325
	 *
	 * @dataProvider data_serialize_collapses_empty_object_without_option
	 *
	 * @param string $original   Original block markup.
	 * @param string $serialized Expected markup after a default parse -> serialize.
	 */
	public function test_serialize_collapses_empty_object_without_option( $original, $serialized ) {
		$blocks = parse_blocks( $original );
		$this->assertSame( $serialized, serialize_blocks( $blocks ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_serialize_collapses_empty_object_without_option() {
		return array(
			'empty object collapses to array' => array(
				'<!-- wp:test {"object":{}} /-->',
				'<!-- wp:test {"object":[]} /-->',
			),
			'nested empty object collapses'   => array(
				'<!-- wp:test {"nested":{"a":{},"b":[]}} /-->',
				'<!-- wp:test {"nested":{"a":[],"b":[]}} /-->',
			),
			'empty array is unaffected'       => array(
				'<!-- wp:test {"array":[]} /-->',
				'<!-- wp:test {"array":[]} /-->',
			),
		);
	}

	/**
	 * On attributes that were not tagged by object-preserving parsing,
	 * wp_restore_block_attribute_object_types() must be a structural no-op:
	 * arrays stay arrays, scalars pass through, and key order is preserved.
	 *
	 * @ticket 63325
	 *
	 * @covers ::wp_restore_block_attribute_object_types
	 */
	public function test_restore_object_types_is_noop_without_marker() {
		$this->assertSame( 'scalar', wp_restore_block_attribute_object_types( 'scalar' ) );
		$this->assertSame( 42, wp_restore_block_attribute_object_types( 42 ) );
		$this->assertNull( wp_restore_block_attribute_object_types( null ) );

		$list = array( 'a', 'b', 'c' );
		$this->assertSame( $list, wp_restore_block_attribute_object_types( $list ) );

		$assoc = array(
			'b' => 1,
			'a' => array( 'nested' => array( 'x', 'y' ) ),
		);
		$this->assertSame( $assoc, wp_restore_block_attribute_object_types( $assoc ) );
	}

	/**
	 * A tagged array must be re-cast to an object (with the marker stripped),
	 * recursively, while sibling untagged arrays stay arrays.
	 *
	 * @ticket 63325
	 *
	 * @covers ::wp_restore_block_attribute_object_types
	 */
	public function test_restore_object_types_recasts_tagged_arrays() {
		$marker = WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER;

		$tagged = array(
			'list'   => array( 1, 2 ),
			'object' => array(
				'inner' => array( $marker => true ),
				$marker => true,
			),
		);

		$restored = wp_restore_block_attribute_object_types( $tagged );

		// Top-level container had no marker, so it stays an array.
		$this->assertIsArray( $restored );
		// The plain list stays a list.
		$this->assertSame( array( 1, 2 ), $restored['list'] );
		// The tagged value becomes an object with the marker removed.
		$this->assertInstanceOf( 'stdClass', $restored['object'] );
		$this->assertObjectNotHasProperty( $marker, $restored['object'] );
		// Nested tagged value is restored recursively to an (empty) object.
		$this->assertInstanceOf( 'stdClass', $restored['object']->inner );
		$this->assertSame( array(), get_object_vars( $restored['object']->inner ) );

		// Round-trips to the expected JSON shape.
		$this->assertSame(
			'{"list":[1,2],"object":{"inner":{}}}',
			wp_json_encode( $restored )
		);
	}

	/**
	 * Because restoration runs on every serialization, a genuine attribute that
	 * merely shares the marker's key name (with any value other than the exact
	 * boolean the parser sets) must be left intact rather than silently dropped.
	 *
	 * @ticket 63325
	 *
	 * @covers ::wp_restore_block_attribute_object_types
	 */
	public function test_restore_object_types_ignores_marker_key_with_non_true_value() {
		$marker = WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER;

		$value    = array(
			$marker => 'a genuine value',
			'a'     => 1,
		);
		$restored = wp_restore_block_attribute_object_types( $value );

		// Not our marker, so the array is not re-cast and the key is preserved.
		$this->assertIsArray( $restored );
		$this->assertSame( 'a genuine value', $restored[ $marker ] );

		// And it survives a full serialize on the default path.
		$serialized = serialize_block_attributes( $value );
		$this->assertStringContainsString( '"' . $marker . '":"a genuine value"', $serialized );
	}

	/**
	 * The attribute root is intentionally never tagged, so a block whose entire
	 * attribute set is an empty object is still serialized without attributes,
	 * even when object preservation is enabled. This locks in that scoping (the
	 * fix targets nested empty objects, not a top-level empty-object attr set).
	 *
	 * @ticket 63325
	 */
	public function test_serialize_drops_top_level_empty_object_even_with_option() {
		$blocks = parse_blocks(
			'<!-- wp:test {} /-->',
			array( 'preserve_empty_object_attributes' => true )
		);

		$this->assertSame( '<!-- wp:test /-->', serialize_blocks( $blocks ) );
	}

	/**
	 * The KSES block-filtering path (filter_block_content) opts in to
	 * object-preserving parsing, so empty object attributes must survive it,
	 * and the internal marker must never leak into the sanitized output.
	 *
	 * @ticket 63325
	 *
	 * @covers ::filter_block_content
	 */
	public function test_filter_block_content_preserves_empty_object_attributes() {
		$content = '<!-- wp:test {"nested":{"a":{},"b":[]}} /-->';

		$filtered = filter_block_content( $content, 'post' );

		$this->assertSame( $content, $filtered, 'Empty object attribute should survive KSES block filtering.' );
		$this->assertStringNotContainsString(
			WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER,
			$filtered,
			'The internal object marker must never leak into filtered output.'
		);
	}

	/**
	 * Ensure there are no issues with special character encoding.
	 *
	 * @ticket 63917
	 */
	public function test_attribute_encoding() {
		$block = array(
			'blockName'    => 'test',
			'attrs'        => array(
				'lt'         => '<',
				'gt'         => '>',
				'amp'        => '&',
				'bs'         => '\\',
				'quot'       => '"',
				'bs-bs-quot' => '\\\\"',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$expected = '<!-- wp:test {"lt":"\\u003c","gt":"\\u003e","amp":"\\u0026","bs":"\\u005c","quot":"\\u0022","bs-bs-quot":"\\u005c\\u005c\\u0022"} /-->';
		$this->assertSame( $expected, serialize_block( $block ) );
	}

	/**
	 * @dataProvider data_serialize_identity_from_parsed
	 *
	 * @param string $original Original block markup.
	 *
	 * @ticket 63917
	 */
	public function test_serialize_identity_from_parsed( $original ) {
		$blocks = parse_blocks( $original );

		$actual = serialize_blocks( $blocks );

		$this->assertSame( $original, $actual );
	}

	public static function data_serialize_identity_from_parsed(): array {
		return array(
			'Void block'                                  =>
				array( '<!-- wp:void /-->' ),

			'Freeform content ($block_name = null)'       =>
				array( 'Example.' ),

			'Block with content'                          =>
				array( '<!-- wp:content -->Example.<!-- /wp:content -->' ),

			'Block with attributes'                       =>
				array( '<!-- wp:attributes {"key":"value"} /-->' ),

			'Block with inner blocks'                     =>
				array( "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->" ),

			'Block with attribute values that may conflict with HTML comment' =>
				array( '<!-- wp:attributes {"key":"\\u002d\\u002d\\u003c\\u003e\\u0026\\u0022"} /-->' ),

			'Block with attribute values that should not be escaped' =>
				array( '<!-- wp:attributes {"key":"€1.00 / 3 for €2.00"} /-->' ),

			'Backslashes in attributes, Gutenberg #16508' =>
				array( '<!-- wp:attributes {"bs":"\\u005c","bsQuote":"\\u005c\\u0022","bsQuoteBs":"\\u005c\\u0022\\u005c"} /-->' ),

			'Tricky backslashes'                          =>
				array( '<!-- wp:attributes {"bsbsQbsbsbsQ":"\\u005c\\u005c\\u0022\\u005c\\u005c\\u005c\\u005c\\u0022"} /-->' ),
		);
	}

	/**
	 * The serialization was adjusted to use unicode escapes sequences for escaped `\` and `"`
	 * characters inside JSON strings.
	 *
	 * Ensure that the previous escape form can be parsed compatibly and serialized back to
	 * the new form.
	 *
	 * @see https://github.com/WordPress/wordpress-develop/pull/9558
	 * @see https://github.com/WordPress/gutenberg/pull/71291
	 *
	 * @ticket 63917
	 *
	 * @dataProvider data_serialize_compatible_forms
	 *
	 * @param string $before Previous serialization form.
	 * @param string $after  New serialization form.
	 */
	public function test_older_serialization_is_compatible( string $before, string $after ) {
		$this->assertNotSame( $before, $after, 'The same serialization should not be provided for before and after.' );
		$blocks = parse_blocks( $before );
		$actual = serialize_blocks( $blocks );
		$this->assertSame( $after, $actual );
	}

	public static function data_serialize_compatible_forms(): array {
		return array(
			'Special characters' => array(
				'<!-- wp:attributes {"lt":"\\u003c","gt":"\\u003e","amp":"\\u0026","bs":"\\\\","quot":"\\u0022"} /-->',
				'<!-- wp:attributes {"lt":"\\u003c","gt":"\\u003e","amp":"\\u0026","bs":"\\u005c","quot":"\\u0022"} /-->',
			),

			'Backslashes'        => array(
				'<!-- wp:attributes {"bs":"\\\\","bsQuote":"\\\\\\u0022","bsQuoteBs":"\\\\\\u0022\\\\"} /-->',
				'<!-- wp:attributes {"bs":"\\u005c","bsQuote":"\\u005c\\u0022","bsQuoteBs":"\\u005c\\u0022\\u005c"} /-->',
			),
		);
	}

	public function test_serialized_block_name() {
		$this->assertNull( strip_core_block_namespace( null ) );
		$this->assertSame( 'example', strip_core_block_namespace( 'example' ) );
		$this->assertSame( 'example', strip_core_block_namespace( 'core/example' ) );
		$this->assertSame( 'plugin/example', strip_core_block_namespace( 'plugin/example' ) );
	}

	/**
	 * @ticket 59327
	 * @ticket 59412
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_pre_callback_modifies_current_block() {
		$markup = "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->";
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks( $blocks, array( __CLASS__, 'add_attribute_to_inner_block' ) );

		$this->assertSame(
			"<!-- wp:outer --><!-- wp:inner {\"key\":\"value\",\"myattr\":\"myvalue\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->",
			$actual
		);
	}

	/**
	 * @ticket 59669
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_post_callback_modifies_current_block() {
		$markup = "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->";
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks( $blocks, null, array( __CLASS__, 'add_attribute_to_inner_block' ) );

		$this->assertSame(
			"<!-- wp:outer --><!-- wp:inner {\"key\":\"value\",\"myattr\":\"myvalue\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->",
			$actual
		);
	}

	public static function add_attribute_to_inner_block( &$block ) {
		if ( 'core/inner' === $block['blockName'] ) {
			$block['attrs']['myattr'] = 'myvalue';
		}
	}

	/**
	 * @ticket 59313
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_pre_callback_prepends_to_inner_block() {
		$markup = "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->";
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks( $blocks, array( __CLASS__, 'insert_next_to_inner_block_callback' ) );

		$this->assertSame(
			"<!-- wp:outer --><!-- wp:tests/inserted-block /--><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->",
			$actual
		);
	}

	/**
	 * @ticket 59313
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_post_callback_appends_to_inner_block() {
		$markup = "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->";
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks( $blocks, null, array( __CLASS__, 'insert_next_to_inner_block_callback' ) );

		$this->assertSame(
			"<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner --><!-- wp:tests/inserted-block /-->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->",
			$actual
		);
	}

	public static function insert_next_to_inner_block_callback( $block ) {
		if ( 'core/inner' !== $block['blockName'] ) {
			return '';
		}

		return get_comment_delimited_block_content( 'tests/inserted-block', array(), '' );
	}

	/**
	 * @ticket 59313
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_pre_callback_prepends_to_child_blocks() {
		$markup = "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->";
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks( $blocks, array( __CLASS__, 'insert_next_to_child_blocks_callback' ) );

		$this->assertSame(
			"<!-- wp:outer --><!-- wp:tests/inserted-block {\"parent\":\"core/outer\"} /--><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:tests/inserted-block {\"parent\":\"core/outer\"} /--><!-- wp:void /--><!-- /wp:outer -->",
			$actual
		);
	}

	/**
	 * @ticket 59313
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_post_callback_appends_to_child_blocks() {
		$markup = "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->";
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks( $blocks, null, array( __CLASS__, 'insert_next_to_child_blocks_callback' ) );

		$this->assertSame(
			"<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner --><!-- wp:tests/inserted-block {\"parent\":\"core/outer\"} /-->\n\nExample.\n\n<!-- wp:void /--><!-- wp:tests/inserted-block {\"parent\":\"core/outer\"} /--><!-- /wp:outer -->",
			$actual
		);
	}

	public static function insert_next_to_child_blocks_callback( $block, $parent_block ) {
		if ( ! isset( $parent_block ) ) {
			return '';
		}

		return get_comment_delimited_block_content(
			'tests/inserted-block',
			array(
				'parent' => $parent_block['blockName'],
			),
			''
		);
	}

	/**
	 * @ticket 59313
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_pre_callback_prepends_if_prev_block() {
		$markup = "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->";
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks( $blocks, array( __CLASS__, 'insert_next_to_if_prev_or_next_block_callback' ) );

		$this->assertSame(
			"<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:tests/inserted-block {\"prev_or_next\":\"core/inner\"} /--><!-- wp:void /--><!-- /wp:outer -->",
			$actual
		);
	}

	/**
	 * @ticket 59313
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_post_callback_appends_if_prev_block() {
		$markup = "<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner -->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->";
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks( $blocks, null, array( __CLASS__, 'insert_next_to_if_prev_or_next_block_callback' ) );

		$this->assertSame(
			"<!-- wp:outer --><!-- wp:inner {\"key\":\"value\"} -->Example.<!-- /wp:inner --><!-- wp:tests/inserted-block {\"prev_or_next\":\"core/void\"} /-->\n\nExample.\n\n<!-- wp:void /--><!-- /wp:outer -->",
			$actual
		);
	}

	public static function insert_next_to_if_prev_or_next_block_callback( $block, $parent_block, $prev_or_next ) {
		if ( ! isset( $prev_or_next ) ) {
			return '';
		}

		return get_comment_delimited_block_content(
			'tests/inserted-block',
			array(
				'prev_or_next' => $prev_or_next['blockName'],
			),
			''
		);
	}

	/**
	 * @ticket 59327
	 * @ticket 59412
	 *
	 * @covers ::traverse_and_serialize_blocks
	 *
	 * @dataProvider data_serialize_identity_from_parsed
	 *
	 * @param string $original Original block markup.
	 */
	public function test_traverse_and_serialize_identity_from_parsed( $original ) {
		$blocks = parse_blocks( $original );

		$actual = traverse_and_serialize_blocks( $blocks );

		$this->assertSame( $original, $actual );
	}

	/**
	 * @ticket 59313
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_do_not_insert_in_void_block() {
		$markup = '<!-- wp:void /-->';
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks(
			$blocks,
			array( __CLASS__, 'insert_next_to_child_blocks_callback' ),
			array( __CLASS__, 'insert_next_to_child_blocks_callback' )
		);

		$this->assertSame( $markup, $actual );
	}

	/**
	 * @ticket 59313
	 *
	 * @covers ::traverse_and_serialize_blocks
	 */
	public function test_traverse_and_serialize_blocks_do_not_insert_in_empty_parent_block() {
		$markup = '<!-- wp:outer --><div class="wp-block-outer"></div><!-- /wp:outer -->';
		$blocks = parse_blocks( $markup );

		$actual = traverse_and_serialize_blocks(
			$blocks,
			array( __CLASS__, 'insert_next_to_child_blocks_callback' ),
			array( __CLASS__, 'insert_next_to_child_blocks_callback' )
		);

		$this->assertSame( $markup, $actual );
	}
}
