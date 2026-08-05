<?php
/**
 * Tests for WP_Block_Parser.
 *
 * @package WordPress
 * @subpackage Blocks
 * @since 5.0.0
 *
 * @group blocks
 *
 * @coversDefaultClass WP_Block_Parser
 */
class Tests_Blocks_wpBlockParser extends WP_UnitTestCase {
	/**
	 * The location of the fixtures to test with.
	 *
	 * @since 5.0.0
	 * @var string
	 */
	protected static $fixtures_dir;

	/**
	 * @dataProvider data_parsing_test_filenames
	 * @ticket 45109
	 *
	 * @covers ::parse
	 */
	public function test_default_parser_output( $html_filename, $parsed_json_filename ) {
		$html_path        = self::$fixtures_dir . '/' . $html_filename;
		$parsed_json_path = self::$fixtures_dir . '/' . $parsed_json_filename;

		foreach ( array( $html_path, $parsed_json_path ) as $filename ) {
			if ( ! file_exists( $filename ) ) {
				throw new Exception( "Missing fixture file: '$filename'" );
			}
		}

		$html            = self::strip_r( file_get_contents( $html_path ) );
		$expected_parsed = json_decode( self::strip_r( file_get_contents( $parsed_json_path ) ), true );

		$parser = new WP_Block_Parser();
		$result = json_decode( json_encode( $parser->parse( $html ) ), true );

		$this->assertSame(
			$expected_parsed,
			$result,
			"File '$parsed_json_filename' does not match expected value"
		);
	}

	/**
	 * @ticket 45109
	 */
	public function data_parsing_test_filenames() {
		self::$fixtures_dir = DIR_TESTDATA . '/blocks/fixtures';

		$fixture_filenames = array_merge(
			glob( self::$fixtures_dir . '/*.json' ),
			glob( self::$fixtures_dir . '/*.html' )
		);

		$fixture_filenames = array_values(
			array_unique(
				array_map(
					array( $this, 'clean_fixture_filename' ),
					$fixture_filenames
				)
			)
		);

		return array_map(
			array( $this, 'pass_parser_fixture_filenames' ),
			$fixture_filenames
		);
	}

	/**
	 * Helper function to remove relative paths and extension from a filename, leaving just the fixture name.
	 *
	 * @since 5.0.0
	 *
	 * @param string $filename The filename to clean.
	 * @return string The cleaned fixture name.
	 */
	protected function clean_fixture_filename( $filename ) {
		$filename = wp_basename( $filename );
		$filename = preg_replace( '/\..+$/', '', $filename );
		return $filename;
	}

	/**
	 * Helper function to return the filenames needed to test the parser output.
	 *
	 * @since 5.0.0
	 *
	 * @param string $filename The cleaned fixture name.
	 * @return array The input and expected output filenames for that fixture.
	 */
	protected function pass_parser_fixture_filenames( $filename ) {
		return array(
			"$filename.html",
			"$filename.parsed.json",
		);
	}

	/**
	 * Helper function to remove '\r' characters from a string.
	 *
	 * @since 5.0.0
	 *
	 * @param string $input The string to remove '\r' from.
	 * @return string The input string, with '\r' characters removed.
	 */
	protected function strip_r( $input ) {
		return str_replace( "\r", '', $input );
	}

	/**
	 * Parses markup with empty-object preservation and returns the first block's attributes.
	 *
	 * @param string $markup Block markup with a single top-level block.
	 * @return array|null The parsed attributes.
	 */
	private function parse_attrs_preserving( $markup ) {
		$blocks = _wp_parse_blocks_preserving_empty_object_attributes( $markup );

		return $blocks[0]['attrs'];
	}

	/**
	 * The default parse path must be untouched: an empty object and an empty array
	 * both decode to an empty PHP array, as they always have.
	 *
	 * @ticket 63325
	 *
	 * @covers ::parse_blocks
	 */
	public function test_default_parse_collapses_empty_objects_to_arrays() {
		$blocks = parse_blocks( '<!-- wp:test {"object":{},"array":[]} /-->' );
		$attrs  = $blocks[0]['attrs'];

		$this->assertSame( array(), $attrs['object'] );
		$this->assertSame( array(), $attrs['array'] );
	}

	/**
	 * With preservation on, only the empty object becomes an object.
	 *
	 * @ticket 63325
	 *
	 * @covers ::_wp_parse_blocks_preserving_empty_object_attributes
	 */
	public function test_preserving_parse_keeps_only_empty_objects_as_objects() {
		$attrs = $this->parse_attrs_preserving( '<!-- wp:test {"object":{},"array":[]} /-->' );

		$this->assertInstanceOf( 'stdClass', $attrs['object'] );
		$this->assertSame( array(), get_object_vars( $attrs['object'] ) );
		$this->assertSame( array(), $attrs['array'] );
	}

	/**
	 * A populated object still becomes an array, so code that walks attributes with
	 * array access and array functions keeps working.
	 *
	 * @ticket 63325
	 *
	 * @covers ::_wp_parse_blocks_preserving_empty_object_attributes
	 */
	public function test_preserving_parse_converts_non_empty_objects_to_arrays() {
		$attrs = $this->parse_attrs_preserving( '<!-- wp:test {"object":{"enabled":true}} /-->' );

		$this->assertSame( array( 'enabled' => true ), $attrs['object'] );
	}

	/**
	 * Only the innermost empty object is an object; its ancestors stay arrays.
	 *
	 * @ticket 63325
	 *
	 * @covers ::_wp_parse_blocks_preserving_empty_object_attributes
	 */
	public function test_preserving_parse_handles_deep_nesting() {
		$attrs = $this->parse_attrs_preserving( '<!-- wp:test {"one":{"two":{"empty":{}}}} /-->' );

		$this->assertIsArray( $attrs['one'] );
		$this->assertIsArray( $attrs['one']['two'] );
		$this->assertInstanceOf( 'stdClass', $attrs['one']['two']['empty'] );
	}

	/**
	 * Empty objects are preserved inside JSON arrays too.
	 *
	 * @ticket 63325
	 *
	 * @covers ::_wp_parse_blocks_preserving_empty_object_attributes
	 */
	public function test_preserving_parse_handles_empty_objects_inside_arrays() {
		$attrs = $this->parse_attrs_preserving( '<!-- wp:test {"items":[{},[],{"nested":{}}]} /-->' );

		$this->assertInstanceOf( 'stdClass', $attrs['items'][0] );
		$this->assertSame( array(), $attrs['items'][1] );
		$this->assertInstanceOf( 'stdClass', $attrs['items'][2]['nested'] );
	}

	/**
	 * The top-level attribute container always becomes an array, so that empty
	 * attributes keep being dropped on serialization.
	 *
	 * @ticket 63325
	 *
	 * @covers ::_wp_parse_blocks_preserving_empty_object_attributes
	 */
	public function test_preserving_parse_converts_the_attribute_root_to_an_array() {
		$this->assertSame( array(), $this->parse_attrs_preserving( '<!-- wp:test {} /-->' ) );
	}

	/**
	 * Malformed attribute JSON behaves identically on both paths.
	 *
	 * @ticket 63325
	 *
	 * @dataProvider data_invalid_attribute_json
	 *
	 * @covers ::_wp_parse_blocks_preserving_empty_object_attributes
	 *
	 * @param string $json Malformed attribute JSON.
	 */
	public function test_invalid_json_yields_null_attributes_on_both_paths( $json ) {
		$markup = "<!-- wp:test $json /-->";

		$this->assertNull( parse_blocks( $markup )[0]['attrs'] );
		$this->assertNull( $this->parse_attrs_preserving( $markup ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_invalid_attribute_json() {
		return array(
			'missing value'  => array( '{"broken":}' ),
			'unquoted keys'  => array( '{not json}' ),
			'trailing comma' => array( '{"a":1,}' ),
		);
	}

	/**
	 * A replacement parser that only implements parse() must keep working.
	 *
	 * This is the reason preservation is requested through parse_with_options()
	 * rather than by adding a parameter to parse(): PHP rejects a subclass that
	 * declares fewer parameters than its parent, so widening parse() itself would
	 * fatal for every plugin that overrides it.
	 *
	 * @ticket 63325
	 *
	 * @covers ::_wp_parse_blocks_preserving_empty_object_attributes
	 */
	public function test_replacement_parser_without_parse_with_options_falls_back() {
		$filter = static function () {
			return 'Tests_Blocks_Legacy_Parser';
		};
		add_filter( 'block_parser_class', $filter );

		try {
			$attrs = $this->parse_attrs_preserving( '<!-- wp:test {"object":{}} /-->' );
		} finally {
			remove_filter( 'block_parser_class', $filter );
		}

		// The legacy parser knows nothing about preservation, so the historical shape comes back.
		$this->assertSame( array(), $attrs['object'] );
	}

	/**
	 * A subclass may still override parse() with the one-argument signature.
	 *
	 * Widening parse() itself would make every such subclass a fatal error, and
	 * delegating parse_with_options() to parse() keeps the override in effect even
	 * when core requests options.
	 *
	 * @ticket 63325
	 *
	 * @covers ::_wp_parse_blocks_preserving_empty_object_attributes
	 */
	public function test_subclass_overriding_parse_is_still_used() {
		$filter = static function () {
			return 'Tests_Blocks_Subclassed_Parser';
		};
		add_filter( 'block_parser_class', $filter );

		try {
			$blocks = _wp_parse_blocks_preserving_empty_object_attributes( '<!-- wp:test {"object":{}} /-->' );
		} finally {
			remove_filter( 'block_parser_class', $filter );
		}

		$this->assertTrue(
			Tests_Blocks_Subclassed_Parser::$parse_was_called,
			'The subclass override of parse() should still run.'
		);
		// Preservation still applies, because the option is read during the inherited parse.
		$this->assertInstanceOf( 'stdClass', $blocks[0]['attrs']['object'] );
	}
}

/**
 * A replacement parser predating parse_with_options(), implementing only parse().
 */
class Tests_Blocks_Legacy_Parser {
	/**
	 * Parses a document into blocks.
	 *
	 * @param string $document Input document being parsed.
	 * @return array[]
	 */
	public function parse( $document ) {
		$parser = new WP_Block_Parser();

		return $parser->parse( $document );
	}
}

/**
 * A parser subclass that overrides parse() with the historical signature.
 */
class Tests_Blocks_Subclassed_Parser extends WP_Block_Parser {
	/**
	 * Whether the override ran.
	 *
	 * @var bool
	 */
	public static $parse_was_called = false;

	/**
	 * Parses a document into blocks.
	 *
	 * @param string $document Input document being parsed.
	 * @return array[]
	 */
	public function parse( $document ) {
		self::$parse_was_called = true;

		return parent::parse( $document );
	}
}
