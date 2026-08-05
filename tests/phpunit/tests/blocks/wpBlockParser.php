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
	 * The default parse must keep collapsing both shapes to an array.
	 *
	 * @ticket 63325
	 *
	 * @covers ::parse
	 */
	public function test_parse_collapses_empty_objects_by_default() {
		$parser = new WP_Block_Parser();
		$blocks = $parser->parse( '<!-- wp:test {"object":{},"array":[]} /-->' );

		$this->assertSame(
			array(
				'object' => array(),
				'array'  => array(),
			),
			$blocks[0]['attrs'],
			'The default parse should decode both an empty object and an empty array as an empty array.'
		);
	}

	/**
	 * @ticket 63325
	 *
	 * @covers ::parse_with_options
	 */
	public function test_parse_with_options_preserves_nested_empty_objects() {
		$attrs = $this->parse_attributes_preserving_empty_objects( '{"object":{},"array":[]}' );

		$this->assertInstanceOf(
			'stdClass',
			$attrs['object'],
			'An empty object should be preserved as an object.'
		);
		$this->assertSame(
			array(),
			$attrs['array'],
			'An empty array should stay an empty array.'
		);
	}

	/**
	 * @ticket 63325
	 *
	 * @covers ::parse_with_options
	 */
	public function test_parse_with_options_converts_non_empty_objects_to_arrays() {
		$attrs = $this->parse_attributes_preserving_empty_objects( '{"object":{"enabled":true}}' );

		$this->assertSame(
			array( 'enabled' => true ),
			$attrs['object'],
			'A non-empty object should be converted to an array, as it has always been.'
		);
	}

	/**
	 * @ticket 63325
	 *
	 * @covers ::parse_with_options
	 */
	public function test_parse_with_options_preserves_deeply_nested_empty_objects() {
		$attrs = $this->parse_attributes_preserving_empty_objects( '{"one":{"two":{"empty":{}}}}' );

		$this->assertIsArray( $attrs['one'], 'An object with properties should be an array.' );
		$this->assertIsArray( $attrs['one']['two'], 'An object with properties should be an array.' );
		$this->assertInstanceOf(
			'stdClass',
			$attrs['one']['two']['empty'],
			'Only the innermost empty object should be preserved as an object.'
		);
	}

	/**
	 * @ticket 63325
	 *
	 * @covers ::parse_with_options
	 */
	public function test_parse_with_options_preserves_empty_objects_inside_arrays() {
		$attrs = $this->parse_attributes_preserving_empty_objects( '{"items":[{},[],{"nested":{}}]}' );

		$this->assertInstanceOf( 'stdClass', $attrs['items'][0], 'An empty object in a list should be preserved.' );
		$this->assertSame( array(), $attrs['items'][1], 'An empty array in a list should stay an array.' );
		$this->assertInstanceOf(
			'stdClass',
			$attrs['items'][2]['nested'],
			'An empty object nested inside a list entry should be preserved.'
		);
	}

	/**
	 * The block's whole attributes value is always an array, so that a block whose only
	 * attribute content is `{}` keeps serializing without any attributes.
	 *
	 * @ticket 63325
	 *
	 * @covers ::parse_with_options
	 */
	public function test_parse_with_options_converts_the_top_level_attributes_object() {
		$attrs = $this->parse_attributes_preserving_empty_objects( '{}' );

		$this->assertSame( array(), $attrs, 'The top-level attributes value should be an array.' );
	}

	/**
	 * @ticket 63325
	 *
	 * @covers ::parse_with_options
	 * @covers ::parse
	 */
	public function test_parse_with_options_returns_null_attributes_for_invalid_json() {
		$document = '<!-- wp:test {invalid} /-->';

		$default_parser = new WP_Block_Parser();
		$default_blocks = $default_parser->parse( $document );

		$attrs = $this->parse_attributes_preserving_empty_objects( '{invalid}' );

		$this->assertNull( $default_blocks[0]['attrs'], 'Invalid attribute JSON parses as null by default.' );
		$this->assertNull( $attrs, 'Invalid attribute JSON should parse as null on the preserving path too.' );
	}

	/**
	 * @ticket 63325
	 *
	 * @covers ::parse_with_options
	 */
	public function test_parse_with_options_tolerates_a_non_array_options_argument() {
		$parser = new WP_Block_Parser();
		$blocks = $parser->parse_with_options( '<!-- wp:test {"object":{}} /-->', true );

		$this->assertSame(
			array( 'object' => array() ),
			$blocks[0]['attrs'],
			'A non-array options argument should fall back to the default behavior.'
		);
	}

	/**
	 * @ticket 63325
	 *
	 * @covers ::parse_with_options
	 * @covers ::parse
	 */
	public function test_parse_with_options_does_not_leak_options_into_a_later_parse() {
		$document = '<!-- wp:test {"object":{}} /-->';
		$parser   = new WP_Block_Parser();

		$parser->parse_with_options( $document, array( 'preserve_empty_object_attributes' => true ) );
		$blocks = $parser->parse( $document );

		$this->assertSame(
			array( 'object' => array() ),
			$blocks[0]['attrs'],
			'Options should apply to a single parse, not to the parser instance.'
		);
	}

	/**
	 * Helper to parse a single block's attributes with empty object preservation enabled.
	 *
	 * @param string $attributes_json Attribute JSON for the block delimiter.
	 * @return array|null The parsed attributes.
	 */
	private function parse_attributes_preserving_empty_objects( $attributes_json ) {
		$parser = new WP_Block_Parser();
		$blocks = $parser->parse_with_options(
			'<!-- wp:test ' . $attributes_json . ' /-->',
			array( 'preserve_empty_object_attributes' => true )
		);

		return $blocks[0]['attrs'];
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
}
