<?php
/**
 * Shootout for tokenizer pattern and capture-unpacking variants.
 *
 * Each variant tokenizes a document into the same tuple shape, so equivalence
 * can be checked before any timing is believed. Only the delimiter matching and
 * the reading of its capture groups differ -- no json_decode, no block
 * construction -- which is exactly the surface a "regex tokenizer" change
 * touches.
 *
 * Usage: php patterns.php [--rounds=N] [--corpus=A,B]
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/corpora.php';

$rounds = 40;
$only   = null;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--rounds=(\d+)$/', $arg, $m ) ) {
		$rounds = (int) $m[1];
	} elseif ( preg_match( '/^--corpus=(.+)$/', $arg, $m ) ) {
		$only = explode( ',', $m[1] );
	}
}

/*
 * The attribute subpattern, shared where a variant does not change it.
 *
 * `[^}]+` gobbles runs of non-brace bytes, `}+(?=})` swallows a run of closing
 * braces in one step, and the per-character branch with the negative lookahead
 * is reached only for a single `}` that might be the terminator.
 */
const ATTRS      = '(\{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?';
const ATTRS_POSS = '(\{(?:(?:[^}]++|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?';

$patterns = array(
	// The pattern the parser ships. Everything else is measured against it.
	'current'   => '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+' . ATTRS . '(\/)?-->/s',

	// The five-named-group pattern the parser used before, kept for comparison.
	'legacy'    => '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s',

	/*
	 * One capture for the whole block name, written so the common
	 * non-namespaced case does not scan the name twice.
	 *
	 * `(?<ns>[a-z][a-z0-9_-]*\/)?(?<name>[a-z][a-z0-9_-]*)` makes the engine
	 * try the name AS a namespace first, fail on the missing slash, then
	 * rescan the same bytes as the name. Most core blocks are `wp:paragraph`
	 * with no namespace, so that rescan is the common path, not the rare one.
	 */
	'name1pass' => '/<!--\s+(?P<closer>\/)?wp:(?P<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s',

	// As above, with the non-brace run made possessive.
	'poss'      => '/<!--\s+(?P<closer>\/)?wp:(?P<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(?P<attrs>{(?:(?:[^}]++|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s',

	/*
	 * Numbered groups. PHP populates a named group under BOTH its name and its
	 * number, so five named groups build eleven entries in $matches -- and with
	 * PREG_OFFSET_CAPTURE each entry is itself a two-element array.
	 */
	'numeric'   => '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+' . ATTRS_POSS . '(\/)?-->/s',

	// Numbered, and without the trailing group: `/` before `-->` is readable
	// straight off the end of the matched text.
	'novoid'    => '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+' . ATTRS_POSS . '\/?-->/s',

	// Named, but four groups instead of five, to separate the cost of having
	// more groups from the cost of naming them.
	'named4'    => '/<!--\s+(?P<closer>\/)?wp:(?P<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(?P<attrs>{(?:(?:[^}]++|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s',

	// Anchored at a position found by strpos, so the match offset is already
	// known and PREG_OFFSET_CAPTURE is not needed at all.
	'anchored'  => '/<!--\s+(?P<closer>\/)?wp:(?P<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(?P<attrs>{(?:(?:[^}]++|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/As',

	/*
	 * Two named groups. A named group costs two entries in $matches -- its name
	 * and its number -- so two named groups build the same five entries as four
	 * numbered ones. The `/` markers are read back off the matched text instead.
	 */
	'named2'    => '/<!--\s+\/?wp:(?P<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(?P<attrs>{(?:(?:[^}]++|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?\/?-->/s',
);

/**
 * Tokenizer using named groups, as the parser does today.
 */
function tokenize_named( $document, $pattern ) {
	$tokens  = array();
	$offset  = 0;
	$length  = strlen( $document );
	$matches = null;

	while ( $offset < $length && preg_match( $pattern, $document, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
		list( $match, $started_at ) = $matches[0];

		$token_length = strlen( $match );
		$is_closer    = isset( $matches['closer'] ) && -1 !== $matches['closer'][1];
		$is_void      = isset( $matches['void'] ) && -1 !== $matches['void'][1];

		if ( isset( $matches['namespace'] ) ) {
			$namespace = ( -1 !== $matches['namespace'][1] ) ? $matches['namespace'][0] : 'core/';
			$name      = $namespace . $matches['name'][0];
		} else {
			$name = $matches['name'][0];
			if ( false === strpos( $name, '/' ) ) {
				$name = 'core/' . $name;
			}
		}

		$has_attrs = isset( $matches['attrs'] ) && -1 !== $matches['attrs'][1];
		$attrs     = $has_attrs ? $matches['attrs'][0] : null;

		$tokens[] = array( $is_closer, $is_void, $name, $attrs, $started_at, $token_length );
		$offset   = $started_at + $token_length;
	}

	return $tokens;
}

/**
 * Tokenizer using numbered groups.
 */
function tokenize_numeric( $document, $pattern ) {
	$tokens  = array();
	$offset  = 0;
	$length  = strlen( $document );
	$matches = null;

	while ( $offset < $length && preg_match( $pattern, $document, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
		list( $match, $started_at ) = $matches[0];

		$token_length = strlen( $match );
		$is_closer    = -1 !== $matches[1][1];
		$is_void      = isset( $matches[4] ) && -1 !== $matches[4][1];

		$name = $matches[2][0];
		if ( false === strpos( $name, '/' ) ) {
			$name = 'core/' . $name;
		}

		$has_attrs = isset( $matches[3] ) && -1 !== $matches[3][1];
		$attrs     = $has_attrs ? $matches[3][0] : null;

		$tokens[] = array( $is_closer, $is_void, $name, $attrs, $started_at, $token_length );
		$offset   = $started_at + $token_length;
	}

	return $tokens;
}

/**
 * Tokenizer that reads the void marker off the end of the matched text.
 *
 * Every match ends in `-->`, so a void delimiter ends in `/-->` and the byte
 * four back from the end tells you which it was.
 */
function tokenize_novoid( $document, $pattern ) {
	$tokens  = array();
	$offset  = 0;
	$length  = strlen( $document );
	$matches = null;

	while ( $offset < $length && preg_match( $pattern, $document, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
		list( $match, $started_at ) = $matches[0];

		$token_length = strlen( $match );
		$is_closer    = -1 !== $matches[1][1];
		$is_void      = '/' === $match[ $token_length - 4 ];

		$name = $matches[2][0];
		if ( false === strpos( $name, '/' ) ) {
			$name = 'core/' . $name;
		}

		$has_attrs = isset( $matches[3] ) && -1 !== $matches[3][1];
		$attrs     = $has_attrs ? $matches[3][0] : null;

		$tokens[] = array( $is_closer, $is_void, $name, $attrs, $started_at, $token_length );
		$offset   = $started_at + $token_length;
	}

	return $tokens;
}

/**
 * Tokenizer that finds candidate positions with strpos and matches anchored.
 *
 * Every delimiter begins with the literal `<!--`, so strpos can locate the
 * candidates and the match position is then known without asking PCRE for it.
 * Dropping PREG_OFFSET_CAPTURE means $matches holds plain strings rather than
 * a two-element array per group.
 *
 * Without OFFSET_CAPTURE an unmatched group in the middle of the pattern comes
 * back as '' and a trailing one is omitted entirely. Neither `closer`, `name`,
 * nor `attrs` can match empty when it does participate, so '' is an unambiguous
 * "did not participate" here.
 */
function tokenize_anchored( $document, $pattern ) {
	$tokens  = array();
	$offset  = 0;
	$matches = null;

	while ( false !== ( $started_at = strpos( $document, '<!--', $offset ) ) ) {
		if ( ! preg_match( $pattern, $document, $matches, 0, $started_at ) ) {
			$offset = $started_at + 4;
			continue;
		}

		$token_length = strlen( $matches[0] );
		$is_closer    = '' !== $matches['closer'];
		$is_void      = isset( $matches['void'] ) && '' !== $matches['void'];

		$name = $matches['name'];
		if ( false === strpos( $name, '/' ) ) {
			$name = 'core/' . $name;
		}

		$has_attrs = isset( $matches['attrs'] ) && '' !== $matches['attrs'];
		$attrs     = $has_attrs ? $matches['attrs'] : null;

		$tokens[] = array( $is_closer, $is_void, $name, $attrs, $started_at, $token_length );
		$offset   = $started_at + $token_length;
	}

	return $tokens;
}

/**
 * Tokenizer with only the two groups whose text is actually needed.
 *
 * The two `/` markers are single bytes at positions the match already pins
 * down, so they cost a byte read rather than a capture group:
 *
 *   void   every match ends in `-->`, so a void delimiter ends in `/-->`.
 *   closer `wp:` sits immediately before the name, so the byte four back from
 *          the start of the name is the `/` of a closer, if there is one.
 */
function tokenize_named2( $document, $pattern ) {
	$tokens  = array();
	$offset  = 0;
	$length  = strlen( $document );
	$matches = null;

	while ( $offset < $length && preg_match( $pattern, $document, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
		list( $match, $started_at ) = $matches[0];

		$token_length = strlen( $match );
		$name_at      = $matches['name'][1];
		$is_closer    = '/' === $document[ $name_at - 4 ];
		$is_void      = '/' === $match[ $token_length - 4 ];

		$name = $matches['name'][0];
		if ( false === strpos( $name, '/' ) ) {
			$name = 'core/' . $name;
		}

		$has_attrs = isset( $matches['attrs'] ) && -1 !== $matches['attrs'][1];
		$attrs     = $has_attrs ? $matches['attrs'][0] : null;

		$tokens[] = array( $is_closer, $is_void, $name, $attrs, $started_at, $token_length );
		$offset   = $started_at + $token_length;
	}

	return $tokens;
}

$tokenizers = array(
	'current'   => static fn( $d ) => tokenize_numeric( $d, $GLOBALS['patterns']['current'] ),
	'legacy'    => static fn( $d ) => tokenize_named( $d, $GLOBALS['patterns']['legacy'] ),
	'name1pass' => static fn( $d ) => tokenize_named( $d, $GLOBALS['patterns']['name1pass'] ),
	'poss'      => static fn( $d ) => tokenize_named( $d, $GLOBALS['patterns']['poss'] ),
	'named4'    => static fn( $d ) => tokenize_named( $d, $GLOBALS['patterns']['named4'] ),
	'numeric'   => static fn( $d ) => tokenize_numeric( $d, $GLOBALS['patterns']['numeric'] ),
	'novoid'    => static fn( $d ) => tokenize_novoid( $d, $GLOBALS['patterns']['novoid'] ),
	'anchored'  => static fn( $d ) => tokenize_anchored( $d, $GLOBALS['patterns']['anchored'] ),
	'named2'    => static fn( $d ) => tokenize_named2( $d, $GLOBALS['patterns']['named2'] ),
);

bench_print_environment();
echo str_repeat( '-', 108 ), "\n";

$corpora = corpus_all();
if ( null !== $only ) {
	$corpora = array_intersect_key( $corpora, array_flip( $only ) );
}

/*
 * A faster tokenizer that tokenizes differently is not a faster tokenizer.
 * Check every variant against the current one on every corpus, plus a set of
 * awkward delimiters the generated corpora do not contain.
 */
$edge_cases = array(
	'<!-- wp:paragraph -->x<!-- /wp:paragraph -->',
	'<!-- wp:my-plugin/my-block {"a":1} /-->',
	'<!-- wp:void /-->',
	'<!--   wp:extra-space   {"a":{}}   /-->',
	'<!-- wp:a {"s":"} --> inside a string"} -->',
	'<!-- wp:b {"nested":{"deep":{"deeper":{}}}} -->',
	'<!-- wp:c {} -->',
	'<!-- wp:d {"brace":"}}}}"} -->',
	'<!-- wp:UPPER -->',
	'<!-- wp:9digit -->',
	'<!-- wp: -->',
	'<!--wp:nospace-->',
	'<!-- /wp:closer -->',
	'<!-- /wp:ns/closer -->',
	'<!-- wp:e {"a":1} /--><!-- wp:f -->',
	'text <!-- not a block --> more <!-- wp:g --> tail',
	'<!-- wp:h {"malformed":  -->',
	'<!-- wp:i {"a":"\\"}"} -->',
);

$verify_docs          = $corpora;
$verify_docs['edges'] = implode( "\n", $edge_cases );

$mismatch = false;
foreach ( $verify_docs as $name => $document ) {
	$reference = $tokenizers['current']( $document );

	foreach ( $tokenizers as $label => $fn ) {
		if ( 'current' === $label ) {
			continue;
		}

		if ( $fn( $document ) !== $reference ) {
			echo "\033[31mMISMATCH: {$label} on '{$name}'\033[0m\n";
			$mismatch = true;
		}
	}
}

if ( ! $mismatch ) {
	printf( "equivalence: all %d variants tokenize %d documents identically\n", count( $tokenizers ), count( $verify_docs ) );
}
echo str_repeat( '-', 108 ), "\n";

$labels = array_keys( $tokenizers );

printf( '%-14s %7s', 'corpus', 'tokens' );
foreach ( $labels as $label ) {
	printf( ' %12s', $label );
}
echo "\n";

$totals = array_fill_keys( $labels, 0.0 );

foreach ( $corpora as $name => $document ) {
	$ops = array();
	foreach ( $tokenizers as $label => $fn ) {
		$ops[ $label ] = static function () use ( $fn, $document ) {
			$fn( $document );
		};
	}

	$inner   = bench_calibrate( $ops['current'] );
	$samples = bench_run( $ops, $rounds, $inner );

	$token_count = count( $tokenizers['current']( $document ) );
	printf( '%-14s %7d', $name, $token_count );

	$base = bench_percentile( $samples['current'], 0.5 );

	foreach ( $labels as $label ) {
		$median            = bench_percentile( $samples[ $label ], 0.5 );
		$totals[ $label ] += $median;

		if ( 'current' === $label ) {
			printf( ' %12s', trim( bench_format_ns( $median ) ) );
			continue;
		}

		$ci          = bench_paired_ci( $samples['current'], $samples[ $label ] );
		$significant = ( $ci['low_pct'] > 0 && $ci['high_pct'] > 0 ) || ( $ci['low_pct'] < 0 && $ci['high_pct'] < 0 );
		$color       = $significant ? ( $ci['median_pct'] < 0 ? '32' : '31' ) : '0';

		printf( " \033[%sm%+11.1f%%\033[0m", $color, $ci['median_pct'] );
	}

	echo "\n";
}

echo str_repeat( '-', 108 ), "\n";
printf( '%-14s %7s', 'sum', '' );
foreach ( $labels as $label ) {
	if ( 'current' === $label ) {
		printf( ' %12s', trim( bench_format_ns( $totals[ $label ] ) ) );
	} else {
		printf( ' %+11.1f%%', ( ( $totals[ $label ] / $totals['current'] ) - 1 ) * 100 );
	}
}
echo "\n";
