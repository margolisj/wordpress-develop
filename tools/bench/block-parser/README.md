# Block parser benchmarks

Timing harness for `WP_Block_Parser`. Needs only a PHP binary -- no database, no
WordPress bootstrap, no PHPUnit.

## Why it is built this way

A median-of-a-few-runs benchmark cannot see a 5% change, because the gap between
two `php` invocations is itself worth more than 5%. Three things fix that:

- **Variants share one process.** `bench.php` loads several revisions of the
  parser under different class names, so an A/B never pays for process startup or
  for whatever else the machine was doing between two runs.
- **Samples are interleaved and compared pairwise.** Thermal throttling drifts
  over seconds; alternating A B A B pushes that drift into both variants equally
  so it cancels in the difference.
- **The reported interval is a bootstrap CI over the paired deltas.** When it
  straddles zero the run is marked `ns` -- the benchmark is saying it cannot tell
  the two apart, rather than reporting a number that is really noise.

Noise floor in practice is 0.3% to 2% per corpus, printed alongside each row.

Loading several revisions at once means `eval()`ing each parser source under a
renamed class. That is why this is developer tooling, run by hand, and nothing
WordPress itself ever loads.

## Environment matters more than the code

The header line reports PCRE JIT, Xdebug, and OPcache, because a regex benchmark
run under the wrong build predicts nothing:

- **PCRE JIT off** changes regex cost enormously. A production WordPress host
  normally has it on. The asdf build pinned in `.tool-versions` is compiled
  `--without-pcre-jit`, so it is the wrong build to tune a pattern against.
- **Xdebug loaded** inflates everything several fold and skews the shape of the
  profile, not just its scale. Pass `-dxdebug.mode=off`, or use a build without
  it.

On this machine the Homebrew PHP has JIT and no Xdebug; the pinned asdf PHP has
neither property. Run both -- a change worth making should hold under each.

## Scripts

| Script | Answers |
| --- | --- |
| `bench.php` | Is revision B faster than revision A, and by how much? |
| `decompose.php` | Where does parse time actually go? |
| `patterns.php` | Which tokenizer pattern is fastest, and do they agree? |
| `summarize.php` | What did previous runs measure? |

### bench.php

```
php bench.php [options] <label>=<rev|path|WORKING> [<label>=<rev> ...]

  --rounds=N     Paired rounds per corpus (default 60).
  --corpus=A,B   Restrict to named corpora.
  --preserve     Parse with preserve_empty_object_attributes.
  --repo=PATH    Repository root.
  --no-verify    Skip the equivalence check.
  --quick        Fewer rounds, for a fast signal while iterating.
  --record=FILE  Append the run to FILE as one line of JSON.
```

The first variant is the baseline. Before timing anything it parses every corpus
with every variant and refuses to present the comparison as like-for-like if the
outputs differ -- a faster parser that parses differently is not a faster parser.

```sh
php bench.php trunk=trunk head=HEAD tok=WORKING --repo=/path/to/wordpress-develop
```

### Reading the noise column

Every row prints how much the baseline varied against itself during that run.
That figure is the resolution of the run, and a difference smaller than it was
not measured -- it was guessed at.

A quiet machine gives 0.3% to 2%. If a row reports much more, something else was
competing for the CPU; re-run rather than reporting the number. `summarize.php`
flags any recorded run whose noise reached 3%.

Absolute timings are comparable only WITHIN one run. Variants are interleaved
against each other, never against a reading taken at another time, so the same
document can differ several fold between runs on a busy versus an idle machine
while the percentage between variants stays put.

### summarize.php

```sh
php summarize.php [FILE] [--corpus=NAME] [--full]
```

Reads back `results.jsonl`. Each line records the resolved revisions, the PHP and
PCRE build, whether the variants parsed identically, and every median and
interval -- so a number quoted in a commit message can be traced to the run that
produced it.

### decompose.php

Cumulative stages over the same documents, so the gap between two rows is the
cost of that layer: `scan` -> `match` -> `unpack` -> `decode` -> `parse`. This is
what shows that `json_decode` dominates attribute-heavy content while the regex
is a minority of the total.

### patterns.php

Tokenizer-only shootout. Every variant is checked against the current pattern on
all corpora plus a list of awkward delimiters -- string values containing `}`
and `-->`, runs of braces, malformed names -- before any timing is believed.

## Corpora

`realistic`, `flat-many`, `deep-nest`, `attr-large`, `attr-braces`, `html-heavy`,
`comment-heavy`, `no-blocks`, `empty-objects`. Each isolates a different part of
the parser's cost so a change that helps one shape and hurts another shows up as
such instead of averaging into one number. See the header of `corpora.php`.
