<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\NginxRules;

class NginxRulesTest extends TestCase
{
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

    public function testSingleFileUsesNegotiateHandoffNotIfWithTryFiles(): void
    {
        $out = NginxRules::generateSingleFile($this->avifOn($this->config()), $this->env());

        $this->assertStringContainsString('error_page 418 = @mc_negotiate;', $out);
        $this->assertStringContainsString('return 418;', $out);
        $this->assertStringContainsString('location @mc_negotiate {', $out);
        $this->assertStringContainsString('internal;', $out);

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

    public function testTryEntriesUseSuffixVariables(): void
    {
        $cfg = $this->avifOn($this->config());
        $serverA = NginxRules::generateServerFile($cfg, $this->env());
        $this->assertStringContainsString('$uri$mc_avif_suffix', $serverA);
        $this->assertStringContainsString('$uri$mc_webp_suffix', $serverA);

        $single = NginxRules::generateSingleFile($cfg, $this->env());
        $this->assertStringContainsString('set $mc_avif_suffix ".mc-no-avif";', $single);
        $this->assertStringContainsString('if ($http_accept ~* "image/avif") { set $mc_avif_suffix ".avif"; }', $single);
        $this->assertStringContainsString('set $mc_webp_suffix ".mc-no-webp";', $single);
        $this->assertStringContainsString('if ($http_accept ~* "image/webp") { set $mc_webp_suffix ".webp"; }', $single);
    }

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

    /**
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
                $this->assertContains('$uri', $entries, "$label: original \$uri entry must be present");
                $this->assertSame('$uri', $entries[count($entries) - 2], "$label: \$uri must precede the =404 terminal");
            }
        }
    }

    public function testTryFilesEndsOnConverterUriWhenConverterEnabled(): void
    {
        $out = NginxRules::generateServerFile($this->config(), $this->env());
        $entries = $this->tryFilesEntries($out);
        $last = end($entries);
        $this->assertStringContainsString('webp-on-demand.php', $last, 'converter URI must terminate the chain');
        $this->assertNotContains('=404', $entries, 'no synthetic =404 terminal when the converter terminates the chain');
    }

    public function testRedirectToExistingGatesCacheAndSiblingEntries(): void
    {
        $base = $this->avifOn($this->config(['destination-folder' => 'mingled', 'destination-extension' => 'append']));

        $on = NginxRules::generateServerFile(array_merge($base, ['redirect-to-existing-in-htaccess' => true]), $this->env());
        $onEntries = $this->tryFilesEntries($on);
        $this->assertContains('/wp-content/magic-convert/avif-images/doc-root$uri$mc_avif_suffix', $onEntries);
        $this->assertContains('/wp-content/magic-convert/webp-images/doc-root$uri$mc_webp_suffix', $onEntries);
        $this->assertContains('$uri$mc_avif_suffix', $onEntries);
        $this->assertContains('$uri$mc_webp_suffix', $onEntries);

        $off = NginxRules::generateServerFile(array_merge($base, ['redirect-to-existing-in-htaccess' => false]), $this->env());
        $offEntries = $this->tryFilesEntries($off);
        $this->assertNotContains('/wp-content/magic-convert/avif-images/doc-root$uri$mc_avif_suffix', $offEntries);
        $this->assertNotContains('/wp-content/magic-convert/webp-images/doc-root$uri$mc_webp_suffix', $offEntries);
        $this->assertNotContains('$uri$mc_avif_suffix', $offEntries);
        $this->assertNotContains('$uri$mc_webp_suffix', $offEntries);
        $this->assertContains('$uri', $offEntries, 'the original $uri entry must remain when redirect-to-existing is off');
    }

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

    public function testMingledAppendAddsSiblingEntry(): void
    {
        $cfg = $this->config(['destination-folder' => 'mingled', 'destination-extension' => 'append']);
        $out = NginxRules::generateServerFile($cfg, $this->env());
        $this->assertMatchesRegularExpression('/\n\s+\$uri\$mc_webp_suffix\n/', $out);
        $this->assertStringNotContainsString('NOTE:', $out);
    }

    public function testMingledSetEmitsUnsupportedNoteAndDropsSibling(): void
    {
        $cfg = $this->config(['destination-folder' => 'mingled', 'destination-extension' => 'set']);
        $out = NginxRules::generateServerFile($cfg, $this->env());
        $this->assertStringContainsString("NOTE: 'mingled' + 'set extension'", $out);
        $this->assertDoesNotMatchRegularExpression('/\n\s+\$uri\$mc_webp_suffix\n/', $out);
        $this->assertStringContainsString('webp-images/doc-root$uri$mc_webp_suffix', $out);
    }

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

    public function testRulesVersionMarkerCarriesFingerprint(): void
    {
        $fp = NginxRules::settingsFingerprint($this->config(), $this->env());
        $out = NginxRules::generateServerFile($this->config(), $this->env());
        $this->assertStringContainsString('location = /magic-convert-rules-version {', $out);
        $this->assertStringContainsString('return 200 "' . $fp . '";', $out);
    }

    public function testFingerprintStableUnderIrrelevantConfigChange(): void
    {
        $base = NginxRules::settingsFingerprint($this->config(), $this->env());

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
            ],
            'empty-config'              => [],
        ];

        foreach ($partials as $label => $cfg) {
            $standalone = NginxRules::settingsFingerprint($cfg, $env);

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

    public function testHeaderCommentCarriesMetadataAndWarnings(): void
    {
        $out = NginxRules::generateServerFile($this->config(), $this->env());
        $this->assertStringContainsString('Generated:   2026-06-12 00:00:00 UTC', $out);
        $this->assertStringContainsString('Plugin ver.: 0.1.0', $out);
        $this->assertStringContainsString('Fingerprint: ', $out);
        $this->assertStringContainsString('REPLACES any add_header directives inherited', $out);
        $this->assertStringContainsString('out of any web-accessible path', $out);
    }

    private function imageRootsConfig(array $overrides = []): array
    {
        return $this->config(array_merge([
            'destination-structure' => 'image-roots',
            'destination-folder' => 'separate',
            'destination-extension' => 'append',
        ], $overrides));
    }

    /**
     * @return string[]
     */
    private function tryEntriesForBlockContaining(string $out, string $needle): array
    {
        $lines = explode("\n", $out);
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

        $this->assertStringNotContainsString('doc-root$uri', $out, 'no doc-root $uri cache path in image-roots mode');
    }

    public function testImageRootsCachePathMathPerRoot(): void
    {
        $out = NginxRules::generateServerFile($this->avifOn($this->imageRootsConfig()), $this->env());

        $themes = $this->tryEntriesForBlockContaining($out, 'mc_rest_themes');
        $this->assertContains('/wp-content/magic-convert/avif-images/themes/$mc_rest_themes$mc_avif_suffix', $themes);
        $this->assertContains('/wp-content/magic-convert/webp-images/themes/$mc_rest_themes$mc_webp_suffix', $themes);
        $this->assertContains('$uri', $themes, 'original $uri must be present per root');

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

    public function testImageRootsMingledUploadsUsesInPlaceSibling(): void
    {
        $cfg = $this->avifOn($this->imageRootsConfig(['destination-folder' => 'mingled']));
        $out = NginxRules::generateServerFile($cfg, $this->env());

        $uploads = $this->tryEntriesForBlockContaining($out, 'mc_rest_uploads');
        $this->assertContains('$uri$mc_avif_suffix', $uploads, 'mingled uploads avif sibling expected');
        $this->assertContains('$uri$mc_webp_suffix', $uploads, 'mingled uploads webp sibling expected');
        $this->assertNotContains('/wp-content/magic-convert/webp-images/uploads/$mc_rest_uploads$mc_webp_suffix', $uploads,
            'mingled uploads must NOT use the cache subtree (file is written in place)');

        $themes = $this->tryEntriesForBlockContaining($out, 'mc_rest_themes');
        $this->assertContains('/wp-content/magic-convert/webp-images/themes/$mc_rest_themes$mc_webp_suffix', $themes);
        $this->assertNotContains('$uri$mc_webp_suffix', $themes, 'themes (non-uploads) is not a sibling lookup');
    }

    public function testImageRootsMingledSetUploadsSuppressedWithNote(): void
    {
        $cfg = $this->avifOn($this->imageRootsConfig([
            'destination-folder' => 'mingled',
            'destination-extension' => 'set',
        ]));
        $out = NginxRules::generateServerFile($cfg, $this->env());

        $this->assertStringContainsString("NOTE: image root 'uploads' with 'mingled' + 'set extension'", $out);

        $uploads = $this->tryEntriesForBlockContaining($out, 'mc_rest_uploads');
        $this->assertNotContains('$uri$mc_webp_suffix', $uploads);
        $this->assertNotContains('/wp-content/magic-convert/webp-images/uploads/$mc_rest_uploads$mc_webp_suffix', $uploads);
        $this->assertContains('$uri', $uploads, 'original still served for the suppressed root');

        $themes = $this->tryEntriesForBlockContaining($out, 'mc_rest_themes');
        $this->assertContains('/wp-content/magic-convert/webp-images/themes/$mc_rest_themes$mc_webp_suffix', $themes);
    }

    public function testImageRootsSingleFilePerRootNegotiateHandoff(): void
    {
        $out = NginxRules::generateSingleFile($this->avifOn($this->imageRootsConfig()), $this->env());

        foreach (['themes', 'uploads'] as $rootId) {
            $this->assertStringContainsString('error_page 418 = @mc_negotiate_' . $rootId . ';', $out);
            $this->assertStringContainsString('location @mc_negotiate_' . $rootId . ' {', $out);

            $negotiateStart = strpos($out, 'location @mc_negotiate_' . $rootId . ' {');
            $this->assertNotFalse($negotiateStart);
            $cachePos = strpos($out, 'webp-images/' . $rootId . '/$mc_rest_' . $rootId);
            $this->assertNotFalse($cachePos, "cache lookup for $rootId expected");
            $this->assertGreaterThan($negotiateStart, $cachePos,
                "try_files cache lookup for $rootId must be inside its @mc_negotiate, not the decider");
        }
    }

    public function testImageRootsWithNoRootsSuppliedEmitsNote(): void
    {
        $env = $this->env(['imageRoots' => []]);
        $out = NginxRules::generateServerFile($this->imageRootsConfig(), $env);

        $this->assertStringContainsString('no image roots were', $out);
        $this->assertStringNotContainsString('mc_rest_', $out, 'no per-root capture locations when no roots supplied');
        $this->assertStringNotContainsString('doc-root$uri', $out, 'no doc-root cache lookup emitted in image-roots mode');
    }

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
