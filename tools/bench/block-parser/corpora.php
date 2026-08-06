<?php
/**
 * Benchmark documents for WP_Block_Parser.
 *
 * Each corpus isolates a different part of the tokenizer's cost, so a change
 * that helps one shape and hurts another shows up as such instead of averaging
 * into a single meaningless number:
 *
 *   realistic      A post as an editor would actually produce it.
 *   flat-many      Token dispatch: many sibling blocks, little else.
 *   deep-nest      Stack handling and the substr() slicing on close.
 *   attr-large     The attrs subpattern over long JSON.
 *   attr-braces    The attrs subpattern over deeply nested JSON, where every
 *                  `}` costs a lookahead in the alternation.
 *   html-heavy     Scanning for the next delimiter through markup dense in
 *                  `<`, which is what a naive first-byte search stops on.
 *   comment-heavy  Scanning past HTML comments that begin like a delimiter
 *                  but are not one.
 *   no-blocks      Pure scan cost with nothing to find.
 *   empty-objects  The shape this branch preserves, on the preserving path.
 */

function corpus_paragraph( $i ) {
	return "<!-- wp:paragraph -->\n<p>Paragraph number {$i} with some ordinary body text in it.</p>\n<!-- /wp:paragraph -->\n";
}

function corpus_realistic( $scale = 60 ) {
	$out = "<!-- wp:heading {\"level\":1} -->\n<h1>A Representative Post</h1>\n<!-- /wp:heading -->\n";

	for ( $i = 0; $i < $scale; $i++ ) {
		$out .= corpus_paragraph( $i );

		if ( 0 === $i % 4 ) {
			$out .= '<!-- wp:image {"id":' . ( 100 + $i ) . ',"sizeSlug":"large","linkDestination":"none"} -->' .
				"\n<figure class=\"wp-block-image size-large\"><img src=\"https://example.com/img-{$i}.jpg\" alt=\"\" class=\"wp-image-{$i}\"/></figure>\n<!-- /wp:image -->\n";
		}

		if ( 0 === $i % 7 ) {
			$out .= "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">\n" .
				"<!-- wp:heading {\"level\":2} -->\n<h2>Section {$i}</h2>\n<!-- /wp:heading -->\n" .
				corpus_paragraph( "{$i}-inner" ) .
				"</div>\n<!-- /wp:group -->\n";
		}

		if ( 0 === $i % 11 ) {
			$out .= "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n";
		}
	}

	return $out;
}

function corpus_flat_many( $scale = 400 ) {
	$out = '';
	for ( $i = 0; $i < $scale; $i++ ) {
		$out .= corpus_paragraph( $i );
	}
	return $out;
}

function corpus_deep_nest( $scale = 60 ) {
	$open  = '';
	$close = '';

	for ( $i = 0; $i < $scale; $i++ ) {
		$open  .= "<!-- wp:group {\"level\":{$i}} -->\n<div class=\"wp-block-group\">\n";
		$close  = "</div>\n<!-- /wp:group -->\n" . $close;
	}

	return $open . corpus_paragraph( 'deep' ) . $close;
}

function corpus_attr_large( $scale = 200 ) {
	$out = '';

	for ( $i = 0; $i < $scale; $i++ ) {
		$attrs = array(
			'id'       => $i,
			'align'    => 'wide',
			'style'    => array(
				'spacing'    => array(
					'padding' => array(
						'top'    => '2rem',
						'bottom' => '2rem',
						'left'   => '1.5rem',
						'right'  => '1.5rem',
					),
					'margin'  => array(
						'top'    => '0',
						'bottom' => '3rem',
					),
				),
				'typography' => array(
					'fontSize'       => 'clamp(1rem, 2vw, 1.5rem)',
					'lineHeight'     => '1.6',
					'letterSpacing'  => '0.02em',
					'textTransform'  => 'none',
					'fontStyle'      => 'normal',
					'fontWeight'     => '400',
				),
				'color'      => array(
					'background' => '#ffffff',
					'text'       => '#111111',
					'gradient'   => 'linear-gradient(135deg, #abc 0%, #def 100%)',
				),
			),
			'metadata' => array( 'name' => "Block {$i}" ),
		);

		$out .= '<!-- wp:cover ' . json_encode( $attrs ) . " -->\n<div class=\"wp-block-cover\">body {$i}</div>\n<!-- /wp:cover -->\n";
	}

	return $out;
}

function corpus_attr_braces( $scale = 200 ) {
	$out = '';

	for ( $i = 0; $i < $scale; $i++ ) {
		// Deliberately brace-dense: every closing run exercises the alternation
		// branch that the flat `[^}]+` branch cannot consume.
		$attrs = array(
			'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => $i ) ) ) ),
			'f' => array( 'g' => array( 'h' => array( 'i' => array( 'j' => 'x' ) ) ) ),
			'k' => array( 'l' => array( 'm' => array( 'n' => array( 'o' => true ) ) ) ),
			'p' => array( 'q' => array( 'r' => array( 's' => array( 't' => null ) ) ) ),
		);

		$out .= '<!-- wp:test/nested ' . json_encode( $attrs ) . " /-->\n";
	}

	return $out;
}

function corpus_html_heavy( $scale = 40 ) {
	$chunk = '';
	for ( $i = 0; $i < 40; $i++ ) {
		$chunk .= "<div class=\"row-{$i}\"><span class=\"cell\"><a href=\"https://example.com/{$i}\"><em>link {$i}</em></a></span></div>\n";
	}

	$out = '';
	for ( $i = 0; $i < $scale; $i++ ) {
		$out .= corpus_paragraph( $i ) . $chunk;
	}

	return $out;
}

function corpus_comment_heavy( $scale = 40 ) {
	$chunk = '';
	for ( $i = 0; $i < 40; $i++ ) {
		$chunk .= "<!-- an ordinary html comment {$i} that is not a block delimiter -->\n<p>text {$i}</p>\n";
	}

	$out = '';
	for ( $i = 0; $i < $scale; $i++ ) {
		$out .= corpus_paragraph( $i ) . $chunk;
	}

	return $out;
}

function corpus_no_blocks( $scale = 400 ) {
	$out = '';
	for ( $i = 0; $i < $scale; $i++ ) {
		$out .= "<div class=\"item-{$i}\"><p>Just markup, block {$i} nowhere in sight.</p></div>\n";
	}
	return $out;
}

function corpus_empty_objects( $scale = 200 ) {
	$out = '';

	for ( $i = 0; $i < $scale; $i++ ) {
		$attrs = '{"id":' . $i . ',"bgColor":{"desktop":{},"tablet":{},"mobile":{}},"metadata":{},"list":[]}';
		$out  .= '<!-- wp:test/responsive ' . $attrs . " /-->\n";
	}

	return $out;
}

/**
 * Returns every corpus keyed by name.
 *
 * @return array Map of name => document string.
 */
function corpus_all() {
	return array(
		'realistic'     => corpus_realistic(),
		'flat-many'     => corpus_flat_many(),
		'deep-nest'     => corpus_deep_nest(),
		'attr-large'    => corpus_attr_large(),
		'attr-braces'   => corpus_attr_braces(),
		'html-heavy'    => corpus_html_heavy(),
		'comment-heavy' => corpus_comment_heavy(),
		'no-blocks'     => corpus_no_blocks(),
		'empty-objects' => corpus_empty_objects(),
	);
}
