# Where `WP_Block_Parser` spends its time

A record of what was measured, what was changed, and what was deliberately left
alone. Every number here came from `bench.php` or `decompose.php` and is
reproducible with the commands at the bottom.

Unless stated otherwise: PHP 8.5.9, PCRE 10.47, JIT on, no Xdebug, 60 interleaved
paired rounds, medians, noise 0.5-1.2%. Recorded runs are in `results.jsonl` and
readable with `summarize.php`.

## Summary

The tokenizer was worth optimizing and has been. What remains is either outside
PHP's reach or costs more in compatibility than it returns in speed. **The parser
is done** unless the block object becomes negotiable.

| Change | Effect | Status |
| --- | --- | --- |
| Numbered capture groups, single-pass block name | **-10.2%** | Shipped |
| Blocks built as plain arrays | **-5.3%** | Measured, not taken -- see below |
| Frames built as plain arrays | +1.0% | Rejected: slower |
| `strpos` prefilter + anchored match | +246.9% on html-heavy | Rejected |
| Possessive `[^}]++` in the attribute group | ~0% | Rejected: no effect |

## What the tokenizer work fixed

Two things, and it is worth separating them because they are unrelated causes
that happened to live in the same regex.

**Named groups cost double.** PHP records a named group under both its name and
its number, so five named groups built eleven entries in `$matches` -- each a
two-element array under `PREG_OFFSET_CAPTURE`. Worse, a single string key forces
`$matches` from a packed array into a hash table. That second effect is the
larger one: a two-named-group variant reached only -17.8% where four numbered
groups reached -27.4%, despite the two building the same number of entries. Entry
count was not the problem; the array representation was.

**The split block name backtracked on the common case.** Written as an optional
namespace followed by a name:

```
(?<ns>[a-z][a-z0-9_-]*\/)?(?<name>[a-z][a-z0-9_-]*)
```

PCRE matches `paragraph` as a namespace, fails to find the `/`, then rescans the
same bytes as the name. An unnamespaced `wp:paragraph` is the common case, so
that rescan was the rule, not the exception. Merging the two into one group with
an optional second segment removes it. This accounted for -8.6% on its own,
confirmed by a numbered-group variant that kept the split name scoring the same
as a named-group variant that fixed it.

## Where the time goes now

Cumulative stages; the gap between two columns is the cost that layer adds. Per
token, in nanoseconds.

| corpus | scan | regex | unpack | json | build |
| --- | --- | --- | --- | --- | --- |
| realistic | 102 | +132 | +150 | -22 | **+293** |
| flat-many | 100 | +114 | +141 | -51 | **+283** |
| deep-nest | 100 | +138 | +154 | +8 | **+291** |
| html-heavy | 166 | +119 | +141 | -48 | **+367** |
| comment-heavy | 385 | +104 | +142 | -48 | **+328** |
| attr-large | 106 | +246 | +160 | **+979** | +295 |
| attr-braces | 101 | +292 | +183 | **+1371** | +456 |

Two regimes, and they want opposite things:

- **Attribute-heavy content** is `json_decode`, by a wide margin -- more than
  scan, match, unpack and build combined. It is C, and `parse_blocks()` is
  contractually required to return decoded attributes, so there is nothing to do
  here. Negative `json` deltas on the other rows are measurement noise around
  zero, not a saving.
- **Everything else** is block building. Since the tokenizer work, this is the
  largest single layer for every corpus that is not attribute-dominated.

Note `comment-heavy` scan at 385 ns/token against 100-166 elsewhere. That is PCRE
working through markup dense in `-->` sequences, and it is why the `strpos`
prefilter idea below fails: the scan is already the fast part.

## The block object: -5.3%, not taken

Every block is built as a `WP_Block_Parser_Block`, mutated as an accumulator
while its children are parsed, then unwrapped with `(array) $block` on the way
out. A variant that builds plain arrays throughout parses all nine corpora
byte-identically and is **5.3% faster overall** -- 6% to 8% on ordinary
block-heavy content.

A second variant isolated which object was responsible. Converting only the
internal `WP_Block_Parser_Frame` -- pure bookkeeping, never returned to a caller
-- measured **+1.0%, slightly slower**. Array hash lookups and the reference
binding cost more than the object property slots they replaced.

So the entire win is `WP_Block_Parser_Block` and its cast, not the frame. This
inverts the intuition that the internal, unexposed class would be the safe place
to optimize; it is the one place where there was nothing to gain.

**Why it was not taken.** `WP_Block_Parser_Block` is a documented public class.
`freeform()` is a public method that returns one, `add_inner_block()` typehints
one, and both are overridable by any parser installed through the
`block_parser_class` filter. Realising the 5.3% means the parser stops
instantiating a class third-party code can subclass and typehint.

That is a compatibility decision, not a performance one, and 5% of a component
that is a minority of page-render time does not buy it. The calculation changes
for a plugin shipping its own parser through `block_parser_class`, where the
compatibility surface is its own.

## Rejected by measurement

Recording these so they are not retried.

**`strpos` prefilter before an anchored match.** Find the next `<` with
`strpos()`, then run an anchored pattern at that offset. **+246.9% on
html-heavy.** PCRE's scan for a literal prefix is vectorized; `strpos` called
once per `<` in markup dense with them is far worse. The scan is not where the
regex spends its time.

**Possessive quantifier on the attribute group.** `[^}]++` instead of `[^}]+`.
No measurable effect, reconfirmed later at +1.1% against the shipped pattern.
The alternation already prevents the backtracking it would have suppressed.

## The environment decides more than the code

A regex benchmark run under the wrong build predicts nothing, so treat the header
line as part of the result:

- **PCRE JIT off** changes regex cost enormously. The asdf build pinned in
  `.tool-versions` is compiled `--without-pcre-jit`, so it is the wrong build to
  tune a pattern against. Every conclusion here was confirmed on both a JIT and a
  non-JIT build; the tokenizer change measures -10.2% with JIT and -10.6% to
  -10.9% without.
- **Xdebug loaded** inflates everything several fold and skews the shape of the
  profile, not just its scale.

One measurement in this project's history was reported from a contaminated run --
absolute timings four times inflated on a busy machine. The run said so: its
noise column read 3-13% against a claimed -14.3%. A difference near the size of
the run's own noise has not been measured. `summarize.php` now flags any recorded
run reaching 3%.

## Reproducing

```sh
cd tools/bench/block-parser

# Where the time goes.
php decompose.php

# The shipped tokenizer against its predecessor.
php bench.php trunk=trunk tokenizer=HEAD --rounds=60

# The block-object and frame variants (see results.jsonl run [4]; the revisions
# it names predate a rebase, so its hashes no longer resolve -- the medians and
# intervals it records are the point, not the hashes).
php bench.php head=HEAD arrays=/path/to/variant.php --rounds=60

# Tokenizer patterns against each other, correctness checked first.
php patterns.php

# Read back what previous runs measured.
php summarize.php
```
