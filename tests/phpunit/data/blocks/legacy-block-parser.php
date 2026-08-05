<?php
/**
 * A block parser that implements only the long-standing one-argument `parse()`.
 *
 * Stands in for a replacement parser installed through the `block_parser_class`
 * filter that predates `WP_Block_Parser::parse_with_options()`.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

class Tests_Legacy_Block_Parser {
	/**
	 * Parses a document and returns a list of block structures.
	 *
	 * @param string $document Input document being parsed.
	 * @return array[]
	 */
	public function parse( $document ) {
		$parser = new WP_Block_Parser();

		return $parser->parse( $document );
	}
}
