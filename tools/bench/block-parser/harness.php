<?php
/**
 * Benchmark harness for WP_Block_Parser.
 *
 * Two things make this more trustworthy than a naive timing loop:
 *
 * 1. Variants are loaded into the SAME process under different class names, so
 *    an A/B comparison never pays for process startup, filesystem state, or
 *    whatever else the machine happened to be doing between two `php` runs.
 * 2. Samples are interleaved (A B A B ...) and compared PAIRWISE. Thermal
 *    throttling and scheduler noise drift over seconds; interleaving pushes
 *    that drift into both variants equally so it cancels in the difference.
 *
 * The reported interval is a bootstrap CI over the paired per-round deltas. If
 * it straddles zero, the benchmark cannot tell the two apart -- which is the
 * thing a median-of-7 could never tell you.
 */

/**
 * Loads a parser source under a fresh class name so several revisions can be
 * benchmarked side by side in one process.
 *
 * `\b` does not fire between `Parser` and `_Block`, so `WP_Block_Parser_Block`
 * and `WP_Block_Parser_Frame` are deliberately left alone -- those are shared
 * between variants and must not be duplicated.
 *
 * @param string $source PHP source of class-wp-block-parser.php.
 * @param string $suffix Suffix for the generated class name.
 * @return string Generated class name.
 */
function bench_load_variant( $source, $suffix ) {
	$class = 'WP_Block_Parser_V_' . $suffix;

	// The trailing require_once calls pull in the shared Block/Frame classes.
	$source = preg_replace( '/^\s*require_once[^;]+;/m', '', $source );
	$source = preg_replace( '/\bWP_Block_Parser\b/', $class, $source );
	$source = preg_replace( '/^<\?php/', '', $source, 1 );

	eval( $source );

	if ( ! class_exists( $class ) ) {
		fwrite( STDERR, "Failed to load variant {$suffix}\n" );
		exit( 1 );
	}

	return $class;
}

/**
 * Reads a parser source from a git revision, a file path, or the working tree.
 *
 * @param string $spec Git revision, file path, or "WORKING".
 * @param string $repo Repository root.
 * @return string
 */
function bench_read_source( $spec, $repo ) {
	$working = $repo . '/src/wp-includes/class-wp-block-parser.php';

	if ( 'WORKING' === $spec ) {
		return file_get_contents( $working );
	}

	if ( is_file( $spec ) ) {
		return file_get_contents( $spec );
	}

	$cmd = sprintf(
		'git -C %s show %s:src/wp-includes/class-wp-block-parser.php',
		escapeshellarg( $repo ),
		escapeshellarg( $spec )
	);

	$out  = array();
	$code = 0;
	exec( $cmd . ' 2>/dev/null', $out, $code );

	if ( 0 !== $code ) {
		fwrite( STDERR, "Could not read parser source for '{$spec}'\n" );
		exit( 1 );
	}

	return implode( "\n", $out );
}

/**
 * Picks an inner iteration count so one sample lands near the target duration.
 *
 * Samples that are too short are dominated by timer granularity and loop
 * overhead; samples that are too long collect too few rounds to say anything
 * about spread.
 *
 * @param callable $fn          Operation to time.
 * @param float    $target_ms   Desired duration of a single sample.
 * @return int
 */
function bench_calibrate( $fn, $target_ms = 12.0 ) {
	$inner = 1;

	for ( $attempt = 0; $attempt < 30; $attempt++ ) {
		$start = hrtime( true );
		for ( $i = 0; $i < $inner; $i++ ) {
			$fn();
		}
		$elapsed_ms = ( hrtime( true ) - $start ) / 1e6;

		if ( $elapsed_ms >= $target_ms ) {
			return $inner;
		}

		// Grow toward the target, but never trust a sub-millisecond reading.
		$factor = $elapsed_ms > 0.05 ? ( $target_ms / $elapsed_ms ) : 8.0;
		$inner  = max( $inner + 1, (int) ceil( $inner * min( $factor, 8.0 ) ) );
	}

	return $inner;
}

/**
 * Runs interleaved, paired samples of several variants.
 *
 * @param array $ops    Map of label => callable.
 * @param int   $rounds Number of paired rounds.
 * @param int   $inner  Iterations per sample.
 * @param int   $warmup Warmup rounds, discarded.
 * @return array Map of label => list of ns/op samples.
 */
function bench_run( $ops, $rounds, $inner, $warmup = 3 ) {
	$samples = array();
	foreach ( $ops as $label => $fn ) {
		$samples[ $label ] = array();
	}

	// Warm caches, JIT, and any lazily-built internal state before recording.
	for ( $w = 0; $w < $warmup; $w++ ) {
		foreach ( $ops as $fn ) {
			for ( $i = 0; $i < $inner; $i++ ) {
				$fn();
			}
		}
	}

	for ( $r = 0; $r < $rounds; $r++ ) {
		foreach ( $ops as $label => $fn ) {
			$start = hrtime( true );
			for ( $i = 0; $i < $inner; $i++ ) {
				$fn();
			}
			$samples[ $label ][] = ( hrtime( true ) - $start ) / $inner;
		}
	}

	return $samples;
}

/**
 * Returns the value at a percentile of a sorted-on-demand list.
 *
 * @param array $values List of numbers.
 * @param float $p      Percentile between 0 and 1.
 * @return float
 */
function bench_percentile( $values, $p ) {
	sort( $values );
	$n = count( $values );

	if ( 0 === $n ) {
		return 0.0;
	}

	$rank  = $p * ( $n - 1 );
	$low   = (int) floor( $rank );
	$high  = (int) ceil( $rank );
	$frac  = $rank - $low;

	return $values[ $low ] * ( 1 - $frac ) + $values[ $high ] * $frac;
}

/**
 * Median absolute deviation -- a spread measure that a few slow samples cannot
 * inflate the way a standard deviation can.
 *
 * @param array $values List of numbers.
 * @return float
 */
function bench_mad( $values ) {
	$median = bench_percentile( $values, 0.5 );

	$deviations = array();
	foreach ( $values as $value ) {
		$deviations[] = abs( $value - $median );
	}

	return bench_percentile( $deviations, 0.5 );
}

/**
 * Bootstrap confidence interval for the median of the paired deltas.
 *
 * Resamples the per-round differences with replacement. Because the rounds are
 * paired and interleaved, a CI that excludes zero means the difference outlived
 * the machine noise rather than riding on it.
 *
 * @param array $baseline   Samples for the baseline variant.
 * @param array $candidate  Samples for the candidate variant.
 * @param int   $resamples  Bootstrap resample count.
 * @param float $confidence Confidence level.
 * @return array {
 *     @type float $median_pct Median percentage change.
 *     @type float $low_pct    Lower bound of the interval.
 *     @type float $high_pct   Upper bound of the interval.
 * }
 */
function bench_paired_ci( $baseline, $candidate, $resamples = 4000, $confidence = 0.95 ) {
	$n = min( count( $baseline ), count( $candidate ) );

	$ratios = array();
	for ( $i = 0; $i < $n; $i++ ) {
		if ( $baseline[ $i ] > 0 ) {
			$ratios[] = $candidate[ $i ] / $baseline[ $i ];
		}
	}

	if ( count( $ratios ) < 2 ) {
		return array(
			'median_pct' => 0.0,
			'low_pct'    => 0.0,
			'high_pct'   => 0.0,
		);
	}

	$count      = count( $ratios );
	$bootstraps = array();

	for ( $b = 0; $b < $resamples; $b++ ) {
		$resample = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$resample[] = $ratios[ random_int( 0, $count - 1 ) ];
		}
		$bootstraps[] = bench_percentile( $resample, 0.5 );
	}

	$tail = ( 1 - $confidence ) / 2;

	return array(
		'median_pct' => ( bench_percentile( $ratios, 0.5 ) - 1 ) * 100,
		'low_pct'    => ( bench_percentile( $bootstraps, $tail ) - 1 ) * 100,
		'high_pct'   => ( bench_percentile( $bootstraps, 1 - $tail ) - 1 ) * 100,
	);
}

/**
 * Formats a nanosecond duration with a fitting unit.
 *
 * @param float $ns Nanoseconds.
 * @return string
 */
function bench_format_ns( $ns ) {
	if ( $ns >= 1e6 ) {
		return sprintf( '%8.3f ms', $ns / 1e6 );
	}
	if ( $ns >= 1e3 ) {
		return sprintf( '%8.2f us', $ns / 1e3 );
	}
	return sprintf( '%8.1f ns', $ns );
}

/**
 * Collects the environment facts that decide whether a regex benchmark means
 * anything at all.
 *
 * Recorded alongside every result: a timing without its build is not a
 * comparable number, since PCRE JIT and Xdebug each move these figures further
 * than any change measured here.
 *
 * @return array
 */
function bench_environment() {
	ob_start();
	phpinfo( INFO_MODULES );
	$info = ob_get_clean();

	return array(
		'php'      => PHP_VERSION,
		'pcre'     => PCRE_VERSION,
		'pcre_jit' => (bool) preg_match( '/PCRE JIT Support\s*=>\s*enabled/i', $info ),
		'xdebug'   => extension_loaded( 'xdebug' ) ? ( ini_get( 'xdebug.mode' ) ?: 'loaded' ) : false,
		'opcache'  => (bool) ( function_exists( 'opcache_get_status' ) && ini_get( 'opcache.enable_cli' ) ),
		'os'       => PHP_OS_FAMILY,
	);
}

/**
 * Prints the environment facts that decide whether a regex benchmark means
 * anything at all.
 */
function bench_print_environment() {
	$env      = bench_environment();
	$pcre_jit = $env['pcre_jit'];

	printf(
		"PHP %s | PCRE %s | PCRE JIT: %s | Xdebug: %s | OPcache: %s\n",
		$env['php'],
		$env['pcre'],
		$pcre_jit ? "\033[32mON\033[0m" : "\033[31mOFF\033[0m",
		false !== $env['xdebug'] ? "\033[31mLOADED (mode=" . $env['xdebug'] . ")\033[0m" : 'absent',
		$env['opcache'] ? 'on' : 'off'
	);

	if ( ! $pcre_jit ) {
		echo "\033[33m  ! PCRE JIT is off in this build. Regex timings here do not\n";
		echo "    predict a production WordPress host, which normally has it on.\033[0m\n";
	}
}
