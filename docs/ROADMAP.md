# Magic Convert — Fork Roadmap

Magic Convert is a fork of [WebP Express](https://github.com/rosell-dk/webp-express) (forked at v0.25.14, January 2026) with three headline goals:

1. **Parallelized bulk conversion** — make converting large media libraries (50k+ images) fast and safe.
2. **AVIF support** — multi-format output architecture (WebP + AVIF now, more formats later).
3. **Native nginx support** — generated, settings-aware nginx config with live self-testing, instead of hand-edited README templates.

Repository: `mboisvertdupras/magic-convert` · Plugin name: **Magic Convert** · Slug/text-domain: `magic-convert`

---

## Why these goals require a fork (key findings, June 2026)

- **Upstream will not deliver AVIF.** `rosell-dk/webp-convert` (the conversion engine, still maintained — 2.9.3, June 2026) is WebP-only by design; its AVIF issue ([#256](https://github.com/rosell-dk/webp-convert/issues/256)) has been open since 2020. The author's successor library [`rosell-dk/image-convert`](https://github.com/rosell-dk/image-convert) contains working AVIF converters but has zero releases, is documented "not production-ready", and has been dormant since April 2024. **Strategy: keep webp-convert untouched for WebP; port AVIF converters from image-convert as MIT donor code.**
- **`.webp` is hardcoded in ~177+ places**: `HTAccessRules.php` (~117), `ConvertHelperIndependent.php` (~40, incl. the `#\.webp$#` destination sanity check), the wod scripts, `CachePurge`/`CacheMover`, `Paths.php`, and the vendored `dom-util-for-webp` picture-tag library (`replaceUrl()` returns `$url . '.webp'` literally). Multi-format means a real `OutputFormat` abstraction, not find-and-replace.
- **Bulk conversion is strictly serial** (`bulk-convert.js` issues one admin-ajax request per file from the success callback) and **the core is not concurrency-safe**: no file locking anywhere; only the `auto` encoding path writes atomically (`EncodingAutoTrait` temp+rename); config saves are not atomic.
- **AVIF encodes ~5–47× slower than WebP** (1–4 s for 1080p, 5–30 s for 4K, ~2.5 GB peak RAM with libaom). On-demand first-request conversion is therefore dangerous for AVIF; AVIF belongs in parallel bulk conversion, with on-demand gated. This is why parallelization (Phase 1) lands before AVIF (Phase 2).
- **The nginx gap is a market opening.** No mainstream plugin generates a per-site, hash-filled, settings-aware nginx include. WebP Express makes nginx users hand-edit README templates (including manually pasting a 32-hex security hash) and nags them to re-edit when settings change. The existing `SelfTest*` classes already implement most of what a live nginx test needs.

---

## Phase 0 — Fork mechanics

**0.1 Rebrand.**
- Plugin header: name **Magic Convert**, main file `magic-convert.php` (renamed from `webp-express.php`), text domain `magic-convert`, author/URI updated, version reset to `0.1.0`.
- PHP namespace `WebPExpress` → `MagicConvert` across all of `lib/`, `wod/`, `wod2/`, `web-service/` (70 classes + bootstrap files). Vendor code untouched.
- Constants `WEBPEXPRESS_*` → `MAGIC_CONVERT_*`.
- Brand strings: `webp-express` → `magic-convert`, `WebP Express` → `Magic Convert`, `webpexpress` → `magicconvert` (option keys, AJAX action names, JS globals, CSS handles, nonce names, hook names, the `wp-content/webp-express/` content dir → `wp-content/magic-convert/`).
  ⚠️ Only **brand** references are renamed. The string `webp` alone (format name, `.webp` extension, `image/webp` mime, `webp-images` cache dir, webp-convert library calls) stays.
- `composer.json` name → `mboisvertdupras/magic-convert`; add `"php": ">=8.1"` requirement.
- README.txt / README.md rewritten headers crediting upstream (both remain MIT/GPL-compatible; preserve original copyright notices).

**0.2 Floors & scaffolding.**
- Requires PHP: **8.1** (needed for GD `imageavif()`; sheds PHP 5.6 compat). Requires at least: WordPress **5.9**.
- PHPUnit (composer dev dependency) with a `tests/` suite for WordPress-independent classes; GitHub Actions CI: `php -l` sweep, `composer validate`, PHPUnit on PHP 8.1/8.3.
- No mass syntax modernization in this phase — floor raise only.

## Phase 1 — Concurrency hardening + parallel bulk

**1.1 Make the conversion core safe for multiple writers** (prerequisite for everything).
- New `FileLock` class: acquire via `fopen($dest . '.lock', 'x')` (O_EXCL, atomic; spans FPM requests *and* CLI processes — `flock()` alone cannot), containing pid+timestamp; stale locks (>10 min) are stolen; "in progress" status when held.
- Atomic destination writes for **all** encode paths at the plugin level: convert to `<dest>.<uniqid>.tmp.webp` (suffix keeps webp-convert's `#\.webp$#` destination validation happy), then `rename()` into place.
- Idempotency check inside the lock (source mtime vs destination), making every retry safe.
- Atomic config saves (temp+rename) in `Config::saveConfigFile`.

**1.2 Parallel bulk in the admin UI.**
- New REST controller `magic-convert/v1`: `POST /convert` (one file), `GET /unconverted` (paged listing, ~500 paths/page — replaces the single 50k-entry JSON blob), `permission_callback` = `manage_options`, `X-WP-Nonce` auth.
- `bulk-convert.js` rewritten as a fixed-size promise pool: default **N=3**, UI slider 1–6, auto-throttle (halve N) on timeout/429/5xx with exponential backoff, rolling nonce refresh preserved. Guidance copy: N ≤ half of `pm.max_children`, N ≤ cores.

**1.3 WP-CLI sharding for the 50k+ crowd.**
- `wp magic-convert convert --shard=<i>/<n>` (stable partition: `crc32(path) % n`).
- `--procs=<n>`: parent builds the list once, `proc_open()`s n self-invocations (shards), streams aggregated progress. Coarse shards only — one WP bootstrap per thousands of files (per-image spawning measured ~178% slower than sequential). Default procs = cores − 1.
- No Action Scheduler, no pcntl-in-web-requests (unavailable under FPM; AS's own docs point to WP-CLI for throughput).

## Phase 2 — Multi-format core + AVIF

**2.1 `OutputFormat` abstraction.** Value class (`id`, `ext`, `mime`, `cacheDirName`, option defaults) threaded through `ConvertHelperIndependent::getDestination()` / `appendOrSetExtension()` / `convert()`, `Destination`, log paths (`/log/conversions/<format>/…`), bigger-than-source markers, `CachePurge`/`CacheMover`. Cache dirs: `webp-images/` stays (compat), `avif-images/` added in parallel.

**2.2 Config schema v2.** Add explicit `config-version`; per-format sections (`formats.webp`, `formats.avif`: enabled, quality, speed/effort, converter stack, scope override); migration from v1; options UI for AVIF (enable toggle, quality, speed); `generateWodOptionsFromConfigObj()` extended with per-format blocks.

**2.3 AVIF converter stack** (ported from image-convert, plugin-namespace `MagicConvert\Convert\Avif`):
- Order: Imagick (runtime detect `queryFormats('AVIF')`, expose `heic:speed`) → vips (`heifsave`, `Q`/`effort`) → GD `imageavif()` (guard `function_exists` + `gd_info()['AVIF Support']`) → exec `avifenc`/`cavif` (reuse the existing supplied-binary + hash-check + `ExecWithFallback` machinery).
- Defaults: **quality 30** (document "AVIF Q30 ≈ JPEG Q75"), **speed/effort 6–7**, 4:2:0 chroma. Implement the speed option image-convert left commented out.
- AVIF is **bulk-only by default**; on-demand AVIF gated behind megapixel cap (~2 MP) + fast speed.

**2.4 Serving + HTML for two formats.**
- `.htaccess` rules: `image/avif` Accept chain ordered before webp (avif → webp → original), `AddType image/avif .avif`.
- Picture-tag generation: fork the two small `dom-util-for-webp` classes into `lib/` and generalize — emit `<source type="image/avif">` + `<source type="image/webp">` + original `<img>`, in browser-preference order.
- wod scripts redirect-to-existing for AVIF; conversion fallback remains WebP-only by default.

**2.5 Self-test + bulk UI.** AVIF capability self-test page (PHP version, `imageavif`, `queryFormats('AVIF')`, vips/FFI, avifenc/cavif in PATH, exec availability — detection failures are the dominant support generator). Bulk UI gets per-format selection and per-format progress/results.

## Phase 3 — Native nginx

**3.1 Config generator** (`NginxRules` mirroring `HTAccessRules`), producing two artifacts from one template model:
- **Artifact A** (preferred): `magic-convert-maps.conf` (http context: `map $http_accept` → `$avif_suffix`/`$webp_suffix`) + `magic-convert-server.conf` (server context: location blocks, `try_files $uri$avif_suffix $uri$webp_suffix … @fallback`, `Vary: Accept`, `types{}` block to sidestep mime.types editing).
- **Artifact B**: single server-context-only file using `set`+`if` (safe because `set` works in `if`) for panel hosts (GridPane/RunCloud/Plesk) whose include slot can't hold `map`.
- Auto-filled: config hash, wp-content relative path, cache-root prefixes, extension mode (append/set), enabled image types, converter/realizer fallback to the wod scripts (try_files final-URI form by default; documented `@named-location` hardened variant).
- Version stamp embedded in the generated conf (`location = /magic-convert-rules-version { return 200 "<n>"; }`) for drift detection.

**3.2 Admin UI.** When `PlatformInfo::isNginx()`: nginx tab with Download/Copy for both artifacts, environment-specific install instructions (vanilla VPS, Docker compose mount, panel include slots, no-access hosts → Alter HTML mode), and a "Run live test" button.

**3.3 Self-test extension** built on `SelfTestRedirectToExisting`/`SelfTestHelper`: AVIF dummy + `Accept: image/avif` assertion, preference test (`Accept: image/avif,image/webp` must return avif), content-length fingerprinting for "rules active but mime missing", `Vary: Accept` verification with CDN guidance (Cloudflare free ignores Vary; CloudFront needs Accept whitelisted), and generated-vs-installed rules-version drift notice replacing the blind nag.

---

## Working agreements

- Commit at every step; keep `upstream` remote (`rosell-dk/webp-express`) for pulling security fixes (especially webp-convert bumps).
- `vendor/rosell-dk/*` is never modified in place; generalizations happen at the plugin layer or in forked-into-`lib/` copies.
- Every new WordPress-independent class gets PHPUnit coverage.
- Security posture inherited from upstream is preserved: 32-hex config hash, `SanityCheck` path containment, `x`-prefix source params, source-exists check before conversion (CVE-2019-15330 is the cautionary tale).
