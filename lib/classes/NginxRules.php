<?php

namespace MagicConvert;

class NginxRules
{
    const AVIF_NO_MATCH_SENTINEL = '.mc-no-avif';
    const WEBP_NO_MATCH_SENTINEL = '.mc-no-webp';

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
        'destination-folder',
        'destination-extension',
        'destination-structure',
        'image-types',
        'scope',
        'redirect-to-existing-in-htaccess',
        'enable-redirection-to-converter',
        'only-redirect-to-converter-for-webp-enabled-browsers',
    ];

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

    public static function generateServerFile($config, $env)
    {
        $m = self::model($config, $env);

        $out  = self::headerComment('magic-convert-server.conf (server context)', $m);
        $out .= "# Place this file's include in the SERVER context (inside server { } of your site,\n";
        $out .= "# usually under /etc/nginx/sites-available/). It REQUIRES magic-convert-maps.conf to be\n";
        $out .= "# included in the http context. If you cannot include a maps file, use the single-file\n";
        $out .= "# artifact (it inlines the negotiation with set/if instead).\n";
        $out .= "\n";
        $out .= self::sourceLocationBlock($m, true);
        $out .= "\n";
        $out .= self::realizerLocationBlock($m);
        $out .= "\n";
        $out .= self::rulesVersionMarker($m['fingerprint']);
        return $out;
    }

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
        $out .= self::sourceLocationBlock($m, false);
        $out .= "\n";
        $out .= self::realizerLocationBlock($m);
        $out .= "\n";
        $out .= self::rulesVersionMarker($m['fingerprint']);
        return $out;
    }

    public static function settingsFingerprint($config, $env)
    {
        $config = array_merge(self::ruleDefaults(), $config);

        $subset = [];
        foreach (self::$fingerprintConfigKeys as $key) {
            $subset[$key] = isset($config[$key]) ? $config[$key] : null;
        }
        $subset['avif-enabled'] = (
            isset($config['formats']['avif']['enabled']) &&
            $config['formats']['avif']['enabled'] === true
        );
        $subset['wp-content-rel'] = isset($env['wpContentRelPath']) ? $env['wpContentRelPath'] : null;
        $subset['hash'] = isset($env['configHash']) ? $env['configHash'] : null;
        $subset['cache-root-rel'] = isset($env['cacheRootRelToDocRoot']) ? $env['cacheRootRelToDocRoot'] : null;
        $subset['wod-url-path'] = isset($env['wodUrlPath']) ? $env['wodUrlPath'] : null;
        $subset['realizer-url-path'] = isset($env['realizerUrlPath']) ? $env['realizerUrlPath'] : null;

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

        ksort($subset);
        return md5(json_encode($subset));
    }

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
            $out .= "location ~* " . $m['locationRegex'] . " {\n";
            $out .= self::negotiationBody($m, 4);
            $out .= "}\n";
            return $out;
        }

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

    private static function imageRootLocationBlock($m, $rootCtx, $useMaps)
    {
        $out = "# image root '" . $rootCtx['id'] . "' (url path: /" . $rootCtx['urlPath'] . ")\n";
        if (!empty($rootCtx['note'])) {
            $out .= $rootCtx['note'];
        }

        if ($useMaps) {
            $out .= "location ~* " . $rootCtx['locationRegex'] . " {\n";
            $out .= self::negotiationBody($m, 4, $rootCtx);
            $out .= "}\n";
            return $out;
        }

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

    private static function negotiationBody($m, $indent, $rootCtx = null)
    {
        $pad = str_repeat(' ', $indent);
        $out  = $pad . "add_header Vary Accept;\n";
        $out .= $pad . "expires 365d;\n";
        $out .= self::typesBlock($m, $indent);

        $out .= $pad . "try_files\n";
        foreach (self::tryEntries($m, $rootCtx) as $entry) {
            $out .= $pad . "    " . $entry . "\n";
        }
        $out .= $pad . "    ;\n";
        return $out;
    }

    private static function tryEntries($m, $rootCtx = null)
    {
        $entries = [];

        if ($m['redirectToExisting']) {
            if ($rootCtx !== null) {
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
                if ($m['avif']) {
                    if ($m['cacheDocRoot']) {
                        $entries[] = $m['cacheUriExpr']('avif', '$mc_avif_suffix');
                    }
                    if ($m['mingled']) {
                        $entries[] = $m['siblingUriExpr']('$mc_avif_suffix');
                    }
                }

                if ($m['cacheDocRoot']) {
                    $entries[] = $m['cacheUriExpr']('webp', '$mc_webp_suffix');
                }
                if ($m['mingled']) {
                    $entries[] = $m['siblingUriExpr']('$mc_webp_suffix');
                }
            }
        }

        $entries[] = '$uri';

        if ($m['redirectToConverter']) {
            $entries[] = $m['converterUri'];
            return $entries;
        }

        $entries[] = '=404';

        return $entries;
    }

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

    private static function model($config, $env)
    {
        $config = array_merge(self::ruleDefaults(), $config);

        $mingled  = ($config['destination-folder'] == 'mingled');
        $append   = ($config['destination-extension'] != 'set');
        $docRoot  = ($config['destination-structure'] == 'doc-root');

        $imageTypes = (int) $config['image-types'];
        $exts = [];
        if ($imageTypes & 1) { $exts[] = 'jpe?g'; }
        if ($imageTypes & 2) { $exts[] = 'png'; }
        $typeRegex = implode('|', $exts);
        $typesHuman = self::typesHuman($imageTypes);

        $avif = (
            isset($config['formats']['avif']['enabled']) &&
            $config['formats']['avif']['enabled'] === true
        );

        $redirectToExisting = (bool) $config['redirect-to-existing-in-htaccess'];
        $redirectToConverter = (bool) $config['enable-redirection-to-converter'];

        $wpContentRel = isset($env['wpContentRelPath']) ? trim($env['wpContentRelPath'], '/') : 'wp-content';
        $cacheRootRel = isset($env['cacheRootRelToDocRoot'])
            ? trim($env['cacheRootRelToDocRoot'], '/')
            : ($wpContentRel . '/magic-convert');
        $hash = isset($env['configHash']) ? $env['configHash'] : '';
        $wodUrlPath = isset($env['wodUrlPath']) ? '/' . ltrim($env['wodUrlPath'], '/') : '';
        $realizerUrlPath = isset($env['realizerUrlPath']) ? '/' . ltrim($env['realizerUrlPath'], '/') : '';

        $scope = is_array($config['scope']) ? $config['scope'] : [];

        $imageRootsMode = !$docRoot;
        $imageRootsEnv = (isset($env['imageRoots']) && is_array($env['imageRoots'])) ? $env['imageRoots'] : [];

        $locationRegex = '^/?' . self::regexEscapePathPrefix($wpContentRel) . '/.*\\.(' . $typeRegex . ')$';

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
        $mingledSibling = $mingled && $append;

        $cacheUriExpr = function ($formatId, $suffixVar) use ($cacheRootRel) {
            $cacheDirName = ($formatId === 'avif') ? 'avif-images' : 'webp-images';
            return '/' . $cacheRootRel . '/' . $cacheDirName . '/doc-root$uri' . $suffixVar;
        };

        $siblingUriExpr = function ($suffixVar) {
            return '$uri' . $suffixVar;
        };

        $imageRoots = [];
        $imageRootsNote = '';
        if ($imageRootsMode) {
            if (count($imageRootsEnv) === 0) {
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
                $capName = 'mc_rest_' . preg_replace('/[^A-Za-z0-9_]/', '_', $rootId);
                $captureVar = '$' . $capName;

                $rootLocationRegex = '"^/?' . self::regexEscapePathPrefix($rootUrlPath) .
                    '/(?<' . $capName . '>.+\\.(?:' . $typeRegex . '))$"';

                $rootMingledInPlace = ($mingled && ($rootId == 'uploads'));

                $rootCacheExpr = function ($formatId, $suffixVar) use ($cacheRootRel, $rootId, $captureVar) {
                    $cacheDirName = ($formatId === 'avif') ? 'avif-images' : 'webp-images';
                    return '/' . $cacheRootRel . '/' . $cacheDirName . '/' . $rootId . '/' . $captureVar . $suffixVar;
                };
                $rootSiblingExpr = function ($suffixVar) {
                    return '$uri' . $suffixVar;
                };

                $rootNote = '';
                $rootUseCache = true;
                $rootUseSibling = false;
                if ($rootMingledInPlace) {
                    if ($append) {
                        $rootUseCache = false;
                        $rootUseSibling = true;
                    } else {
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

        $converterUri =
            $wodUrlPath . '?xsource=x$request_filename&wp-content=' . $wpContentRel .
            ($hash !== '' ? '&hash=' . $hash : '');

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

        $model['mingled'] = $mingledSibling;

        $model['fingerprint'] = self::settingsFingerprint($config, $env);

        return $model;
    }

    private static function typesHuman($imageTypes)
    {
        $hasJpeg = (bool) ($imageTypes & 1);
        $hasPng  = (bool) ($imageTypes & 2);
        if ($hasJpeg && $hasPng) { return 'jpeg + png'; }
        if ($hasJpeg) { return 'jpeg'; }
        if ($hasPng) { return 'png'; }
        return 'none';
    }

    private static function regexEscapePathPrefix($path)
    {
        return str_replace('.', '\\.', $path);
    }

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
            'imageRoots' => self::imageRootsInScopeFromPaths($config),
        ];
    }

    /**
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

    public static function generateMapsFileFromPaths($config)
    {
        return self::generateMapsFile($config, self::environmentFromPaths($config));
    }

    public static function generateServerFileFromPaths($config)
    {
        return self::generateServerFile($config, self::environmentFromPaths($config));
    }

    public static function generateSingleFileFromPaths($config)
    {
        return self::generateSingleFile($config, self::environmentFromPaths($config));
    }

    /**
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
