<?php
/**
 * WP_Block_Parser benchmark driver.
 *
 * Usage:
 *   php bench.php [options] <label>=<rev|path|WORKING> [<label>=<rev> ...]
 *
 * Options:
 *   --rounds=N     Paired rounds per corpus (default 60).
 *   --corpus=A,B   Restrict to named corpora.
 *   --preserve     Parse with preserve_empty_object_attributes.
 *   --repo=PATH    Repository root (default: inferred).
 *   --no-verify    Skip the equivalence check. Use only when a variant is
 *                  deliberately expected to differ.
 *   --quick        Fewer rounds, for a fast signal while iterating.
 *   --record=FILE  Append the run to FILE as one line of JSON.
 *
 * The first variant listed is the baseline every other one is compared against.
 *
 * Example:
 *   php bench.php base=HEAD cand=WORKING --corpus=attr-large,html-heavy
 *
 * A recorded run carries the resolved revisions, the PHP and PCRE build, and
 * every median and interval, so a number can be traced back to what produced
 * it. Timings are only comparable within one run: variants are interleaved
 * against each other, not against a reading taken on another day.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/corpora.php';

$argv_rest = array_slice( $argv, 1 );
$options   = array(
	'rounds'   => 60,
	'corpus'   => null,
	'repo'     => null,
	'verify'   => true,
	'preserve' => false,
	'record'   => null,
);
$variants  = array();

foreach ( $argv_rest as $arg ) {
	if ( '--quick' === $arg ) {
		$options['rounds'] = 20;
	} elseif ( '--preserve' === $arg ) {
		$options['preserve'] = true;
	} elseif ( '--no-verify' === $arg ) {
		$options['verify'] = false;
	} elseif ( preg_match( '/^--rounds=(\d+)$/', $arg, $m ) ) {
		$options['rounds'] = (int) $m[1];
	} elseif ( preg_match( '/^--corpus=(.+)$/', $arg, $m ) ) {
		$options['corpus'] = explode( ',', $m[1] );
	} elseif ( preg_match( '/^--repo=(.+)$/', $arg, $m ) ) {
		$options['repo'] = $m[1];
	} elseif ( preg_match( '/^--record=(.+)$/', $arg, $m ) ) {
		$options['record'] = $m[1];
	} elseif ( preg_match( '/^([A-Za-z0-9_.-]+)=(.+)$/', $arg, $m ) ) {
		$variants[ $m[1] ] = $m[2];
	} else {
		fwrite( STDERR, "Unrecognized argument: {$arg}\n" );
		exit( 1 );
	}
}

if ( empty( $variants ) ) {
	fwrite( STDERR, "No variants given. See the header of this file for usage.\n" );
	exit( 1 );
}

$repo = $options['repo'];
if ( null === $repo ) {
	$repo = trim( shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' rev-parse --show-toplevel 2>/dev/null' ) ?? '' );
	if ( '' === $repo || ! is_dir( $repo . '/src/wp-includes' ) ) {
		$repo = getcwd();
	}
}

if ( ! is_file( $repo . '/src/wp-includes/class-wp-block-parser.php' ) ) {
	fwrite( STDERR, "Could not locate the parser under {$repo}. Pass --repo=PATH.\n" );
	exit( 1 );
}

require_once $repo . '/src/wp-includes/class-wp-block-parser-block.php';
require_once $repo . '/src/wp-includes/class-wp-block-parser-frame.php';

bench_print_environment();
echo str_repeat( '-', 96 ), "\n";

// Resolve and load each variant.
$classes  = array();
$resolved = array();
$suffix   = 0;

foreach ( $variants as $label => $spec ) {
	$source = bench_read_source( $spec, $repo );
	$class  = bench_load_variant( $source, 'X' . ( ++$suffix ) );

	$classes[ $label ] = $class;

	if ( 'WORKING' === $spec ) {
		$described = 'working tree';
	} elseif ( is_file( $spec ) ) {
		$described = $spec;
	} else {
		$described = trim( shell_exec( sprintf(
			'git -C %s rev-parse --short %s 2>/dev/null',
			escapeshellarg( $repo ),
			escapeshellarg( $spec )
		) ) ?? $spec );
	}

	$resolved[ $label ] = $described;
	printf( "  %-10s %s (%d bytes of source)\n", $label, $described, strlen( $source ) );
}

$parse_options = $options['preserve']
	? array( 'preserve_empty_object_attributes' => true )
	: array();

printf(
	"  mode:      %s | rounds: %d\n",
	$options['preserve'] ? 'preserve_empty_object_attributes' : 'default parse',
	$options['rounds']
);
echo str_repeat( '-', 96 ), "\n";

/**
 * Parses with a variant, using parse_with_options() when it exists.
 */
function bench_parse( $class, $document, $parse_options ) {
	$parser = new $class();

	if ( ! empty( $parse_options ) && method_exists( $parser, 'parse_with_options' ) ) {
		return $parser->parse_with_options( $document, $parse_options );
	}

	return $parser->parse( $document );
}

$corpora = corpus_all();

if ( null !== $options['corpus'] ) {
	$selected = array();
	foreach ( $options['corpus'] as $name ) {
		if ( ! isset( $corpora[ $name ] ) ) {
			fwrite( STDERR, "Unknown corpus '{$name}'. Available: " . implode( ', ', array_keys( $corpora ) ) . "\n" );
			exit( 1 );
		}
		$selected[ $name ] = $corpora[ $name ];
	}
	$corpora = $selected;
}

$labels        = array_keys( $classes );
$baseline      = $labels[0];
$mismatches    = array();
$totals        = array();

foreach ( $labels as $label ) {
	$totals[ $label ] = 0.0;
}

// An "optimization" that changes the parse is not an optimization. Check first,
// so a mismatch is reported even if the timings look attractive.
if ( $options['verify'] && count( $labels ) > 1 ) {
	foreach ( $corpora as $name => $document ) {
		$reference = serialize( bench_parse( $classes[ $baseline ], $document, $parse_options ) );

		foreach ( array_slice( $labels, 1 ) as $label ) {
			$actual = serialize( bench_parse( $classes[ $label ], $document, $parse_options ) );

			if ( $actual !== $reference ) {
				$mismatches[] = "{$label} vs {$baseline} on corpus '{$name}'";
			}
		}
	}

	if ( ! empty( $mismatches ) ) {
		echo "\033[31mOUTPUT MISMATCH -- variants do not parse identically:\033[0m\n";
		foreach ( $mismatches as $mismatch ) {
			echo "  - {$mismatch}\n";
		}
		echo "\033[31mTimings below are not a like-for-like comparison.\033[0m\n";
		echo str_repeat( '-', 96 ), "\n";
	} else {
		echo "equivalence: all variants parse every corpus identically\n";
		echo str_repeat( '-', 96 ), "\n";
	}
}

// Header.
printf( "%-14s %6s %10s", 'corpus', 'KiB', 'blocks' );
foreach ( $labels as $label ) {
	printf( ' %14s', $label );
}
if ( count( $labels ) > 1 ) {
	printf( '  %-28s', 'change vs ' . $baseline );
}
echo "\n";

$recorded = array();

foreach ( $corpora as $name => $document ) {
	$block_count = count( bench_parse( $classes[ $baseline ], $document, $parse_options ) );

	$ops = array();
	foreach ( $classes as $label => $class ) {
		$ops[ $label ] = static function () use ( $class, $document, $parse_options ) {
			bench_parse( $class, $document, $parse_options );
		};
	}

	$inner   = bench_calibrate( $ops[ $baseline ] );
	$samples = bench_run( $ops, $options['rounds'], $inner );

	printf( '%-14s %6.1f %10d', $name, strlen( $document ) / 1024, $block_count );

	$row = array(
		'bytes'   => strlen( $document ),
		'blocks'  => $block_count,
		'inner'   => $inner,
		'medians' => array(),
		'change'  => array(),
	);

	foreach ( $labels as $label ) {
		$median = bench_percentile( $samples[ $label ], 0.5 );
		$totals[ $label ] += $median;

		$row['medians'][ $label ] = round( $median, 2 );
		printf( ' %14s', trim( bench_format_ns( $median ) ) );
	}

	if ( count( $labels ) > 1 ) {
		$parts = array();
		foreach ( array_slice( $labels, 1 ) as $label ) {
			$ci = bench_paired_ci( $samples[ $baseline ], $samples[ $label ] );

			// A CI that straddles zero means the run cannot separate the two.
			$significant = ( $ci['low_pct'] > 0 && $ci['high_pct'] > 0 )
				|| ( $ci['low_pct'] < 0 && $ci['high_pct'] < 0 );

			$row['change'][ $label ] = array(
				'median_pct'  => round( $ci['median_pct'], 2 ),
				'low_pct'     => round( $ci['low_pct'], 2 ),
				'high_pct'    => round( $ci['high_pct'], 2 ),
				'significant' => $significant,
			);

			$color = '0';
			if ( $significant ) {
				$color = $ci['median_pct'] < 0 ? '32' : '31';
			}

			$parts[] = sprintf(
				"\033[%sm%+6.1f%%\033[0m [%+.1f, %+.1f]%s",
				$color,
				$ci['median_pct'],
				$ci['low_pct'],
				$ci['high_pct'],
				$significant ? '' : ' ns'
			);
		}
		printf( '  %s', implode( '  ', $parts ) );
	}

	// Noise floor: how much the baseline varied against itself this round.
	$mad          = bench_mad( $samples[ $baseline ] );
	$median       = bench_percentile( $samples[ $baseline ], 0.5 );
	$row['noise'] = round( $median > 0 ? ( $mad / $median ) * 100 : 0, 2 );
	printf( "   (noise +/-%.1f%%)", $row['noise'] );

	$recorded[ $name ] = $row;

	echo "\n";
}

echo str_repeat( '-', 96 ), "\n";
printf( '%-32s', 'sum of medians' );
foreach ( $labels as $label ) {
	printf( ' %14s', trim( bench_format_ns( $totals[ $label ] ) ) );
}
if ( count( $labels ) > 1 ) {
	$parts = array();
	foreach ( array_slice( $labels, 1 ) as $label ) {
		$parts[] = sprintf( '%+.1f%%', ( ( $totals[ $label ] / $totals[ $baseline ] ) - 1 ) * 100 );
	}
	printf( '  %s', implode( '  ', $parts ) );
}
echo "\n";
echo "\n'ns' marks a change the run could not separate from noise.\n";

if ( null !== $options['record'] ) {
	$run = array(
		'at'          => gmdate( 'c' ),
		'environment' => bench_environment(),
		'mode'        => $options['preserve'] ? 'preserve_empty_object_attributes' : 'default',
		'rounds'      => $options['rounds'],
		'baseline'    => $baseline,
		'variants'    => $resolved,
		// Null rather than true when the check was skipped: not asked is not passed.
		'equivalent'  => $options['verify'] ? empty( $mismatches ) : null,
		'mismatches'  => $mismatches,
		'corpora'     => $recorded,
		'sums'        => array_map(
			static function ( $total ) {
				return round( $total, 2 );
			},
			$totals
		),
	);

	// One run per line, so results append without rewriting what came before.
	$written = file_put_contents(
		$options['record'],
		json_encode( $run, JSON_UNESCAPED_SLASHES ) . "\n",
		FILE_APPEND
	);

	if ( false === $written ) {
		fwrite( STDERR, "Could not write to {$options['record']}\n" );
		exit( 1 );
	}

	printf( "recorded to %s\n", $options['record'] );
}
