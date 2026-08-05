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
		$blocks     = parse_blocks( $original, array( 'preserve_object_attribute_types' => true ) );
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
	 * Only the objects that PHP arrays cannot express are re-cast, recursively.
	 *
	 * An object with at least one non-sequential key already re-encodes as a JSON
	 * object, so it is never tagged and stays a plain array -- which also keeps
	 * ordinary array access working on restored attributes. The objects that do
	 * need help are the ones whose array form is a list: the empty object, and the
	 * object whose keys happen to run 0..n-1.
	 *
	 * @ticket 63325
	 *
	 * @covers ::wp_restore_block_attribute_object_types
	 */
	public function test_restore_object_types_recasts_tagged_arrays() {
		// Tagged input comes from the parser itself; the marker sentinel is not forgeable.
		$blocks = parse_blocks(
			'<!-- wp:test {"list":[1,2],"object":{"inner":{}},"numeric":{"0":"a"}} /-->',
			array( 'preserve_object_attribute_types' => true )
		);

		$restored = wp_restore_block_attribute_object_types( $blocks[0]['attrs'] );

		// Top-level container is never tagged, so it stays an array.
		$this->assertIsArray( $restored );
		// The plain list stays a list.
		$this->assertSame( array( 1, 2 ), $restored['list'] );
		// A keyed object needs no marker, so it is still an array after restoration.
		$this->assertIsArray( $restored['object'] );
		// The empty object inside it could not survive as an array, so it was tagged.
		$this->assertInstanceOf( 'stdClass', $restored['object']['inner'] );
		$this->assertSame( array(), get_object_vars( $restored['object']['inner'] ) );
		// An object whose keys form a list would encode as an array, so it was tagged too.
		$this->assertInstanceOf( 'stdClass', $restored['numeric'] );
		$this->assertObjectNotHasProperty( WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER, $restored['numeric'] );

		// Round-trips to the expected JSON shape.
		$this->assertSame(
			'{"list":[1,2],"object":{"inner":{}},"numeric":{"0":"a"}}',
			wp_json_encode( $restored )
		);
	}

	/**
	 * Because restoration runs on every serialization, including the default
	 * parse path, a genuine attribute that merely shares the marker's key name
	 * must be left intact rather than silently dropped. The marker is matched by
	 * object identity, so no author-supplied value can collide with it: json_decode()
	 * cannot produce the parser's sentinel instance.
	 *
	 * @ticket 63325
	 *
	 * @dataProvider data_restore_object_types_ignores_author_supplied_marker_key
	 *
	 * @covers ::wp_restore_block_attribute_object_types
	 *
	 * @param mixed  $author_value Value stored under the marker key by a block author.
	 * @param string $expected     Expected JSON fragment after serialization.
	 */
	public function test_restore_object_types_ignores_author_supplied_marker_key( $author_value, $expected ) {
		$marker = WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER;

		$value    = array(
			$marker => $author_value,
			'a'     => 1,
		);
		$restored = wp_restore_block_attribute_object_types( $value );

		// Not our marker, so the array is not re-cast and the key is preserved.
		$this->assertIsArray( $restored );
		$this->assertArrayHasKey( $marker, $restored );
		$this->assertSame( $author_value, $restored[ $marker ] );

		// And it survives a full serialize on the default path.
		$this->assertStringContainsString( $expected, serialize_block_attributes( $value ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_restore_object_types_ignores_author_supplied_marker_key() {
		$marker = WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER;

		return array(
			'string value' => array( 'a genuine value', '"' . $marker . '":"a genuine value"' ),
			'integer one'  => array( 1, '"' . $marker . '":1' ),
			'boolean true' => array( true, '"' . $marker . '":true' ),
			'empty array'  => array( array(), '"' . $marker . '":[]' ),
		);
	}

	/**
	 * A block author writing the marker key into their own attribute JSON must
	 * get it back untouched on the default (render) parse path, which never tags
	 * anything. This is the end-to-end form of the identity check.
	 *
	 * @ticket 63325
	 *
	 * @covers ::parse_blocks
	 * @covers ::serialize_blocks
	 */
	public function test_author_supplied_marker_key_round_trips_on_default_path() {
		$marker  = WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER;
		$content = '<!-- wp:test {"other":{"' . $marker . '":true,"keep":"me"}} /-->';

		$this->assertSame(
			$content,
			serialize_blocks( parse_blocks( $content ) ),
			'Default-path round trip must not consume an author-supplied marker key.'
		);
	}

	/**
	 * The end-to-end form of the previous test, on the object-preserving path this
	 * time. This is the path `filter_block_content()` and the Block Hooks algorithm
	 * use, so a block author who happens to name an attribute key after the marker
	 * must not lose it on save.
	 *
	 * @ticket 63325
	 *
	 * @covers ::parse_blocks
	 * @covers ::serialize_blocks
	 */
	public function test_author_supplied_marker_key_round_trips_on_object_preserving_path() {
		$marker  = WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER;
		$content = '<!-- wp:test {"cfg":{"' . $marker . '":true,"label":"Buy now"},"empty":{}} /-->';

		$this->assertSame(
			$content,
			serialize_blocks( parse_blocks( $content, array( 'preserve_object_attribute_types' => true ) ) ),
			'An author-supplied marker key must survive the object-preserving round trip.'
		);

		$this->assertSame(
			$content,
			filter_block_content( $content, 'post' ),
			'It must survive the KSES save path, which opts in to object preservation.'
		);
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
			array( 'preserve_object_attribute_types' => true )
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
