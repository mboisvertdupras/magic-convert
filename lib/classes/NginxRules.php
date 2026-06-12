<?php

namespace MagicConvert;

/**
 * NginxRules — generates settings-aware nginx config artifacts (roadmap Phase 3.1).
 *
 * This is the nginx counterpart of HTAccessRules. Where Apache reads a per-directory
 * .htaccess that the plugin physically writes into the docroot, nginx reads operator-
 * installed include files. nginx does NOT honour .htaccess, so the security protections
 * that gate the wod converter scripts on Apache do not exist here.
 *
 * SECURITY CONSTRAINT (critical): the generated rules embed the 32-hex config hash that
 * WodConfigLoader requires to run the converter endpoints. Leaking that hash weakens the
 * CVE-2019-15330 defense layers. Therefore these artifacts MUST NEVER be written to a
 * web-accessible location. This class deliberately has NO disk-writing method: artifacts
 * are produced in memory for display and streamed only via authenticated admin endpoints.
 * The only thing persisted (by the caller) is a non-secret { fingerprint, generated-at,
 * plugin-version } triple — never the rule body.
 *
 * PURITY: the generation core takes a config array PLUS an explicit $env array (wp-content
 * rel path, config hash, wod url path, cache-root rel path, etc.) and returns a string. It
 * makes NO calls to the filesystem, to WordPress, or to Paths/Config. That makes every
 * matrix point unit-testable without bootstrapping WordPress. The thin wrappers
 * environmentFromPaths() / generate*FromPaths() gather the env from Paths and delegate.
 *
 * TWO ARTIFACTS (one template model):
 *   - Artifact A (preferred): a maps file (http context) + a server file (server context).
 *     The maps file declares `map $http_accept $mc_*_suffix`. The server file uses those
 *     map variables in try_files. This is the cleanest, fastest shape but needs an include
 *     slot in the http context (for the maps).
 *   - Artifact B (fallback): a single server-context-only file that replaces the maps with
 *     the `set`+`if` pattern (`set` is one of the few directives that behaves correctly
 *     inside `if`), for panel hosts (GridPane/RunCloud/Plesk) whose include slot can only
 *     reach the server context.
 *
 * Both artifacts carry a header comment block (generated-date, plugin version, settings
 * fingerprint) and a rules-version marker location so drift is detectable over HTTP.
 */
class NginxRules
{
    /**
     * The config keys that actually affect the generated rule body. Documented here as the
     * single source of truth for the settings fingerprint (see settingsFingerprint()).
     *
     * Anything NOT in this list (e.g. converter stack order, quality/speed, cache-control
     * cosmetics that nginx serves via its own `expires`, UI-only flags) does NOT change the
     * rules and therefore does NOT change the fingerprint.
     *
     * @var string[]
     */
    /**
     * Sentinel suffixes used when a format is NOT accepted by the browser. They MUST be
     * non-empty and MUST NOT match any real cache/sibling filename — otherwise an unsupported
     * format's try entry ($uri + suffix) would collapse to "$uri" and wrongly serve the
     * ORIGINAL image before the supported format's entry is reached. With a guaranteed-miss
     * sentinel, an unsupported format's entries are skipped cleanly and try_files falls
     * through to the next format / the original.
     */
    const AVIF_NO_MATCH_SENTINEL = '.mc-no-avif';
    const WEBP_NO_MATCH_SENTINEL = '.mc-no-webp';

    /**
     * Rule-affecting config defaults. SINGLE SOURCE OF TRUTH: both model() (which bakes the
     * fingerprint into the generated file) and settingsFingerprint() (the public/standalone
     * fingerprint persisted to State and compared by the Phase 3.3 drift detector) MUST merge
     * these defaults BEFORE fingerprinting. If they diverge, a partial config (one missing any
     * of the fingerprintConfigKeys) produces a file-embedded fingerprint that differs from the
     * persisted/standalone one, and the drift detector reports a FALSE mismatch.
     *
     * @return array
     */
    private static function ruleDefaults()
    {
        return [
            'destination-folder' => 'separate',
            'destination-extension' => 'append',
            'destination-structure' => 'doc-root',
            'image-types' => 3,
            'scope' => ['themes', 'uploads'],
            'redirect-to-existing-in-htaccess' => true,
            'enable-redirection-to-converter' => true,
            'only-redirect-to-converter-for-webp-enabled-browsers' => true,
        ];
    }

    private static $fingerprintConfigKeys = [
        'destination-folder',        // mingled | separate
        'destination-extension',     // append | set
        'destination-structure',     // doc-root | image-roots
        'image-types',               // 1=jpeg, 2=png, 3=both
        'scope',                     // ['themes','uploads',...]  -> location scope
        'redirect-to-existing-in-htaccess',   // emit redirect-to-existing try entries
        'enable-redirection-to-converter',    // emit the converter fallback try entry
        'only-redirect-to-converter-for-webp-enabled-browsers', // (informational; affects comments)
    ];

    // =====================================================================================
    //  PUBLIC GENERATION CORE (pure: config + env in, string out)
    // =====================================================================================

    /**
     * Artifact A, file 1 — the http-context maps file (magic-convert-maps.conf).
     *
     * Declares the Accept->suffix maps used by the server file. The avif map is only emitted
     * when formats.avif.enabled (avif is redirect-to-existing only; no on-demand avif route).
     *
     * @param  array  $config
     * @param  array  $env     gathered environment (see environmentFromPaths()).
     * @return string
     */
    public static function generateMapsFile($config, $env)
    {
        $m = self::model($config, $env);

        $out  = self::headerComment('magic-convert-maps.conf (http context)', $m);
        $out .= "# Place this file's include in the HTTP context (e.g. inside the top-level http { } block,\n";
        $out .= "# often via /etc/nginx/conf.d/ or a snippets include). It declares the Accept-negotiation\n";
        $out .= "# maps consumed by magic-convert-server.conf. Maps are NOT allowed in the server context;\n";
        $out .= "# if your include slot can only reach server{}, use the single-file artifact instead.\n";
        $out .= "\n";

        $out .= "# The 'default' value is a guaranteed-miss sentinel (NOT empty): when a format is not\n";
        $out .= "# accepted, its try_files entry resolves to a non-existent path and is skipped cleanly,\n";
        $out .= "# instead of collapsing to the original image and short-circuiting negotiation.\n";
        if ($m['avif']) {
            $out .= "# AVIF is preferred over WebP: a browser that sends 'image/avif' in Accept gets the\n";
            $out .= "# '.avif' suffix tried first (redirect-to-existing only — there is no on-demand avif).\n";
            $out .= "map \$http_accept \$mc_avif_suffix {\n";
            $out .= "    default        \"" . self::AVIF_NO_MATCH_SENTINEL . "\";\n";
            $out .= "    \"~*image/avif\" \".avif\";\n";
            $out .= "}\n";
        }
        $out .= "map \$http_accept \$mc_webp_suffix {\n";
        $out .= "    default        \"" . self::WEBP_NO_MATCH_SENTINEL . "\";\n";
        $out .= "    \"~*image/webp\" \".webp\";\n";
        $out .= "}\n";

        return $out;
    }

    /**
     * Artifact A, file 2 — the server-context locations file (magic-convert-server.conf).
     *
     * Uses the map variables ($mc_avif_suffix / $mc_webp_suffix) from the maps file.
     *
     * @param  array  $config
     * @param  array  $env
     * @return string
     */
    public static function generateServerFile($config, $env)
    {
        $m = self::model($config, $env);

        $out  = self::headerComment('magic-convert-server.conf (server context)', $m);
        $out .= "# Place this file's include in the SERVER context (inside server { } of your site,\n";
        $out .= "# usually under /etc/nginx/sites-available/). It REQUIRES magic-convert-maps.conf to be\n";
        $out .= "# included in the http context. If you cannot include a maps file, use the single-file\n";
        $out .= "# artifact (it inlines the negotiation with set/if instead).\n";
        $out .= "\n";
        $out .= self::sourceLocationBlock($m, /* useMaps */ true);
        $out .= "\n";
        $out .= self::realizerLocationBlock($m);
        $out .= "\n";
        $out .= self::rulesVersionMarker($m['fingerprint']);
        return $out;
    }

    /**
     * Artifact B — single server-context-only file (magic-convert.conf).
     *
     * Same locations as Artifact A's server file, but the Accept->suffix decision is inlined
     * with the `set`+`if` pattern instead of map variables, so no http-context include is
     * needed. Safe because `set` is one of the directives that works correctly inside `if`.
     *
     * @param  array  $config
     * @param  array  $env
     * @return string
     */
    public static function generateSingleFile($config, $env)
    {
        $m = self::model($config, $env);

        $out  = self::headerComment('magic-convert.conf (single file, server context only)', $m);
        $out .= "# Single-file variant for panel hosts (GridPane / RunCloud / Plesk) whose include slot\n";
        $out .= "# can only reach the server context. It needs NO http-context maps: a 'decider'\n";
        $out .= "# location resolves the Accept -> suffix with set/if and hands off (via error_page\n";
        $out .= "# 418) to an internal '\@mc_negotiate' location that runs the real try_files. The\n";
        $out .= "# try_files is deliberately NOT in the same location as the 'if' — that would trip\n";
        $out .= "# nginx's 'if inside location is evil' and silently serve the original image.\n";
        $out .= "\n";
        $out .= self::sourceLocationBlock($m, /* useMaps */ false);
        $out .= "\n";
        $out .= self::realizerLocationBlock($m);
        $out .= "\n";
        $out .= self::rulesVersionMarker($m['fingerprint']);
        return $out;
    }

    /**
     * Stable fingerprint over the rule-affecting config subset (documented in
     * $fingerprintConfigKeys) PLUS the rule-affecting environment (wp-content rel path and
     * the config hash, since both appear literally in the converter try-entry). Changing an
     * irrelevant config value (quality, converter order, UI cosmetics) does NOT change it;
     * changing destination options / image types / scope / redirect options / avif-enabled /
     * hash / wp-content path DOES.
     *
     * @param  array  $config
     * @param  array  $env
     * @return string  32-hex md5.
     */
    public static function settingsFingerprint($config, $env)
    {
        // Merge the rule defaults FIRST, exactly as model() does before it bakes the fingerprint
        // into the generated file. Without this, a partial config (missing any fingerprintConfigKey)
        // would fingerprint here against null but in model() against the default, so the
        // file-embedded fingerprint and this standalone/persisted one would diverge and the drift
        // detector would report a false mismatch. See ruleDefaults() for the rationale.
        $config = array_merge(self::ruleDefaults(), $config);

        $subset = [];
        foreach (self::$fingerprintConfigKeys as $key) {
            $subset[$key] = isset($config[$key]) ? $config[$key] : null;
        }
        // avif enabled lives under formats.avif.enabled (config v2). Read defensively so a v1
        // config fingerprints identically to avif-disabled.
        $subset['avif-enabled'] = (
            isset($config['formats']['avif']['enabled']) &&
            $config['formats']['avif']['enabled'] === true
        );
        // Environment inputs that appear in the rule body.
        $subset['wp-content-rel'] = isset($env['wpContentRelPath']) ? $env['wpContentRelPath'] : null;
        $subset['hash'] = isset($env['configHash']) ? $env['configHash'] : null;
        $subset['cache-root-rel'] = isset($env['cacheRootRelToDocRoot']) ? $env['cacheRootRelToDocRoot'] : null;
        $subset['wod-url-path'] = isset($env['wodUrlPath']) ? $env['wodUrlPath'] : null;
        $subset['realizer-url-path'] = isset($env['realizerUrlPath']) ? $env['realizerUrlPath'] : null;

        // In image-roots mode the per-root URL paths (and ids) appear literally in the generated
        // location regexes / cache paths, so they are rule-affecting and must change the fingerprint
        // (drift detection). They do NOT affect doc-root rules, so they are folded in ONLY for
        // image-roots — a doc-root config's fingerprint stays independent of image-root URL paths.
        if (($subset['destination-structure'] ?? null) === 'image-roots'
            && isset($env['imageRoots']) && is_array($env['imageRoots'])) {
            $rootsSubset = [];
            foreach ($env['imageRoots'] as $r) {
                if (isset($r['id']) && isset($r['urlPath'])) {
                    $rootsSubset[] = ['id' => $r['id'], 'urlPath' => trim($r['urlPath'], '/')];
                }
            }
            $subset['image-roots'] = $rootsSubset;
        }

        // Sort keys so insertion order can't perturb the hash.
        ksort($subset);
        return md5(json_encode($subset));
    }

    /**
     * The rules-version marker location. Drift detection (Phase 3.3) does an HTTP GET of
     * /magic-convert-rules-version and compares the body to the stored fingerprint.
     *
     * @param  string  $fingerprint
     * @return string
     */
    public static function rulesVersionMarker($fingerprint)
    {
        return
            "# Rules-version marker — lets the plugin detect over HTTP whether the installed rules\n" .
            "# match the current settings (drift detection). Returns the settings fingerprint.\n" .
            "location = /magic-convert-rules-version {\n" .
            "    default_type text/plain;\n" .
            "    return 200 \"" . $fingerprint . "\";\n" .
            "}\n";
    }

    // =====================================================================================
    //  RULE-BODY BUILDERS (pure helpers, operate on the prepared $m model)
    // =====================================================================================

    /**
     * The location block that handles requests for SOURCE images (jpg/png). This is the heart
     * of the negotiation: try the avif cache, then the webp cache, then mingled siblings, then
     * the original, then (if enabled) the converter.
     *
     * Artifact A (useMaps) emits a SINGLE location: the suffix variables come from http-context
     * maps, so the location body is just headers + types + try_files.
     *
     * Artifact B (!useMaps) CANNOT use maps. The naive shape — `set`+`if` then `try_files` in the
     * SAME location — is broken by nginx's "if inside location is evil": the `if {}` builds an
     * implicit nested location, so the outer `try_files` never runs and the request falls through
     * to `$uri` (the original) regardless of Accept. (Validated by `nginx -t` yet non-functional
     * at runtime — exactly how it slipped through.) The fix is a two-location indirection: a
     * "decider" location does ONLY the `set`+`if` and then hands off via `error_page 418 =
     * @mc_negotiate; return 418;`, and a separate `@mc_negotiate` named location carries the
     * headers/types and the real `try_files`. Because the `try_files` no longer shares a location
     * with any `if`, it runs correctly, and the `set` suffix variables propagate into the named
     * location. This serves avif/webp/original by Accept exactly like Artifact A.
     *
     * @param  array  $m         prepared model.
     * @param  bool   $useMaps   true => use $mc_avif_suffix/$mc_webp_suffix maps (Artifact A);
     *                           false => decider + @mc_negotiate named location (Artifact B).
     * @return string
     */
    private static function sourceLocationBlock($m, $useMaps)
    {
        $out  = "# Handle requests for source images (" . $m['typesHuman'] . ").\n";
        $out .= "# WARNING: 'add_header' inside a location REPLACES any add_header directives inherited\n";
        $out .= "# from the surrounding server/http context. If you rely on inherited headers (HSTS,\n";
        $out .= "# X-Frame-Options, CSP, ...), RE-DECLARE them inside this location (or @mc_negotiate*)\n";
        $out .= "# or they will be dropped for these image responses.\n";

        if (!empty($m['unsupportedNote'])) {
            $out .= $m['unsupportedNote'];
        }

        // IMAGE-ROOTS: one self-contained location per enabled image root, mapping the root-relative
        // remainder into that root's cache subtree. (Doc-root mode falls through to the single block.)
        if ($m['imageRootsMode']) {
            if (!empty($m['imageRootsNote'])) {
                $out .= $m['imageRootsNote'];
            }
            $out .= "# destination-structure = 'image-roots': converted files live under a per-root\n";
            $out .= "# cache subtree (<cache>/<fmt>-images/<rootId>/<relWithinRoot>), so each image root\n";
            $out .= "# gets its own location capturing the root-relative remainder.\n";
            $blocks = [];
            foreach ($m['imageRoots'] as $rootCtx) {
                $blocks[] = self::imageRootLocationBlock($m, $rootCtx, $useMaps);
            }
            return $out . implode("\n", $blocks);
        }

        if ($useMaps) {
            // Artifact A: one location; suffix variables come from the http-context maps.
            $out .= "location ~* " . $m['locationRegex'] . " {\n";
            $out .= self::negotiationBody($m, 4);
            $out .= "}\n";
            return $out;
        }

        // Artifact B: decider location (set/if ONLY) -> @mc_negotiate (headers/types/try_files).
        // Do NOT put try_files in this location: an `if {}` here creates an implicit nested
        // location that would swallow it. We hand off with the 418 / error_page pattern instead.
        $out .= "location ~* " . $m['locationRegex'] . " {\n";
        $out .= self::deciderBody($m, '@mc_negotiate', 4);
        $out .= "}\n";
        $out .= "\n";
        $out .= "# Negotiation target for the single-file artifact. Carries the headers/types and the\n";
        $out .= "# real try_files; reached only via the decider above (it is internal-only).\n";
        $out .= "location @mc_negotiate {\n";
        $out .= "    internal;\n";
        $out .= self::negotiationBody($m, 4);
        $out .= "}\n";
        return $out;
    }

    /**
     * One image-root location block (Artifact A: single location using map vars; Artifact B:
     * decider + per-root @mc_negotiate_<rootId> named location). The root-relative remainder is a
     * NAMED capture in the location regex (positional $1 is unreliable in try_files), and the
     * cache lookups map that capture into the root's cache subtree.
     *
     * @param  array  $m
     * @param  array  $rootCtx
     * @param  bool   $useMaps
     * @return string
     */
    private static function imageRootLocationBlock($m, $rootCtx, $useMaps)
    {
        $out = "# image root '" . $rootCtx['id'] . "' (url path: /" . $rootCtx['urlPath'] . ")\n";
        if (!empty($rootCtx['note'])) {
            $out .= $rootCtx['note'];
        }

        if ($useMaps) {
            // Artifact A: suffix variables come from the http-context maps.
            $out .= "location ~* " . $rootCtx['locationRegex'] . " {\n";
            $out .= self::negotiationBody($m, 4, $rootCtx);
            $out .= "}\n";
            return $out;
        }

        // Artifact B: decider (set/if ONLY) -> per-root @mc_negotiate_<rootId>. A SHARED named
        // location can't carry per-root captures, so each root gets its own negotiate target.
        $negotiateName = $rootCtx['negotiateName'];
        $out .= "location ~* " . $rootCtx['locationRegex'] . " {\n";
        $out .= self::deciderBody($m, $negotiateName, 4);
        $out .= "}\n";
        $out .= "\n";
        $out .= "location " . $negotiateName . " {\n";
        $out .= "    internal;\n";
        $out .= self::negotiationBody($m, 4, $rootCtx);
        $out .= "}\n";
        return $out;
    }

    /**
     * The Artifact-B "decider" body: set/if to resolve the Accept->suffix variables, then hand off
     * via error_page 418 to the given named location. Shared by the doc-root single location and
     * every per-image-root location so the "if inside location is evil" avoidance is identical.
     *
     * @param  array   $m
     * @param  string  $negotiateName  the @named location to hand off to.
     * @param  int     $indent
     * @return string
     */
    private static function deciderBody($m, $negotiateName, $indent)
    {
        $pad = str_repeat(' ', $indent);
        $out  = $pad . "# Decide the negotiated suffix(es) here, then hand off to " . $negotiateName . ". The\n";
        $out .= $pad . "# initial 'set' is a guaranteed-miss sentinel (NOT empty): an unsupported format\n";
        $out .= $pad . "# must skip its try entry, not collapse to \$uri and serve the original.\n";
        $out .= $pad . "# IMPORTANT: try_files MUST NOT live in this location — the 'if' below builds an\n";
        $out .= $pad . "# implicit nested location ('if inside location is evil') that would swallow it.\n";
        if ($m['avif']) {
            $out .= $pad . "set \$mc_avif_suffix \"" . self::AVIF_NO_MATCH_SENTINEL . "\";\n";
            $out .= $pad . "if (\$http_accept ~* \"image/avif\") { set \$mc_avif_suffix \".avif\"; }\n";
        }
        $out .= $pad . "set \$mc_webp_suffix \"" . self::WEBP_NO_MATCH_SENTINEL . "\";\n";
        $out .= $pad . "if (\$http_accept ~* \"image/webp\") { set \$mc_webp_suffix \".webp\"; }\n";
        $out .= $pad . "error_page 418 = " . $negotiateName . ";\n";
        $out .= $pad . "return 418;\n";
        return $out;
    }

    /**
     * The shared negotiation body (Vary + expires + types + try_files chain), used by BOTH
     * Artifact A's source location and Artifact B's @mc_negotiate named location so the served
     * behaviour is identical.
     *
     * @param  array       $m
     * @param  int         $indent
     * @param  array|null  $rootCtx  per-root descriptor (image-roots) or null (doc-root).
     * @return string
     */
    private static function negotiationBody($m, $indent, $rootCtx = null)
    {
        $pad = str_repeat(' ', $indent);
        $out  = $pad . "add_header Vary Accept;\n";
        $out .= $pad . "expires 365d;\n";
        $out .= self::typesBlock($m, $indent);

        // try_files chain.
        $out .= $pad . "try_files\n";
        foreach (self::tryEntries($m, $rootCtx) as $entry) {
            $out .= $pad . "    " . $entry . "\n";
        }
        $out .= $pad . "    ;\n";
        return $out;
    }

    /**
     * The try_files entries, in priority order. AVIF entries (when enabled) precede WebP
     * entries. Each cache lookup uses the negotiated suffix variable so that, for a browser
     * that does not accept the format, the variable is "" and the entry collapses to a path
     * that won't exist with an empty suffix — nginx simply moves to the next entry.
     *
     * Order:
     *   1. avif cache (doc-root structured) — only if avif enabled AND redirect-to-existing
     *   2. avif sibling (mingled)           — only if avif enabled AND mingled AND redirect-to-existing
     *   3. webp cache (doc-root structured) — only if redirect-to-existing
     *   4. webp sibling (mingled)           — only if redirect-to-existing
     *   5. $uri (original)
     *   6. converter fallback (webp only)   — only if redirect-to-converter enabled
     *   7. =404 terminal                    — only when 6 is absent (see below)
     *
     * redirect-to-existing gating (mirrors HTAccessRules::redirectToExisting* gating on the
     * 'redirect-to-existing-in-htaccess' flag): when that flag is OFF, the avif/webp cache and
     * sibling lookups are NOT emitted, so pre-existing .webp/.avif are NOT served from cache —
     * exactly as .htaccess would behave. Only $uri (and, if enabled, the converter) remain.
     *
     * Terminal-entry invariant (critical): nginx treats the LAST try_files argument as an
     * internal-redirect/fallback TARGET, not a file to serve. If $uri were last, a request for a
     * missing/own file would re-enter this same location and trigger a "rewrite or internal
     * redirection cycle" (HTTP 500), or stall the single-file 418/@mc_negotiate handoff. When the
     * converter fallback (a real URI target) is present it safely terminates the chain. When it is
     * absent (redirect-to-converter OFF) we MUST append a non-redirecting terminal — '=404' — so
     * $uri stays a file-to-serve and a genuine miss returns a clean 404 instead of looping.
     *
     * IMAGE-ROOTS: when $rootCtx is supplied (a per-root descriptor from $m['imageRoots']) the
     * cache/sibling lookups come from that root's own closures and gating (root-relative capture
     * into the per-root cache subtree, or the in-place sibling for mingled-uploads), instead of
     * the doc-root $uri-based expressions. $uri and the converter terminal are unchanged.
     *
     * @param  array       $m
     * @param  array|null  $rootCtx  per-root descriptor (image-roots), or null for doc-root.
     * @return string[]
     */
    private static function tryEntries($m, $rootCtx = null)
    {
        $entries = [];

        // ---- AVIF (redirect-to-existing only) ----
        // Gated on redirect-to-existing, mirroring HTAccessRules: with the flag off these cache/
        // sibling lookups are omitted and pre-existing converted files are NOT served.
        if ($m['redirectToExisting']) {
            if ($rootCtx !== null) {
                // image-roots: per-root cache subtree and/or in-place sibling.
                if ($m['avif']) {
                    if ($rootCtx['useCache']) {
                        $entries[] = $rootCtx['cacheExpr']('avif', '$mc_avif_suffix');
                    }
                    if ($rootCtx['useSibling']) {
                        $entries[] = $rootCtx['siblingExpr']('$mc_avif_suffix');
                    }
                }
                if ($rootCtx['useCache']) {
                    $entries[] = $rootCtx['cacheExpr']('webp', '$mc_webp_suffix');
                }
                if ($rootCtx['useSibling']) {
                    $entries[] = $rootCtx['siblingExpr']('$mc_webp_suffix');
                }
            } else {
                // doc-root: single mirror keyed by full $uri.
                if ($m['avif']) {
                    if ($m['cacheDocRoot']) {
                        $entries[] = $m['cacheUriExpr']('avif', '$mc_avif_suffix');
                    }
                    if ($m['mingled']) {
                        // mingled sibling in the same dir as the source (uploads only — see note)
                        $entries[] = $m['siblingUriExpr']('$mc_avif_suffix');
                    }
                }

                // ---- WebP ----
                if ($m['cacheDocRoot']) {
                    $entries[] = $m['cacheUriExpr']('webp', '$mc_webp_suffix');
                }
                if ($m['mingled']) {
                    $entries[] = $m['siblingUriExpr']('$mc_webp_suffix');
                }
            }
        }

        // ---- Original ----
        $entries[] = '$uri';

        // ---- Converter fallback (webp only, gated) ----
        if ($m['redirectToConverter']) {
            $entries[] = $m['converterUri'];
            return $entries;
        }

        // No converter target to terminate the chain: $uri would otherwise be the LAST arg and
        // nginx would treat it as a redirect target -> rewrite/redirect cycle. Append a
        // non-redirecting terminal so $uri stays a file-to-serve and a real miss is a clean 404.
        $entries[] = '=404';

        return $entries;
    }

    /**
     * The location that realizes a missing .webp on direct request (mirrors the README's
     * webp-realizer recipe). Only emitted when redirect-to-converter is enabled (the realizer
     * is the converter's companion: it materializes the .webp the converter would have made).
     *
     * @param  array  $m
     * @return string
     */
    private static function realizerLocationBlock($m)
    {
        if (!$m['redirectToConverter']) {
            return "# (Converter/realizer routes omitted — redirect-to-converter is disabled.)\n";
        }
        $out  = "# Route direct requests for a missing .webp to the realizer, which converts the\n";
        $out .= "# corresponding source and serves the fresh .webp. (Mirrors the manual README recipe.)\n";
        $out .= "location ~* " . $m['realizerLocationRegex'] . " {\n";
        $out .= "    try_files\n";
        $out .= "        \$uri\n";
        $out .= "        " . $m['realizerUri'] . "\n";
        $out .= "        ;\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * The types{} block — sidesteps mime.types editing entirely by declaring the mime types
     * locally. avif/webp lines are conditional; jpeg/png always present.
     *
     * @param  array  $m
     * @param  int    $indent
     * @return string
     */
    private static function typesBlock($m, $indent)
    {
        $pad = str_repeat(' ', $indent);
        $out  = $pad . "# Declare mime types locally so you don't have to edit mime.types.\n";
        $out .= $pad . "types {\n";
        if ($m['avif']) {
            $out .= $pad . "    image/avif avif;\n";
        }
        $out .= $pad . "    image/webp webp;\n";
        $out .= $pad . "    image/jpeg jpg jpeg;\n";
        $out .= $pad . "    image/png  png;\n";
        $out .= $pad . "}\n";
        return $out;
    }

    /**
     * The header comment block shared by every artifact.
     *
     * @param  string  $title
     * @param  array   $m
     * @return string
     */
    private static function headerComment($title, $m)
    {
        $out  = "# =====================================================================\n";
        $out .= "# Magic Convert — generated nginx rules\n";
        $out .= "# " . $title . "\n";
        $out .= "# ---------------------------------------------------------------------\n";
        $out .= "# Generated:   " . $m['generatedAt'] . "\n";
        $out .= "# Plugin ver.: " . $m['pluginVersion'] . "\n";
        $out .= "# Fingerprint: " . $m['fingerprint'] . "\n";
        $out .= "#\n";
        $out .= "# Settings reflected in these rules:\n";
        $out .= "#   destination-folder:    " . $m['destinationFolder'] . "\n";
        $out .= "#   destination-extension: " . $m['destinationExtension'] . "\n";
        $out .= "#   destination-structure: " . $m['destinationStructure'] . "\n";
        $out .= "#   image-types:           " . $m['typesHuman'] . "\n";
        $out .= "#   redirect-to-existing:  " . ($m['redirectToExisting'] ? 'enabled' : 'disabled') . "\n";
        $out .= "#   redirect-to-converter: " . ($m['redirectToConverter'] ? 'enabled' : 'disabled') . "\n";
        $out .= "#   AVIF serving:          " . ($m['avif'] ? 'enabled' : 'disabled') . "\n";
        $out .= "#\n";
        $out .= "# SECURITY: these rules embed the config hash that authorizes the converter scripts.\n";
        $out .= "# Keep this file out of any web-accessible path. nginx does not honour .htaccess.\n";
        $out .= "# DO NOT EDIT MANUALLY — regenerate from the Magic Convert nginx tab when settings change.\n";
        $out .= "# =====================================================================\n";
        $out .= "\n";
        return $out;
    }

    // =====================================================================================
    //  MODEL PREPARATION (turns config + env into the small dict the builders consume)
    // =====================================================================================

    /**
     * Build the prepared model from config + env. This is where the full destination-options
     * matrix is resolved exactly once, mirroring HTAccessRules' option handling.
     *
     * @param  array  $config
     * @param  array  $env
     * @return array
     */
    private static function model($config, $env)
    {
        // Merge the shared rule defaults. settingsFingerprint() merges the SAME set before
        // hashing, so the fingerprint baked here matches the standalone/persisted one even for a
        // partial config. (See ruleDefaults().)
        $config = array_merge(self::ruleDefaults(), $config);

        $mingled  = ($config['destination-folder'] == 'mingled');
        $append   = ($config['destination-extension'] != 'set'); // 'append' or anything-not-set => append
        $docRoot  = ($config['destination-structure'] == 'doc-root');

        // image types -> regex alternation + human label
        $imageTypes = (int) $config['image-types'];
        $exts = [];
        if ($imageTypes & 1) { $exts[] = 'jpe?g'; }
        if ($imageTypes & 2) { $exts[] = 'png'; }
        $typeRegex = implode('|', $exts);             // e.g. 'jpe?g|png'
        $typesHuman = self::typesHuman($imageTypes);

        $avif = (
            isset($config['formats']['avif']['enabled']) &&
            $config['formats']['avif']['enabled'] === true
        );

        $redirectToExisting = (bool) $config['redirect-to-existing-in-htaccess'];
        $redirectToConverter = (bool) $config['enable-redirection-to-converter'];

        // Environment inputs (already docroot-relative, no leading slash) — pure, supplied by caller.
        $wpContentRel = isset($env['wpContentRelPath']) ? trim($env['wpContentRelPath'], '/') : 'wp-content';
        $cacheRootRel = isset($env['cacheRootRelToDocRoot'])
            ? trim($env['cacheRootRelToDocRoot'], '/')
            : ($wpContentRel . '/magic-convert');
        $hash = isset($env['configHash']) ? $env['configHash'] : '';
        $wodUrlPath = isset($env['wodUrlPath']) ? '/' . ltrim($env['wodUrlPath'], '/') : '';
        $realizerUrlPath = isset($env['realizerUrlPath']) ? '/' . ltrim($env['realizerUrlPath'], '/') : '';

        $scope = is_array($config['scope']) ? $config['scope'] : [];

        // image-roots structure: the converted file lives under a PER-ROOT cache subtree keyed by
        // the image-root id and the source path RELATIVE TO THAT ROOT, not the full doc-root path:
        //   <cacheRootRel>/<fmt>-images/<rootId>/<relPathWithinRoot><ext>
        // (see ConvertHelperIndependent::getDestination + Paths::destinationRoot). We therefore
        // cannot serve image-roots configs from the single doc-root location; each enabled root
        // needs its own location that captures the root-relative remainder and maps it into that
        // root's cache subtree. The roots-in-scope (id + docroot-relative url path) are supplied by
        // the caller via $env['imageRoots'] so the core stays pure/testable.
        $imageRootsMode = !$docRoot;
        $imageRootsEnv = (isset($env['imageRoots']) && is_array($env['imageRoots'])) ? $env['imageRoots'] : [];

        // ----- Location regex (scope) -----
        // The README recipe scopes to ^/?wp-content/.*\.(png|jpe?g)$ . We honour the configured
        // scope: when scope is exactly the wp-content roots we keep the wp-content prefix; if the
        // scope reaches outside wp-content (themes is inside, uploads usually inside), we still
        // anchor on wp-content because every default image root lives under it. The regex is the
        // wp-content tree by default. (Doc-root mode only; image-roots mode builds per-root regexes.)
        $locationRegex = '^/?' . self::regexEscapePathPrefix($wpContentRel) . '/.*\\.(' . $typeRegex . ')$';

        // ----- Unsupported-combination handling -----
        // The manual README recipe only ever supported 'append' extension mode. In 'set' mode a
        // source foo.jpg maps to foo.webp (extension REPLACED), not foo.jpg.webp. Doc-root cache
        // lookups still work in 'set' mode because the cache path is computed from the full source
        // URI; the only place 'set' matters for nginx is the MINGLED SIBLING lookup, where the
        // sibling is $1.$2 (basename + new ext) rather than $uri + suffix. We handle that with a
        // capture-group rewrite of $uri, but try_files cannot run a regex capture inline. So for
        // mingled + set we emit a clear note and generate the closest safe rules (doc-root cache
        // entries still work; the mingled-sibling entry is generated in append-form which is a
        // no-op miss in set mode rather than a wrong hit) — never silently wrong.
        // (Doc-root only: in image-roots mode the per-root descriptors carry their own, more precise
        // notes — see $imageRoots below — so we suppress this doc-root-worded one to avoid confusion.)
        $unsupportedNote = '';
        if ($mingled && !$append && $docRoot) {
            $unsupportedNote =
                "# NOTE: 'mingled' + 'set extension' (foo.jpg -> foo.webp) cannot be expressed as a pure\n" .
                "#       try_files suffix append in nginx, so the same-dir (mingled) sibling lookup is\n" .
                "#       omitted for this combination to avoid a wrong hit. The doc-root cache entries\n" .
                "#       below are correct ONLY for scopes the plugin writes to the doc-root cache dir\n" .
                "#       (e.g. themes). For images under uploads, mingled+set writes the converted file\n" .
                "#       in place as <name>.webp (extension REPLACED, not appended) and NOT to the\n" .
                "#       doc-root cache dir, so neither the suppressed sibling entry nor the kept\n" .
                "#       doc-root entry will hit — converted images under uploads are NOT served in this\n" .
                "#       mode. This is an honest-unsupported combination (never a silent wrong hit). For\n" .
                "#       full coverage keep 'destination-extension' = 'append', or use a separate cache\n" .
                "#       directory ('destination-folder' = 'separate'), which is always correct.\n";
        }
        // In mingled+set we suppress the (wrong) sibling entry. Effective 'mingled sibling' flag:
        $mingledSibling = $mingled && $append;

        // ----- try_files expression builders (closures keep the matrix logic in one place) -----

        // doc-root structured cache path: /<cacheRootRel>/<format>-images/doc-root$uri<suffix>
        // $uri already starts with '/', and includes the wp-content prefix.
        $cacheUriExpr = function ($formatId, $suffixVar) use ($cacheRootRel) {
            $cacheDirName = ($formatId === 'avif') ? 'avif-images' : 'webp-images';
            return '/' . $cacheRootRel . '/' . $cacheDirName . '/doc-root$uri' . $suffixVar;
        };

        // mingled sibling: original location + suffix (append mode). foo.jpg -> foo.jpg.webp
        $siblingUriExpr = function ($suffixVar) {
            return '$uri' . $suffixVar;
        };

        // ----- Per-image-root descriptors (image-roots structure only) -----
        // Each enabled root in scope becomes a self-contained location with:
        //   - locationRegex: ^/?<rootUrlPath>/(?<capture>.+\.(types))$   (named capture — positional
        //     $1 is unreliable in try_files; a named capture survives into the chain)
        //   - cacheExpr(fmt,suffixVar): /<cacheRootRel>/<fmt>-images/<rootId>/<capture><suffix>
        //   - siblingExpr(suffixVar): $uri<suffix> (mingled in-place sibling)
        //   - negotiateName: a per-root @mc_negotiate_<rootId> target for Artifact B (the single
        //     file can't share one named location across roots because each needs its own capture).
        // The mingled-uploads special case mirrors Paths::getCacheDirForImageRoot / destinationRoot:
        // for the 'uploads' root in mingled mode the converted file is written IN PLACE as a sibling
        // (not under the cache subtree), so that root uses the sibling lookup and NOT the cache one.
        $imageRoots = [];
        $imageRootsNote = '';
        if ($imageRootsMode) {
            if (count($imageRootsEnv) === 0) {
                // Honest-unsupported: we are in image-roots mode but the caller gave us no roots to
                // build per-root locations from. Emit a clear note rather than silently generating a
                // single doc-root-shaped location that would never hit the per-root cache subtree.
                $imageRootsNote =
                    "# NOTE: destination-structure = 'image-roots' is configured, but no image roots were\n" .
                    "#       supplied to the generator, so NO per-root location blocks could be emitted and\n" .
                    "#       converted images will NOT be served. Re-generate from the Magic Convert nginx\n" .
                    "#       tab (which supplies the in-scope image roots), or switch destination-structure\n" .
                    "#       to 'doc-root'.\n";
            }
            foreach ($imageRootsEnv as $root) {
                if (!isset($root['id']) || !isset($root['urlPath'])) {
                    continue;
                }
                $rootId = $root['id'];
                $rootUrlPath = trim($root['urlPath'], '/');
                // Sanitize the capture variable name: nginx variables are [A-Za-z0-9_].
                $capName = 'mc_rest_' . preg_replace('/[^A-Za-z0-9_]/', '_', $rootId);
                $captureVar = '$' . $capName;

                $rootLocationRegex = '"^/?' . self::regexEscapePathPrefix($rootUrlPath) .
                    '/(?<' . $capName . '>.+\\.(?:' . $typeRegex . '))$"';

                // The 'uploads' root in mingled mode writes converted files in place (sibling),
                // exactly like Paths::getCacheDirForImageRoot's mingled+uploads short-circuit.
                $rootMingledInPlace = ($mingled && ($rootId == 'uploads'));

                // Cache subtree expression for this root (separate / mingled-non-uploads).
                $rootCacheExpr = function ($formatId, $suffixVar) use ($cacheRootRel, $rootId, $captureVar) {
                    $cacheDirName = ($formatId === 'avif') ? 'avif-images' : 'webp-images';
                    return '/' . $cacheRootRel . '/' . $cacheDirName . '/' . $rootId . '/' . $captureVar . $suffixVar;
                };
                // In-place sibling expression (mingled uploads): $uri + suffix.
                $rootSiblingExpr = function ($suffixVar) {
                    return '$uri' . $suffixVar;
                };

                // Per-root unsupported note: mingled+set on this root cannot be expressed as a pure
                // suffix append (foo.jpg -> foo.webp replaces the extension). We drop the affected
                // lookup for that root and explain — never a silent wrong hit.
                $rootNote = '';
                $rootUseCache = true;       // emit the cache-subtree lookup for this root
                $rootUseSibling = false;    // emit the in-place sibling lookup for this root
                if ($rootMingledInPlace) {
                    if ($append) {
                        $rootUseCache = false;
                        $rootUseSibling = true;
                    } else {
                        // mingled + set + uploads: in-place file is <name>.webp (ext replaced). Not a
                        // pure suffix append; suppress to avoid a wrong hit.
                        $rootUseCache = false;
                        $rootUseSibling = false;
                        $rootNote =
                            "# NOTE: image root '" . $rootId . "' with 'mingled' + 'set extension' writes the\n" .
                            "#       converted file in place as <name>.webp (extension REPLACED), which cannot be\n" .
                            "#       expressed as a try_files suffix append. The in-place lookup is omitted for\n" .
                            "#       this root to avoid a wrong hit — converted images here are NOT served. Use\n" .
                            "#       'destination-extension' = 'append' or 'destination-folder' = 'separate'.\n";
                    }
                } else {
                    // separate, or mingled on a non-uploads root: served from the cache subtree.
                    if (!$append) {
                        // set extension only affects mingled-uploads in image-roots mode (separate
                        // always appends — appendOrSetExtension only SETs for mingled+upload). The
                        // cache subtree path is computed from the root-relative remainder + appended
                        // suffix, so it stays correct here. No note needed.
                    }
                    $rootUseCache = true;
                    $rootUseSibling = false;
                }

                $imageRoots[] = [
                    'id' => $rootId,
                    'urlPath' => $rootUrlPath,
                    'locationRegex' => $rootLocationRegex,
                    'captureVar' => $captureVar,
                    'negotiateName' => '@mc_negotiate_' . preg_replace('/[^A-Za-z0-9_]/', '_', $rootId),
                    'cacheExpr' => $rootCacheExpr,
                    'siblingExpr' => $rootSiblingExpr,
                    'useCache' => $rootUseCache,
                    'useSibling' => $rootUseSibling,
                    'note' => $rootNote,
                ];
            }
        }

        // Converter fallback final-URI form (webp only).
        $converterUri =
            $wodUrlPath . '?xsource=x$request_filename&wp-content=' . $wpContentRel .
            ($hash !== '' ? '&hash=' . $hash : '');

        // Realizer location + final-URI form.
        $realizerLocationRegex = '^/?' . self::regexEscapePathPrefix($wpContentRel) .
            '/.*\\.(' . $typeRegex . ')\\.webp$';
        $realizerUri =
            $realizerUrlPath . '?xdestination=x$request_filename&wp-content=' . $wpContentRel .
            ($hash !== '' ? '&hash=' . $hash : '');

        $generatedAt = isset($env['generatedAt']) ? $env['generatedAt'] : gmdate('Y-m-d H:i:s') . ' UTC';
        $pluginVersion = isset($env['pluginVersion']) ? $env['pluginVersion'] : 'unknown';

        $model = [
            'mingled' => $mingled,
            'append' => $append,
            'cacheDocRoot' => $docRoot,
            'avif' => $avif,
            'redirectToExisting' => $redirectToExisting,
            'redirectToConverter' => $redirectToConverter,
            'typeRegex' => $typeRegex,
            'typesHuman' => $typesHuman,
            'locationRegex' => $locationRegex,
            'realizerLocationRegex' => $realizerLocationRegex,
            'cacheUriExpr' => $cacheUriExpr,
            'siblingUriExpr' => $siblingUriExpr,
            'converterUri' => $converterUri,
            'realizerUri' => $realizerUri,
            'unsupportedNote' => $unsupportedNote,
            'imageRootsMode' => $imageRootsMode,
            'imageRoots' => $imageRoots,
            'imageRootsNote' => $imageRootsNote,
            'destinationFolder' => $config['destination-folder'],
            'destinationExtension' => $config['destination-extension'],
            'destinationStructure' => $config['destination-structure'],
            'generatedAt' => $generatedAt,
            'pluginVersion' => $pluginVersion,
        ];

        // Apply the mingled-sibling suppression decided above.
        $model['mingled'] = $mingledSibling;

        // Fingerprint is computed over config+env, independent of $model presentation.
        $model['fingerprint'] = self::settingsFingerprint($config, $env);

        return $model;
    }

    /**
     * Human label for the image-types bitmask.
     *
     * @param  int  $imageTypes
     * @return string
     */
    private static function typesHuman($imageTypes)
    {
        $hasJpeg = (bool) ($imageTypes & 1);
        $hasPng  = (bool) ($imageTypes & 2);
        if ($hasJpeg && $hasPng) { return 'jpeg + png'; }
        if ($hasJpeg) { return 'jpeg'; }
        if ($hasPng) { return 'png'; }
        return 'none';
    }

    /**
     * Escape a docroot-relative path prefix for safe inclusion in an nginx location regex.
     * Only '.' needs escaping for the path components we deal with; everything else in a
     * wp-content-style path is regex-literal.
     *
     * @param  string  $path
     * @return string
     */
    private static function regexEscapePathPrefix($path)
    {
        return str_replace('.', '\\.', $path);
    }

    // =====================================================================================
    //  THIN WP/Paths WRAPPERS (the only impure code — gather env, then delegate to the core)
    // =====================================================================================

    /**
     * Gather the rule-affecting environment from Paths/WordPress. Kept thin and separate so the
     * generation core stays pure and unit-testable.
     *
     * @return array
     */
    public static function environmentFromPaths($config = null)
    {
        return [
            'wpContentRelPath' => Paths::getContentDirRel(),
            'cacheRootRelToDocRoot' => Paths::getMagicConvertContentDirRel(),
            'configHash' => Paths::getConfigHash(),
            'wodUrlPath' => Paths::getWodUrlPath(),
            'realizerUrlPath' => Paths::getWebPRealizerUrlPath(),
            'pluginVersion' => self::pluginVersion(),
            'generatedAt' => gmdate('Y-m-d H:i:s') . ' UTC',
            // The in-scope image roots (id + docroot-relative url path), needed only for
            // destination-structure = 'image-roots'. We gather them here (impure) so the pure core
            // can build per-root locations from plain data.
            'imageRoots' => self::imageRootsInScopeFromPaths($config),
        ];
    }

    /**
     * Gather the in-scope image roots as [{id, urlPath}, ...] from Paths. urlPath is the
     * docroot-relative URL path (e.g. 'wp-content/uploads'). When $config is null or has no scope,
     * falls back to the default scope. Thin/impure wrapper — never called from the pure core.
     *
     * @param  array|null  $config
     * @return array<int,array{id:string,urlPath:string}>
     */
    private static function imageRootsInScopeFromPaths($config)
    {
        $scope = (is_array($config) && isset($config['scope']) && is_array($config['scope']))
            ? $config['scope']
            : ['themes', 'uploads'];

        $roots = [];
        foreach ($scope as $rootId) {
            $urlPath = Paths::getUrlPathById($rootId);
            if ($urlPath === false || $urlPath === null || $urlPath === '') {
                continue;
            }
            $roots[] = ['id' => $rootId, 'urlPath' => $urlPath];
        }
        return $roots;
    }

    /**
     * Best-effort plugin version. Reads the 'Version:' header from the main plugin file via
     * WordPress's get_file_data() when available; falls back to 'unknown'. Thin/impure — only
     * called from the wrapper layer, never from the pure core.
     *
     * @return string
     */
    private static function pluginVersion()
    {
        if (defined('MAGIC_CONVERT_PLUGIN') && function_exists('get_file_data')) {
            $data = get_file_data(constant('MAGIC_CONVERT_PLUGIN'), ['Version' => 'Version']);
            if (!empty($data['Version'])) {
                return $data['Version'];
            }
        }
        return 'unknown';
    }

    /** Convenience: maps file from live Paths environment. */
    public static function generateMapsFileFromPaths($config)
    {
        return self::generateMapsFile($config, self::environmentFromPaths($config));
    }

    /** Convenience: server file from live Paths environment. */
    public static function generateServerFileFromPaths($config)
    {
        return self::generateServerFile($config, self::environmentFromPaths($config));
    }

    /** Convenience: single file from live Paths environment. */
    public static function generateSingleFileFromPaths($config)
    {
        return self::generateSingleFile($config, self::environmentFromPaths($config));
    }

    /**
     * The non-secret state triple to persist on each config save (NEVER the rule body).
     * Phase 3.2 (UI) and 3.3 (drift detection) read this back to compare without regenerating.
     *
     * @param  array  $config
     * @return array{fingerprint:string, generated-at:string, plugin-version:string}
     */
    public static function stateRecordFromPaths($config)
    {
        $env = self::environmentFromPaths($config);
        return [
            'fingerprint' => self::settingsFingerprint($config, $env),
            'generated-at' => $env['generatedAt'],
            'plugin-version' => $env['pluginVersion'],
        ];
    }
}
