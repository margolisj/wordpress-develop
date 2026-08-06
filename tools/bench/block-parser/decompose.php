<?php
/**
 * Splits parse cost into its stages.
 *
 * Optimizing the tokenizer regex is only worthwhile if the regex is where the
 * time goes. These stages are cumulative -- each adds one layer of work over
 * the same documents, so the gap between two rows is the cost of that layer:
 *
 *   scan     A bare literal search for the delimiter prefix. The floor: what it
 *            costs merely to walk the document and find candidate positions.
 *   match    The real delimiter pattern with capture groups, matches discarded.
 *   unpack   ...plus reading the capture groups out, attrs left as a raw string.
 *   decode   ...plus json_decode of the attributes. This is next_token().
 *   parse    ...plus block construction, the stack, and substr slicing.
 *
 * Usage: php decompose.php [--rev=REV] [--repo=PATH] [--rounds=N]
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/corpora.php';

$rev    = 'WORKING';
$repo   = null;
$rounds = 25;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--rev=(.+)$/', $arg, $m ) ) {
		$rev = $m[1];
	} elseif ( preg_match( '/^--repo=(.+)$/', $arg, $m ) ) {
		$repo = $m[1];
	} elseif ( preg_match( '/^--rounds=(\d+)$/', $arg, $m ) ) {
		$rounds = (int) $m[1];
	}
}

if ( null === $repo ) {
	$repo = trim( shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' rev-parse --show-toplevel 2>/dev/null' ) ?? '' );
	if ( '' === $repo || ! is_dir( $repo . '/src/wp-includes' ) ) {
		$repo = getcwd();
	}
}

require_once $repo . '/src/wp-includes/class-wp-block-parser-block.php';
require_once $repo . '/src/wp-includes/class-wp-block-parser-frame.php';

$class = bench_load_variant( bench_read_source( $rev, $repo ), 'D' );

bench_print_environment();
echo str_repeat( '-', 100 ), "\n";

const FULL_PATTERN = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
const SCAN_PATTERN = '/<!--\s+\/?wp:/s';

/**
 * Walks the document with a pattern, discarding matches.
 *
 * Advancing needs the match POSITION, not just its length, or the loop rescans
 * the gap between offset and match on every iteration and turns quadratic.
 */
function bench_scan( $document, $pattern ) {
	$offset  = 0;
	$matches = null;
	$length  = strlen( $document );

	while ( $offset < $length && preg_match( $pattern, $document, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
		$offset = $matches[0][1] + max( 1, strlen( $matches[0][0] ) );
	}
}

/**
 * Matches the real pattern and reads the capture groups out, but leaves the
 * attributes as the raw substring -- everything next_token() does except the
 * json_decode.
 */
function bench_unpack( $document ) {
	$offset  = 0;
	$matches = null;
	$length  = strlen( $document );
	$sink    = null;

	while ( $offset < $length && preg_match( FULL_PATTERN, $document, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
		list( $match, $started_at ) = $matches[0];

		$token_length = strlen( $match );
		$is_closer    = isset( $matches['closer'] ) && -1 !== $matches['closer'][1];
		$is_void      = isset( $matches['void'] ) && -1 !== $matches['void'][1];
		$namespace    = $matches['namespace'];
		$namespace    = ( isset( $namespace ) && -1 !== $namespace[1] ) ? $namespace[0] : 'core/';
		$name         = $namespace . $matches['name'][0];
		$has_attrs    = isset( $matches['attrs'] ) && -1 !== $matches['attrs'][1];

		$sink = $has_attrs ? $matches['attrs'][0] : array();
		$sink = array( $is_closer, $is_void, $name, $sink );

		$offset = $started_at + $token_length;
	}
}

/**
 * Runs next_token() across a document without any parser bookkeeping.
 */
function bench_next_token( $class, $document ) {
	$parser           = new $class();
	$parser->document = $document;
	$parser->offset   = 0;
	$length           = strlen( $document );

	while ( $parser->offset < $length ) {
		$token = $parser->next_token();

		if ( 'no-more-tokens' === $token[0] ) {
			break;
		}

		$parser->offset = $token[3] + $token[4];
	}
}

$stages = array( 'scan', 'match', 'unpack', 'decode', 'parse' );

printf( "%-14s %7s %7s", 'corpus', 'KiB', 'tokens' );
foreach ( $stages as $stage ) {
	printf( ' %11s', $stage );
}
echo "     per-token deltas (ns)\n";
echo str_repeat( '-', 100 ), "\n";

foreach ( corpus_all() as $name => $document ) {
	$token_count = preg_match_all( FULL_PATTERN, $document );

	$ops = array(
		'scan'   => static function () use ( $document ) {
			bench_scan( $document, SCAN_PATTERN );
		},
		'match'  => static function () use ( $document ) {
			bench_scan( $document, FULL_PATTERN );
		},
		'unpack' => static function () use ( $document ) {
			bench_unpack( $document );
		},
		'decode' => static function () use ( $class, $document ) {
			bench_next_token( $class, $document );
		},
		'parse'  => static function () use ( $class, $document ) {
			$parser = new $class();
			$parser->parse( $document );
		},
	);

	$inner   = bench_calibrate( $ops['parse'] );
	$samples = bench_run( $ops, $rounds, $inner );

	$median = array();
	foreach ( $stages as $stage ) {
		$median[ $stage ] = bench_percentile( $samples[ $stage ], 0.5 );
	}

	printf( '%-14s %7.1f %7d', $name, strlen( $document ) / 1024, $token_count );
	foreach ( $stages as $stage ) {
		printf( ' %11s', trim( bench_format_ns( $median[ $stage ] ) ) );
	}

	if ( $token_count > 0 ) {
		printf(
			'     scan %.0f | regex %+.0f | unpack %+.0f | json %+.0f | build %+.0f',
			$median['scan'] / $token_count,
			( $median['match'] - $median['scan'] ) / $token_count,
			( $median['unpack'] - $median['match'] ) / $token_count,
			( $median['decode'] - $median['unpack'] ) / $token_count,
			( $median['parse'] - $median['decode'] ) / $token_count
		);
	}

	echo "\n";
}

echo str_repeat( '-', 100 ), "\n";
echo "Stages are cumulative; the deltas attribute each token's cost to the layer that added it.\n";
