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
	 * By default (no options), JSON objects and arrays both decode to plain
	 * arrays and no object marker is added. This is the historical behavior.
	 *
	 * @ticket 63325
	 *
	 * @covers ::parse
	 */
	public function test_parse_does_not_tag_object_types_by_default() {
		$parser = new WP_Block_Parser();
		$blocks = $parser->parse( '<!-- wp:test {"object":{},"array":[]} /-->' );

		$attrs = $blocks[0]['attrs'];

		$this->assertSame( array(), $attrs['object'], 'Empty object should decode to an empty array by default.' );
		$this->assertSame( array(), $attrs['array'], 'Empty array should decode to an empty array.' );
		$this->assertStringNotContainsString(
			WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER,
			wp_json_encode( $blocks ),
			'No object marker should be present on the default parse path.'
		);
	}

	/**
	 * With the `preserve_empty_object_attributes` option, values that came from
	 * a JSON object are tagged with the object marker, values from a JSON array
	 * are not, and the top-level attribute container itself is never tagged.
	 *
	 * @ticket 63325
	 *
	 * @covers ::parse
	 */
	public function test_parse_tags_object_types_when_option_is_set() {
		$marker = WP_Block_Parser::OBJECT_ATTRIBUTE_MARKER;
		$parser = new WP_Block_Parser();
		$blocks = $parser->parse(
			'<!-- wp:test {"object":{"x":1},"array":[1,2]} /-->',
			array( 'preserve_empty_object_attributes' => true )
		);

		$attrs = $blocks[0]['attrs'];

		// The top-level attribute container is intentionally not tagged.
		$this->assertArrayNotHasKey( $marker, $attrs, 'The attribute root must not be tagged.' );

		// A value from a JSON object is tagged.
		$this->assertArrayHasKey( $marker, $attrs['object'], 'Object-typed value must be tagged.' );
		$this->assertSame( 1, $attrs['object']['x'], 'Object data must be preserved alongside the marker.' );

		// A value from a JSON array is not tagged and keeps list shape.
		$this->assertArrayNotHasKey( $marker, $attrs['array'], 'Array-typed value must not be tagged.' );
		$this->assertSame( array( 1, 2 ), $attrs['array'], 'Array data must be preserved.' );
	}

	/**
	 * Invalid attribute JSON yields null attributes on the object-preserving
	 * path, matching the default json_decode() behavior.
	 *
	 * @ticket 63325
	 *
	 * @covers ::parse
	 */
	public function test_parse_with_option_returns_null_for_invalid_attribute_json() {
		$parser = new WP_Block_Parser();
		$blocks = $parser->parse(
			'<!-- wp:test {"invalid} /-->',
			array( 'preserve_empty_object_attributes' => true )
		);

		$this->assertNull( $blocks[0]['attrs'] );
	}
}
