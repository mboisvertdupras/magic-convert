# FormatProvider is one interface with a laziness invariant

The per-format adapter behind the encode seam (`FormatProvider`) serves two very different callers: planning code (ConcurrencyAdvisor, REST/CLI scheduling, config defaults) that must run without the vendor autoloader or WordPress, and encode-time code that needs the full converter stacks. We decided on a single interface covering both, governed by a hard laziness invariant — construction and fact methods (`converterIds()`, `optionDefaults()`, `memoryReserveBytes()`, `concurrencyWeight()`) must never touch vendor classes; only work methods (`encode()`, `encodeWith()`, `selfTest()`) may — rather than splitting into a facts interface and an encode interface with two registries.

## Considered Options

- **Two interfaces / two registries** (FormatProfile + FormatEncoder): cleaner on paper, but every format registers twice and must be kept in lock-step, and the facts half fails the deletion test — it would be a pass-through of constants.
- **Facts stay in ConcurrencyAdvisor**: smallest diff, but preserves the `$formatId === 'avif'` string forks that motivated the seam; a third format still edits the advisor.

## Consequences

- The laziness invariant is load-bearing, not stylistic: planning paths (including the WP-free wod scripts) construct providers freely, so a vendor `use` in a provider constructor is a production fatal on the serving path. It is pinned by a standalone regression test (run outside PHPUnit, whose bootstrap loads vendor/autoload and would mask the bug — same pattern as the AVIF autoloader regression).
- `OutputFormat` deliberately stays a pure value object (id/extension/mime/cacheDir) and must not grow an `encoder()` accessor; provider lookup goes through `ProviderRegistry` only.
- The registry is a hardcoded static map like `OutputFormat`'s — never filter-extensible, because the wod scripts must build it without WordPress.
