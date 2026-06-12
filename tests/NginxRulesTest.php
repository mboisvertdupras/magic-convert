<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\NginxRules;

/**
 * Pure-output unit tests for NginxRules: structural invariants for matrix points, plus
 * fingerprint stability. No filesystem / WordPress / nginx needed (the generation core is
 * pure — config + env in, string out), so these run everywhere.
 */
class NginxRulesTest extends TestCase
{
    /** A representative, complete environment. */
    private function env(array $overrides = []): array
    {
        return array_merge([
            'wpContentRelPath' => 'wp-content',
            'cacheRootRelToDocRoot' => 'wp-content/magic-convert',
            'configHash' => 'c513fe386c6b8793f9bf9ad1071d2266',
            'wodUrlPath' => 'wp-content/plugins/magic-convert/wod/webp-on-demand.php',
            'realizerUrlPath' => 'wp-content/plugins/magic-convert/wod/webp-realizer.php',
            'pluginVersion' => '0.1.0',
            'generatedAt' => '2026-06-12 00:00:00 UTC',
            // In-scope image roots (id + docroot-relative url path) used by image-roots mode.
            'imageRoots' => [
                ['id' => 'themes', 'urlPath' => 'wp-content/themes'],
                ['id' => 'uploads', 'urlPath' => 'wp-content/uploads'],
            ],
        ], $overrides);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'destination-folder' => 'separate',
            'destination-extension' => 'append',
            'destination-structure' => 'doc-root',
            'image-types' => 3,
            'scope' => ['themes', 'uploads'],
            'redirect-to-existing-in-htaccess' => true,
            'enable-redirection-to-converter' => true,
            'only-redirect-to-converter-for-webp-enabled-browsers' => true,
        ], $overrides);
    }

    private function avifOn(array $config): array
    {
        $config['formats']['avif']['enabled'] = true;
        return $config;
    }

    // --- types block + Vary present everywhere -----------------------------------

    public function testServerFileHasTypesBlockAndVary(): void
    {
        $out = NginxRules::generateServerFile($this->config(), $this->env());
        $this->assertStringContainsString('types {', $out);
        $this->assertStringContainsString('image/webp webp;', $out);
        $this->assertStringContainsString('image/jpeg jpg jpeg;', $out);
        $this->assertStringContainsString('image/png  png;', $out);
        $this->assertStringContainsString('add_header Vary Accept;', $out);
    }

    public function testSingleFileHasTypesBlockAndVary(): void
    {
        $out = NginxRules::generateSingleFile($this->config(), $this->env());
        $this->assertStringContainsString('types {', $out);
        $this->assertStringContainsString('add_header Vary Accept;', $out);
    }

    /**
     * Artifact B must NOT put try_files in the same location as the negotiation 'if' (the
     * "if inside location is evil" defect). It must use the decider + @mc_negotiate handoff so
     * the try_files actually runs. Structural guard complementing the functional serve test.
     */
    public function testSingleFileUsesNegotiateHandoffNotIfWithTryFiles(): void
    {
        $out = NginxRules::generateSingleFile($this->avifOn($this->config()), $this->env());

        // Handoff scaffolding present.
        $this->assertStringContainsString('error_page 418 = @mc_negotiate;', $out);
        $this->assertStringContainsString('return 418;', $out);
        $this->assertStringContainsString('location @mc_negotiate {', $out);
        $this->assertStringContainsString('internal;', $out);

        // The try_files DIRECTIVE (indented, on its own line — not the comment mentioning it)
        // must live in @mc_negotiate, AFTER the named-location opener, so the decider location
        // (which holds the 'if') does NOT contain it.
        $negotiateStart = strpos($out, 'location @mc_negotiate {');
        $this->assertNotFalse($negotiateStart);
        $this->assertSame(
            1,
            preg_match('/\n\s+try_files\n/', $out, $mm, PREG_OFFSET_CAPTURE),
            'expected a try_files directive on its own line'
        );
        $tryFilesPos = $mm[0][1];
        $this->assertGreaterThan(
            $negotiateStart,
            $tryFilesPos,
            'try_files must appear inside @mc_negotiate, not in the decider location with the if'
        );
    }

    // --- avif disabled => no avif content ----------------------------------------

    public function testNoAvifContentWhenDisabled(): void
    {
        $maps = NginxRules::generateMapsFile($this->config(), $this->env());
        $server = NginxRules::generateServerFile($this->config(), $this->env());
        $single = NginxRules::generateSingleFile($this->config(), $this->env());

        foreach ([$maps, $server, $single] as $out) {
            $this->assertStringNotContainsString('avif', $out, 'No avif token should appear when disabled');
            $this->assertStringNotContainsString('$mc_avif_suffix', $out);
        }
    }

    public function testAvifContentWhenEnabled(): void
    {
        $cfg = $this->avifOn($this->config());
        $maps = NginxRules::generateMapsFile($cfg, $this->env());
        $server = NginxRules::generateServerFile($cfg, $this->env());

        $this->assertStringContainsString('$mc_avif_suffix', $maps);
        $this->assertStringContainsString('image/avif', $maps);
        $this->assertStringContainsString('image/avif avif;', $server);
        $this->assertStringContainsString('avif-images/doc-root$uri$mc_avif_suffix', $server);
    }

    // --- avif try entry precedes webp --------------------------------------------

    public function testAvifTryEntryPrecedesWebp(): void
    {
        $cfg = $this->avifOn($this->config());
        $server = NginxRules::generateServerFile($cfg, $this->env());

        $avifPos = strpos($server, 'avif-images/doc-root$uri$mc_avif_suffix');
        $webpPos = strpos($server, 'webp-images/doc-root$uri$mc_webp_suffix');
        $this->assertNotFalse($avifPos);
        $this->assertNotFalse($webpPos);
        $this->assertLessThan($webpPos, $avifPos, 'avif try entry must precede webp');
    }

    // --- both guarded by suffix variable (map/set emptiness semantics) -----------

    public function testTryEntriesUseSuffixVariables(): void
    {
        $cfg = $this->avifOn($this->config());
        $serverA = NginxRules::generateServerFile($cfg, $this->env());
        // Artifact A relies on map vars; the suffix variables must appear in try entries.
        $this->assertStringContainsString('$uri$mc_avif_suffix', $serverA);
        $this->assertStringContainsString('$uri$mc_webp_suffix', $serverA);

        // Artifact B inlines them with set/if (the variables still drive try_files). The initial
        // 'set' uses a guaranteed-miss sentinel (NOT empty) so an unsupported format's try entry
        // is skipped rather than collapsing to $uri.
        $single = NginxRules::generateSingleFile($cfg, $this->env());
        $this->assertStringContainsString('set $mc_avif_suffix ".mc-no-avif";', $single);
        $this->assertStringContainsString('if ($http_accept ~* "image/avif") { set $mc_avif_suffix ".avif"; }', $single);
        $this->assertStringContainsString('set $mc_webp_suffix ".mc-no-webp";', $single);
        $this->assertStringContainsString('if ($http_accept ~* "image/webp") { set $mc_webp_suffix ".webp"; }', $single);
    }

    // --- hash in converter URI only when redirect-to-converter enabled -----------

    public function testHashInConverterUriOnlyWhenConverterEnabled(): void
    {
        $hash = 'c513fe386c6b8793f9bf9ad1071d2266';

        $withConverter = NginxRules::generateServerFile($this->config(), $this->env());
        $this->assertStringContainsString('webp-on-demand.php?xsource=x$request_filename', $withConverter);
        $this->assertStringContainsString('hash=' . $hash, $withConverter);
        $this->assertStringContainsString('webp-realizer.php', $withConverter);

        $noConverter = NginxRules::generateServerFile(
            $this->config(['enable-redirection-to-converter' => false]),
            $this->env()
        );
        $this->assertStringNotContainsString('webp-on-demand.php', $noConverter);
        $this->assertStringNotContainsString('webp-realizer.php', $noConverter);
        $this->assertStringNotContainsString('hash=' . $hash, $noConverter);
    }

    // --- terminal try_files entry: $uri is NEVER last (rewrite-cycle guard) -------

    /**
     * Extract the ordered, trimmed try_files argument lines from a generated artifact.
     *
     * @return string[]
     */
    private function tryFilesEntries(string $out): array
    {
        $this->assertSame(
            1,
            preg_match('/\n\s+try_files\n(.*?)\n\s+;\n/s', $out, $m),
            'expected a try_files block terminated by a lone ";"'
        );
        $entries = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $entries[] = $line;
            }
        }
        return $entries;
    }

    /**
     * REGRESSION (the HTTP 500 / 418 defect): nginx treats the LAST try_files argument as an
     * internal-redirect/fallback TARGET, not a file to serve. With the converter fallback
     * disabled the chain used to end on a bare "$uri", so a request that reached it re-entered
     * the same location -> "rewrite or internal redirection cycle" (500) or a stalled 418 handoff.
     * The last entry must be a non-redirecting terminal ("=404") in that case, and the converter
     * URI (a real target) otherwise. $uri must NEVER be the final argument.
     */
    public function testTryFilesNeverEndsOnBareUriWhenConverterDisabled(): void
    {
        foreach ([$this->config(), $this->avifOn($this->config())] as $base) {
            $cfg = array_merge($base, ['enable-redirection-to-converter' => false]);

            foreach (
                [
                    'server' => NginxRules::generateServerFile($cfg, $this->env()),
                    'single' => NginxRules::generateSingleFile($cfg, $this->env()),
                ] as $label => $out
            ) {
                $entries = $this->tryFilesEntries($out);
                $last = end($entries);
                $this->assertSame('=404', $last, "$label: converter-off chain must terminate on =404");
                $this->assertNotSame('$uri', $last, "$label: \$uri must never be the last try_files arg");
                // $uri must still be present (the original is served when no converted file hits)
                // and must come immediately before the terminal.
                $this->assertContains('$uri', $entries, "$label: original \$uri entry must be present");
                $this->assertSame('$uri', $entries[count($entries) - 2], "$label: \$uri must precede the =404 terminal");
            }
        }
    }

    /**
     * When the converter IS enabled, the converter URI (a real internal-redirect target) safely
     * terminates the chain — so NO synthetic "=404" terminal should be appended (it would be dead
     * after the converter and would also swallow the converter's own 404s).
     */
    public function testTryFilesEndsOnConverterUriWhenConverterEnabled(): void
    {
        $out = NginxRules::generateServerFile($this->config(), $this->env());
        $entries = $this->tryFilesEntries($out);
        $last = end($entries);
        $this->assertStringContainsString('webp-on-demand.php', $last, 'converter URI must terminate the chain');
        $this->assertNotContains('=404', $entries, 'no synthetic =404 terminal when the converter terminates the chain');
    }

    // --- redirect-to-existing gates the avif/webp cache+sibling try entries ------

    /**
     * SEMANTIC PARITY with HTAccessRules: the avif/webp cache + sibling lookups (the
     * "redirect to existing converted file" behaviour) must be gated on
     * 'redirect-to-existing-in-htaccess'. With the flag OFF, those entries must NOT be emitted —
     * pre-existing .webp/.avif are not served from cache, exactly as .htaccess would behave —
     * leaving only $uri (+ converter when enabled). HTAccessRules gates these on the same flag.
     */
    public function testRedirectToExistingGatesCacheAndSiblingEntries(): void
    {
        // mingled + avif on so BOTH cache (doc-root) and sibling entries are in play.
        $base = $this->avifOn($this->config(['destination-folder' => 'mingled', 'destination-extension' => 'append']));

        // Flag ON: cache + sibling lookups present.
        $on = NginxRules::generateServerFile(array_merge($base, ['redirect-to-existing-in-htaccess' => true]), $this->env());
        $onEntries = $this->tryFilesEntries($on);
        $this->assertContains('/wp-content/magic-convert/avif-images/doc-root$uri$mc_avif_suffix', $onEntries);
        $this->assertContains('/wp-content/magic-convert/webp-images/doc-root$uri$mc_webp_suffix', $onEntries);
        $this->assertContains('$uri$mc_avif_suffix', $onEntries);
        $this->assertContains('$uri$mc_webp_suffix', $onEntries);

        // Flag OFF: every cache/sibling lookup is gone; only $uri (+converter) remain.
        $off = NginxRules::generateServerFile(array_merge($base, ['redirect-to-existing-in-htaccess' => false]), $this->env());
        $offEntries = $this->tryFilesEntries($off);
        $this->assertNotContains('/wp-content/magic-convert/avif-images/doc-root$uri$mc_avif_suffix', $offEntries);
        $this->assertNotContains('/wp-content/magic-convert/webp-images/doc-root$uri$mc_webp_suffix', $offEntries);
        $this->assertNotContains('$uri$mc_avif_suffix', $offEntries);
        $this->assertNotContains('$uri$mc_webp_suffix', $offEntries);
        $this->assertContains('$uri', $offEntries, 'the original $uri entry must remain when redirect-to-existing is off');
    }

    // --- image types narrow the location regex ----------------------------------

    public function testImageTypesAffectLocationRegex(): void
    {
        $both = NginxRules::generateServerFile($this->config(['image-types' => 3]), $this->env());
        $this->assertStringContainsString('(jpe?g|png)', $both);

        $jpegOnly = NginxRules::generateServerFile($this->config(['image-types' => 1]), $this->env());
        $this->assertStringContainsString('(jpe?g)', $jpegOnly);
        $this->assertStringNotContainsString('jpe?g|png', $jpegOnly);

        $pngOnly = NginxRules::generateServerFile($this->config(['image-types' => 2]), $this->env());
        $this->assertStringContainsString('(png)', $pngOnly);
    }

    // --- mingled adds sibling entry; mingled+set emits the unsupported note -------

    public function testMingledAppendAddsSiblingEntry(): void
    {
        $cfg = $this->config(['destination-folder' => 'mingled', 'destination-extension' => 'append']);
        $out = NginxRules::generateServerFile($cfg, $this->env());
        // mingled sibling lookup: $uri$mc_webp_suffix as a standalone try entry.
        $this->assertMatchesRegularExpression('/\n\s+\$uri\$mc_webp_suffix\n/', $out);
        $this->assertStringNotContainsString('NOTE:', $out);
    }

    public function testMingledSetEmitsUnsupportedNoteAndDropsSibling(): void
    {
        $cfg = $this->config(['destination-folder' => 'mingled', 'destination-extension' => 'set']);
        $out = NginxRules::generateServerFile($cfg, $this->env());
        $this->assertStringContainsString("NOTE: 'mingled' + 'set extension'", $out);
        // The mingled sibling try-entry must be suppressed (would be a wrong hit).
        $this->assertDoesNotMatchRegularExpression('/\n\s+\$uri\$mc_webp_suffix\n/', $out);
        // Doc-root cache entry must still be present (it is always correct).
        $this->assertStringContainsString('webp-images/doc-root$uri$mc_webp_suffix', $out);
    }

    // --- moved wp-content reflected in paths -------------------------------------

    public function testMovedWpContentReflectedInPaths(): void
    {
        $env = $this->env([
            'wpContentRelPath' => 'wp-content-moved',
            'cacheRootRelToDocRoot' => 'wp-content-moved/magic-convert',
        ]);
        $out = NginxRules::generateServerFile($this->config(), $env);
        $this->assertStringContainsString('^/?wp-content-moved/.*', $out);
        $this->assertStringContainsString('wp-content-moved/magic-convert/webp-images/doc-root', $out);
        $this->assertStringContainsString('wp-content=wp-content-moved', $out);
    }

    // --- rules-version marker emits the fingerprint ------------------------------

    public function testRulesVersionMarkerCarriesFingerprint(): void
    {
        $fp = NginxRules::settingsFingerprint($this->config(), $this->env());
        $out = NginxRules::generateServerFile($this->config(), $this->env());
        $this->assertStringContainsString('location = /magic-convert-rules-version {', $out);
        $this->assertStringContainsString('return 200 "' . $fp . '";', $out);
    }

    // --- FINGERPRINT: stable under irrelevant changes, changes under relevant -----

    public function testFingerprintStableUnderIrrelevantConfigChange(): void
    {
        $base = NginxRules::settingsFingerprint($this->config(), $this->env());

        // Irrelevant: AVIF quality/speed, converter stack, an unrelated UI flag.
        $irrelevant = $this->config();
        $irrelevant['formats']['avif']['quality'] = 45;
        $irrelevant['formats']['avif']['speed'] = 4;
        $irrelevant['converters'] = [['converter' => 'gd']];
        $irrelevant['some-ui-cosmetic'] = 'whatever';
        $irrelevant['cache-control'] = 'custom';

        $this->assertSame($base, NginxRules::settingsFingerprint($irrelevant, $this->env()));
    }

    public function testFingerprintChangesUnderRelevantConfigChange(): void
    {
        $base = NginxRules::settingsFingerprint($this->config(), $this->env());

        $cases = [
            ['destination-folder' => 'mingled'],
            ['destination-extension' => 'set'],
            ['destination-structure' => 'image-roots'],
            ['image-types' => 1],
            ['scope' => ['uploads']],
            ['redirect-to-existing-in-htaccess' => false],
            ['enable-redirection-to-converter' => false],
        ];
        foreach ($cases as $change) {
            $cfg = $this->config($change);
            $this->assertNotSame(
                $base,
                NginxRules::settingsFingerprint($cfg, $this->env()),
                'Fingerprint must change for: ' . json_encode($change)
            );
        }

        // avif enabled is a relevant change too.
        $this->assertNotSame($base, NginxRules::settingsFingerprint($this->avifOn($this->config()), $this->env()));
    }

    public function testFingerprintChangesWhenHashOrWpContentChanges(): void
    {
        $base = NginxRules::settingsFingerprint($this->config(), $this->env());

        $diffHash = NginxRules::settingsFingerprint($this->config(), $this->env(['configHash' => str_repeat('a', 32)]));
        $this->assertNotSame($base, $diffHash);

        $diffWpc = NginxRules::settingsFingerprint($this->config(), $this->env(['wpContentRelPath' => 'wp-content-moved']));
        $this->assertNotSame($base, $diffWpc);
    }

    public function testFingerprintIs32Hex(): void
    {
        $fp = NginxRules::settingsFingerprint($this->config(), $this->env());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fp);
    }

    /**
     * The standalone settingsFingerprint() (persisted to State / compared by the drift detector)
     * MUST equal the fingerprint baked into the generated file, even for a PARTIAL config that is
     * missing some fingerprintConfigKeys. model() merges rule defaults before baking the file
     * fingerprint, so settingsFingerprint() must merge the same defaults — otherwise the drift
     * detector reports a false mismatch. (Regression guard for the default-merge divergence.)
     */
    public function testStandaloneFingerprintMatchesFileForPartialConfig(): void
    {
        $env = $this->env();

        $partials = [
            'only-destination-folder'   => ['destination-folder' => 'separate'],
            'only-destination-folder-m' => ['destination-folder' => 'mingled'],
            'only-image-types'          => ['image-types' => 1],
            'missing-only-redirect-key' => [
                'destination-folder' => 'separate',
                'destination-extension' => 'append',
                'destination-structure' => 'doc-root',
                'image-types' => 3,
                'scope' => ['themes', 'uploads'],
                'redirect-to-existing-in-htaccess' => true,
                'enable-redirection-to-converter' => true,
                // 'only-redirect-to-converter-for-webp-enabled-browsers' intentionally omitted
            ],
            'empty-config'              => [],
        ];

        foreach ($partials as $label => $cfg) {
            $standalone = NginxRules::settingsFingerprint($cfg, $env);

            // Pull the fingerprint actually embedded in the generated file.
            $server = NginxRules::generateServerFile($cfg, $env);
            $this->assertSame(
                1,
                preg_match('/return 200 "([0-9a-f]{32})";/', $server, $m),
                "could not find embedded fingerprint for: $label"
            );
            $this->assertSame(
                $m[1],
                $standalone,
                "standalone fingerprint must match file-embedded fingerprint for partial config: $label"
            );
        }
    }

    // --- header comment block carries metadata + warnings ------------------------

    public function testHeaderCommentCarriesMetadataAndWarnings(): void
    {
        $out = NginxRules::generateServerFile($this->config(), $this->env());
        $this->assertStringContainsString('Generated:   2026-06-12 00:00:00 UTC', $out);
        $this->assertStringContainsString('Plugin ver.: 0.1.0', $out);
        $this->assertStringContainsString('Fingerprint: ', $out);
        // add_header replacement warning must be prominent.
        $this->assertStringContainsString('REPLACES any add_header directives inherited', $out);
        // security warning about keeping out of web-accessible path.
        $this->assertStringContainsString('out of any web-accessible path', $out);
    }

    // =================================================================================
    //  IMAGE-ROOTS structure (destination-structure = 'image-roots')
    // =================================================================================
    //
    // In image-roots mode the converted file lives under a PER-ROOT cache subtree keyed by the
    // image-root id and the source path RELATIVE TO THAT ROOT (see
    // ConvertHelperIndependent::getDestination + Paths::destinationRoot), NOT the full doc-root
    // path. So a single doc-root-shaped location can never hit it: each enabled root needs its own
    // location capturing the root-relative remainder and mapping it into that root's cache subtree.
    // These tests assert that shape (parity with HTAccessRules.php:449-465).

    private function imageRootsConfig(array $overrides = []): array
    {
        return $this->config(array_merge([
            'destination-structure' => 'image-roots',
            'destination-folder' => 'separate',
            'destination-extension' => 'append',
        ], $overrides));
    }

    /**
     * Helper: collect the try_files entries that appear INSIDE the location/named-location whose
     * opener line contains $needle (so we can assert per-root behaviour independently).
     *
     * @return string[]
     */
    private function tryEntriesForBlockContaining(string $out, string $needle): array
    {
        $lines = explode("\n", $out);
        // Find the opener line.
        $start = null;
        foreach ($lines as $i => $line) {
            if (strpos($line, $needle) !== false && strpos($line, 'location') !== false) {
                $start = $i;
                break;
            }
        }
        $this->assertNotNull($start, "could not find a location opener containing: $needle");

        $entries = [];
        $inTry = false;
        for ($i = $start; $i < count($lines); $i++) {
            $t = trim($lines[$i]);
            if ($t === 'try_files') {
                $inTry = true;
                continue;
            }
            if ($inTry) {
                if ($t === ';') {
                    break;
                }
                if ($t !== '') {
                    $entries[] = $t;
                }
            }
        }
        return $entries;
    }

    public function testImageRootsEmitsPerRootLocations(): void
    {
        $out = NginxRules::generateServerFile($this->imageRootsConfig(), $this->env());

        // One named-capture location per enabled root, anchored on the root's URL path.
        $this->assertMatchesRegularExpression(
            '#location ~\* "\^/\?wp-content/themes/\(\?<mc_rest_themes>\.\+\\\\\.\(\?:jpe\?g\|png\)\)\$"#',
            $out,
            'themes root location with named capture expected'
        );
        $this->assertMatchesRegularExpression(
            '#location ~\* "\^/\?wp-content/uploads/\(\?<mc_rest_uploads>\.\+\\\\\.\(\?:jpe\?g\|png\)\)\$"#',
            $out,
            'uploads root location with named capture expected'
        );

        // The doc-root single-location regex must NOT be present in image-roots mode.
        $this->assertStringNotContainsString('doc-root$uri', $out, 'no doc-root $uri cache path in image-roots mode');
    }

    public function testImageRootsCachePathMathPerRoot(): void
    {
        $out = NginxRules::generateServerFile($this->avifOn($this->imageRootsConfig()), $this->env());

        // themes root: cache subtree keyed by rootId + the named capture remainder.
        $themes = $this->tryEntriesForBlockContaining($out, 'mc_rest_themes');
        $this->assertContains('/wp-content/magic-convert/avif-images/themes/$mc_rest_themes$mc_avif_suffix', $themes);
        $this->assertContains('/wp-content/magic-convert/webp-images/themes/$mc_rest_themes$mc_webp_suffix', $themes);
        $this->assertContains('$uri', $themes, 'original $uri must be present per root');

        // uploads root: same shape, keyed by 'uploads'.
        $uploads = $this->tryEntriesForBlockContaining($out, 'mc_rest_uploads');
        $this->assertContains('/wp-content/magic-convert/avif-images/uploads/$mc_rest_uploads$mc_avif_suffix', $uploads);
        $this->assertContains('/wp-content/magic-convert/webp-images/uploads/$mc_rest_uploads$mc_webp_suffix', $uploads);
    }

    public function testImageRootsAvifPrecedesWebpPerRoot(): void
    {
        $out = NginxRules::generateServerFile($this->avifOn($this->imageRootsConfig()), $this->env());

        foreach (['themes', 'uploads'] as $rootId) {
            $entries = $this->tryEntriesForBlockContaining($out, 'mc_rest_' . $rootId);
            $avifIdx = null;
            $webpIdx = null;
            foreach ($entries as $i => $e) {
                if (strpos($e, 'avif-images/' . $rootId) !== false) { $avifIdx = $i; }
                if (strpos($e, 'webp-images/' . $rootId) !== false) { $webpIdx = $i; }
            }
            $this->assertNotNull($avifIdx, "avif entry expected for root $rootId");
            $this->assertNotNull($webpIdx, "webp entry expected for root $rootId");
            $this->assertLessThan($webpIdx, $avifIdx, "avif must precede webp for root $rootId");
        }
    }

    public function testImageRootsNoAvifContentWhenDisabled(): void
    {
        $out = NginxRules::generateServerFile($this->imageRootsConfig(), $this->env());
        $this->assertStringContainsString('webp-images/themes/$mc_rest_themes', $out, 'webp lookups still present');
        $this->assertStringNotContainsString('avif', $out, 'no avif token in image-roots mode when avif disabled');
        $this->assertStringNotContainsString('$mc_avif_suffix', $out);
    }

    /**
     * mingled + uploads: the converted file is written IN PLACE (sibling), exactly like
     * Paths::getCacheDirForImageRoot's mingled+uploads short-circuit. So the 'uploads' root must
     * use the in-place sibling lookup ($uri + suffix), NOT a cache-subtree lookup. A NON-uploads
     * mingled root (themes) still uses its cache subtree.
     */
    public function testImageRootsMingledUploadsUsesInPlaceSibling(): void
    {
        $cfg = $this->avifOn($this->imageRootsConfig(['destination-folder' => 'mingled']));
        $out = NginxRules::generateServerFile($cfg, $this->env());

        // uploads root: in-place siblings, NO cache-subtree lookup.
        $uploads = $this->tryEntriesForBlockContaining($out, 'mc_rest_uploads');
        $this->assertContains('$uri$mc_avif_suffix', $uploads, 'mingled uploads avif sibling expected');
        $this->assertContains('$uri$mc_webp_suffix', $uploads, 'mingled uploads webp sibling expected');
        $this->assertNotContains('/wp-content/magic-convert/webp-images/uploads/$mc_rest_uploads$mc_webp_suffix', $uploads,
            'mingled uploads must NOT use the cache subtree (file is written in place)');

        // themes root (non-uploads): still uses its cache subtree.
        $themes = $this->tryEntriesForBlockContaining($out, 'mc_rest_themes');
        $this->assertContains('/wp-content/magic-convert/webp-images/themes/$mc_rest_themes$mc_webp_suffix', $themes);
        $this->assertNotContains('$uri$mc_webp_suffix', $themes, 'themes (non-uploads) is not a sibling lookup');
    }

    /**
     * mingled + set + uploads in image-roots: the in-place file is <name>.webp (extension
     * REPLACED), which a try_files suffix append cannot express. The uploads lookup must be
     * SUPPRESSED with a per-root NOTE (never a silent wrong hit). themes (separate-from-uploads
     * cache subtree, always append) stays correct.
     */
    public function testImageRootsMingledSetUploadsSuppressedWithNote(): void
    {
        $cfg = $this->avifOn($this->imageRootsConfig([
            'destination-folder' => 'mingled',
            'destination-extension' => 'set',
        ]));
        $out = NginxRules::generateServerFile($cfg, $this->env());

        $this->assertStringContainsString("NOTE: image root 'uploads' with 'mingled' + 'set extension'", $out);

        // uploads block: no cache lookup AND no sibling lookup (only $uri remains, + converter).
        $uploads = $this->tryEntriesForBlockContaining($out, 'mc_rest_uploads');
        $this->assertNotContains('$uri$mc_webp_suffix', $uploads);
        $this->assertNotContains('/wp-content/magic-convert/webp-images/uploads/$mc_rest_uploads$mc_webp_suffix', $uploads);
        $this->assertContains('$uri', $uploads, 'original still served for the suppressed root');

        // themes still served from its cache subtree.
        $themes = $this->tryEntriesForBlockContaining($out, 'mc_rest_themes');
        $this->assertContains('/wp-content/magic-convert/webp-images/themes/$mc_rest_themes$mc_webp_suffix', $themes);
    }

    /**
     * Artifact B (single file) in image-roots: each root needs its OWN decider + its OWN
     * @mc_negotiate_<rootId> named location (a shared named location can't carry per-root captures).
     * The try_files must live in the named location (not with the 'if'): the "if inside location is
     * evil" avoidance, per root.
     */
    public function testImageRootsSingleFilePerRootNegotiateHandoff(): void
    {
        $out = NginxRules::generateSingleFile($this->avifOn($this->imageRootsConfig()), $this->env());

        foreach (['themes', 'uploads'] as $rootId) {
            $this->assertStringContainsString('error_page 418 = @mc_negotiate_' . $rootId . ';', $out);
            $this->assertStringContainsString('location @mc_negotiate_' . $rootId . ' {', $out);

            // The try_files for this root must appear AFTER its named-location opener (so it is not
            // in the decider location that holds the 'if').
            $negotiateStart = strpos($out, 'location @mc_negotiate_' . $rootId . ' {');
            $this->assertNotFalse($negotiateStart);
            $cachePos = strpos($out, 'webp-images/' . $rootId . '/$mc_rest_' . $rootId);
            $this->assertNotFalse($cachePos, "cache lookup for $rootId expected");
            $this->assertGreaterThan($negotiateStart, $cachePos,
                "try_files cache lookup for $rootId must be inside its @mc_negotiate, not the decider");
        }
    }

    /**
     * HONEST-UNSUPPORTED: image-roots mode with NO image roots supplied must emit an explicit
     * NOTE and NO per-root locations — never a silently wrong single doc-root-shaped location.
     */
    public function testImageRootsWithNoRootsSuppliedEmitsNote(): void
    {
        $env = $this->env(['imageRoots' => []]);
        $out = NginxRules::generateServerFile($this->imageRootsConfig(), $env);

        $this->assertStringContainsString('no image roots were', $out);
        $this->assertStringNotContainsString('mc_rest_', $out, 'no per-root capture locations when no roots supplied');
        $this->assertStringNotContainsString('doc-root$uri', $out, 'no doc-root cache lookup emitted in image-roots mode');
    }

    /**
     * Image-roots URL paths appear literally in the generated location regexes, so they are
     * rule-affecting: the fingerprint MUST change when an image root's URL path changes (drift
     * detection). In doc-root mode the same env change must NOT move the fingerprint.
     */
    public function testImageRootsUrlPathChangeMovesFingerprintOnlyInImageRootsMode(): void
    {
        $cfgIr = $this->imageRootsConfig();
        $base = NginxRules::settingsFingerprint($cfgIr, $this->env());
        $moved = NginxRules::settingsFingerprint($cfgIr, $this->env([
            'imageRoots' => [
                ['id' => 'themes', 'urlPath' => 'wp-content/themes'],
                ['id' => 'uploads', 'urlPath' => 'wp-content-moved/uploads-moved'],
            ],
        ]));
        $this->assertNotSame($base, $moved, 'image-roots URL path change must move the fingerprint');

        // Doc-root mode: image-root url paths are irrelevant to the rules, so the fingerprint
        // must be stable across them.
        $cfgDoc = $this->config(['destination-structure' => 'doc-root']);
        $docBase = NginxRules::settingsFingerprint($cfgDoc, $this->env());
        $docMoved = NginxRules::settingsFingerprint($cfgDoc, $this->env([
            'imageRoots' => [
                ['id' => 'themes', 'urlPath' => 'wp-content/themes'],
                ['id' => 'uploads', 'urlPath' => 'wp-content-moved/uploads-moved'],
            ],
        ]));
        $this->assertSame($docBase, $docMoved, 'doc-root fingerprint must not depend on image-root url paths');
    }
}
