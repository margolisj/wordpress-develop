<?php
/**
 * Reads back a benchmark record written by `bench.php --record=FILE`.
 *
 * Usage:
 *   php summarize.php [FILE] [--corpus=NAME] [--full]
 *
 * Defaults to results.jsonl beside this script.
 *
 * Runs are listed newest last. Timings are only comparable WITHIN a run --
 * variants are interleaved against each other, never against a reading from
 * another day or another machine state. The noise column is what says whether a
 * run is worth reading at all: when it approaches the change being claimed, the
 * run measured the machine rather than the code.
 */

require __DIR__ . '/harness.php';

$file    = __DIR__ . '/results.jsonl';
$corpus  = null;
$full    = false;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--full' === $arg ) {
		$full = true;
	} elseif ( preg_match( '/^--corpus=(.+)$/', $arg, $m ) ) {
		$corpus = $m[1];
	} elseif ( ! str_starts_with( $arg, '--' ) ) {
		$file = $arg;
	}
}

if ( ! is_file( $file ) ) {
	fwrite( STDERR, "No record at {$file}\n" );
	exit( 1 );
}

$runs = array();
foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	$run = json_decode( $line, true );
	if ( is_array( $run ) ) {
		$runs[] = $run;
	}
}

if ( empty( $runs ) ) {
	fwrite( STDERR, "No runs in {$file}\n" );
	exit( 1 );
}

/**
 * Flags a run whose noise is large enough to swamp what it claims to measure.
 *
 * @param array $run Run data.
 * @return float Largest noise reading in the run.
 */
function summarize_worst_noise( $run ) {
	$noise = array();
	foreach ( $run['corpora'] as $row ) {
		$noise[] = $row['noise'];
	}
	return $noise ? max( $noise ) : 0.0;
}

printf( "%d run(s) in %s\n\n", count( $runs ), $file );

foreach ( $runs as $index => $run ) {
	$env   = $run['environment'];
	$worst = summarize_worst_noise( $run );

	printf(
		"[%d] %s  PHP %s / PCRE %s  JIT:%s  Xdebug:%s  %d rounds  mode:%s\n",
		$index,
		$run['at'],
		$env['php'],
		$env['pcre'],
		$env['pcre_jit'] ? 'on' : 'off',
		false === $env['xdebug'] ? 'absent' : $env['xdebug'],
		$run['rounds'],
		$run['mode']
	);

	$described = array();
	foreach ( $run['variants'] as $label => $rev ) {
		$described[] = "{$label}={$rev}";
	}
	printf( "    %s\n", implode( '  ', $described ) );

	if ( true !== $run['equivalent'] ) {
		$note = null === $run['equivalent'] ? 'equivalence check SKIPPED' : 'OUTPUTS DIFFER';
		printf( "    \033[31m! %s\033[0m\n", $note );
	}

	if ( $worst >= 3.0 ) {
		printf(
			"    \033[33m! noise reached %.1f%% in this run -- treat differences smaller than that as unmeasured\033[0m\n",
			$worst
		);
	}

	$labels   = array_keys( $run['variants'] );
	$baseline = $run['baseline'];
	$rows     = $run['corpora'];

	if ( null !== $corpus ) {
		$rows = array_intersect_key( $rows, array_flip( array( $corpus ) ) );
	}

	if ( $full ) {
		printf( "    %-14s %10s", 'corpus', 'noise' );
		foreach ( $labels as $label ) {
			printf( ' %14s', $label );
		}
		echo "\n";

		foreach ( $rows as $name => $row ) {
			printf( '    %-14s %9.1f%%', $name, $row['noise'] );
			foreach ( $labels as $label ) {
				printf( ' %14s', trim( bench_format_ns( $row['medians'][ $label ] ) ) );
			}
			foreach ( array_slice( $labels, 1 ) as $label ) {
				$change = $row['change'][ $label ];
				printf(
					'  %+.1f%%%s',
					$change['median_pct'],
					$change['significant'] ? '' : ' ns'
				);
			}
			echo "\n";
		}
	}

	$sums  = $run['sums'];
	$parts = array();
	foreach ( array_slice( $labels, 1 ) as $label ) {
		$parts[] = sprintf( '%s %+.1f%%', $label, ( ( $sums[ $label ] / $sums[ $baseline ] ) - 1 ) * 100 );
	}
	printf( "    sum vs %s: %s\n\n", $baseline, implode( '  ', $parts ) );
}

echo "'ns' marks a change that run could not separate from its own noise.\n";
