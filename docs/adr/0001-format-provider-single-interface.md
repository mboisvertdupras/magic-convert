# FormatProvider is one interface with a laziness invariant

The per-format adapter behind the encode seam (`FormatProvider`) serves two very different callers: planning code (ConcurrencyAdvisor, REST/CLI scheduling, config defaults) that must run without the vendor autoloader or WordPress, and encode-time code that needs the full converter stacks. We decided on a single interface covering both, governed by a hard laziness invariant — construction and the seven fact methods (`id()`, `converterIds()`, `optionDefaults()`, `memoryReserveBytes()`, `concurrencyWeight()`, `normalizeOptions()`, `converterEntryFromConfig()`) must never touch vendor classes or WordPress; only the four work methods (`encode()`, `encodeWith()`, `selfTest()`, `memorySafetyMode()`) may — rather than splitting into a facts interface and an encode interface with two registries.

## Considered Options

- **Two interfaces / two registries** (FormatProfile + FormatEncoder): cleaner on paper, but every format registers twice and must be kept in lock-step, and the facts half fails the deletion test — it would be a pass-through of constants.
- **Facts stay in ConcurrencyAdvisor**: smallest diff, but preserves the `$formatId === 'avif'` string forks that motivated the seam; a third format still edits the advisor.

## Consequences

- The laziness invariant is load-bearing, not stylistic: planning paths (including the WP-free wod scripts) construct providers freely, so a vendor `use` in a provider constructor is a production fatal on the serving path. It is pinned by a standalone regression test (run outside PHPUnit, whose bootstrap loads vendor/autoload and would mask the bug — same pattern as the AVIF autoloader regression).
- `OutputFormat` deliberately stays a pure value object (id/extension/mime/cacheDir) and must not grow an `encoder()` accessor; provider lookup goes through `ProviderRegistry` only.
- The registry is a hardcoded static map like `OutputFormat`'s — never filter-extensible, because the wod scripts must build it without WordPress.
- `WebPProvider::selfTest()` returns `[]` by design: a config-blind WebP probe would create a second source of truth diverging from `TestRun`'s configured trial conversions, so WebP's authoritative converter status stays with that config-aware path. Provider encode paths (`encode()`/`encodeWith()`) deliberately carry no belt-and-braces autoloader guard of their own — the `ConvertHelperIndependent::convert()`/`serveConverted()` chokepoint having loaded the autoloader is the guarantee.
