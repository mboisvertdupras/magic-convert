# Magic Convert

WordPress plugin that produces and serves next-gen image formats (WebP, AVIF) for browsers that support them. This glossary pins the format-pipeline language; architecture detail lives in CLAUDE.md and docs/.

## Language

### Formats

**Output format**:
A browser-facing image format the plugin can produce (webp, avif). Enabled per site; WebP is always on.
_Avoid_: image type, target format

**OutputFormat**:
The cheap, pure value object naming an output format's identity facts: id, extension, mime type, cache dir name. Safe to touch in request-time serving paths; never carries encoder machinery.
_Avoid_: format object, format descriptor

**FormatProvider**:
The per-format adapter behind the encode seam. One per output format. Seven vendor-free, WordPress-free fact methods (`id`, `converterIds`, `optionDefaults`, `memoryReserveBytes`, `concurrencyWeight`, `normalizeOptions`, `converterEntryFromConfig`) and four work methods that may load heavy code (`encode`, `encodeWith`, `selfTest`, `memorySafetyMode`). `selfTest()` returns config-independent capability rows, or `[]` when the format has no config-blind probe — WebP returns `[]`, its authoritative converter status staying with the config-aware test-run path.
_Avoid_: encoder (that's a converter's job), format handler, format service

**ProviderRegistry**:
The static, WordPress-free map from format id to FormatProvider. Hardcoded like the OutputFormat registry; never filter-extensible.
_Avoid_: provider factory

**Laziness invariant**:
The rule that constructing a FormatProvider and calling any of its seven fact methods (`id`, `converterIds`, `optionDefaults`, `memoryReserveBytes`, `concurrencyWeight`, `normalizeOptions`, `converterEntryFromConfig`) must work without the vendor autoloader or WordPress; only the four work methods (`encode`, `encodeWith`, `selfTest`, `memorySafetyMode`) may pull in heavy code.
_Avoid_: lazy loading (too generic)

### Conversion

**Converter**:
One concrete encoding method inside a provider's ordered stack (cwebp, vips, gd, avifenc, cavif, …). Tried in priority order until one succeeds.
_Avoid_: encoder backend, engine

**Converter id-space**:
Each FormatProvider owns its own converter id list. Ids may repeat across providers (vips exists in both) without being the same code path; an id is only meaningful next to its format.
_Avoid_: shared converter list

**FormatEncodeException**:
The seam-level failure contract of a provider's encode: one exception type carrying the human message plus per-converter failure reasons.
_Avoid_: conversion error (ambiguous with per-converter failures)

### Serving

**On-demand serving (wod)**:
Converting an image at first request time via the standalone, WordPress-free wod scripts. A format's participation is provider policy, not an accident of wiring; AVIF is deliberately gated out.
_Avoid_: live conversion, JIT conversion

**wod-options projection**:
The trimmed, hash-named JSON file derived from the canonical config; the only configuration the standalone serving scripts can read. A setting the projection omits does not exist at serving time.
_Avoid_: wod config (it is derived, never edited)
