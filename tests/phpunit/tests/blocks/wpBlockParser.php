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
	 * A block name carries its namespace, and one without a namespace belongs to core.
	 *
	 * The fixtures cover `core/` both spelled out and left implicit, but nothing
	 * covers a namespace belonging to anyone else.
	 *
	 * @ticket 63325
	 *
	 * @dataProvider data_block_names
	 *
	 * @covers ::parse
	 *
	 * @param string $document Input document.
	 * @param string $expected Expected parsed block name.
	 */
	public function test_parse_resolves_block_names( $document, $expected ) {
		$parser = new WP_Block_Parser();
		$blocks = $parser->parse( $document );

		$this->assertSame( $expected, $blocks[0]['blockName'] );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_block_names() {
		return array(
			'no namespace'                => array( '<!-- wp:paragraph /-->', 'core/paragraph' ),
			'core spelled out'            => array( '<!-- wp:core/paragraph /-->', 'core/paragraph' ),
			'third party namespace'       => array( '<!-- wp:my-plugin/my-block /-->', 'my-plugin/my-block' ),
			'namespace with digits'       => array( '<!-- wp:plugin2/block3 /-->', 'plugin2/block3' ),
			'underscores'                 => array( '<!-- wp:my_plugin/my_block /-->', 'my_plugin/my_block' ),
			'namespaced with attributes'  => array( '<!-- wp:my-plugin/my-block {"a":1} /-->', 'my-plugin/my-block' ),
			'namespaced opener'           => array( '<!-- wp:my-plugin/my-block -->x<!-- /wp:my-plugin/my-block -->', 'my-plugin/my-block' ),
			'unnamespaced opener'         => array( '<!-- wp:paragraph -->x<!-- /wp:paragraph -->', 'core/paragraph' ),
			'single character name'       => array( '<!-- wp:a /-->', 'core/a' ),
			'single character namespaced' => array( '<!-- wp:a/b /-->', 'a/b' ),
		);
	}

	/**
	 * A name the delimiter grammar does not allow is not a block at all.
	 *
	 * @ticket 63325
	 *
	 * @dataProvider data_invalid_block_names
	 *
	 * @covers ::parse
	 *
	 * @param string $document Input document.
	 */
	public function test_parse_rejects_invalid_block_names( $document ) {
		$parser = new WP_Block_Parser();
		$blocks = $parser->parse( $document );

		$this->assertCount( 1, $blocks, 'The document should parse as a single freeform block.' );
		$this->assertNull( $blocks[0]['blockName'], 'Invalid delimiters are freeform content, not blocks.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_invalid_block_names() {
		return array(
			'uppercase'           => array( '<!-- wp:Paragraph /-->' ),
			'leading digit'       => array( '<!-- wp:9lives /-->' ),
			'empty name'          => array( '<!-- wp: /-->' ),
			'leading hyphen'      => array( '<!-- wp:-block /-->' ),
			'three segments'      => array( '<!-- wp:a/b/c /-->' ),
			'trailing slash'      => array( '<!-- wp:block/ /-->' ),
			'namespace only'      => array( '<!-- wp:/block /-->' ),
			'no space before -->' => array( '<!-- wp:paragraph-->' ),
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
}
