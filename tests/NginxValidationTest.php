<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\NginxRules;

require_once __DIR__ . '/NginxHarness.php';

/**
 * Self-validation harness tests (dev-only, skip-gated for CI portability).
 *
 * Validates that BOTH artifacts (A: maps + server; B: single file) parse cleanly with
 * `nginx -t` across a representative slice of the config matrix, with avif on and off.
 * SKIPS when no nginx binary is present.
 */
class NginxValidationTest extends TestCase
{
    private function env(): array
    {
        return [
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
        ];
    }

    private function baseConfig(): array
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

    /**
     * Representative matrix points covering mingled/separate x doc-root/image-roots x
     * append/set x jpeg/png/both x converter on/off.
     *
     * @return array<string,array>
     */
    public static function matrixProvider(): array
    {
        $base = [
            'destination-folder' => 'separate',
            'destination-extension' => 'append',
            'destination-structure' => 'doc-root',
            'image-types' => 3,
            'scope' => ['themes', 'uploads'],
            'redirect-to-existing-in-htaccess' => true,
            'enable-redirection-to-converter' => true,
            'only-redirect-to-converter-for-webp-enabled-browsers' => true,
        ];
        $mk = function (array $o) use ($base) { return [array_merge($base, $o)]; };

        return [
            'separate/doc-root/append/both'        => $mk([]),
            'mingled/doc-root/append/both'         => $mk(['destination-folder' => 'mingled']),
            'mingled/doc-root/set/both'            => $mk(['destination-folder' => 'mingled', 'destination-extension' => 'set']),
            'separate/image-roots/append/both'     => $mk(['destination-structure' => 'image-roots']),
            'mingled/image-roots/append/both'      => $mk(['destination-structure' => 'image-roots', 'destination-folder' => 'mingled']),
            'mingled/image-roots/set/both'         => $mk(['destination-structure' => 'image-roots', 'destination-folder' => 'mingled', 'destination-extension' => 'set']),
            'separate/image-roots/no-converter'    => $mk(['destination-structure' => 'image-roots', 'enable-redirection-to-converter' => false]),
            'separate/image-roots/jpeg-only'       => $mk(['destination-structure' => 'image-roots', 'image-types' => 1]),
            'separate/doc-root/append/jpeg-only'   => $mk(['image-types' => 1]),
            'separate/doc-root/append/png-only'    => $mk(['image-types' => 2]),
            'separate/doc-root/no-converter'       => $mk(['enable-redirection-to-converter' => false]),
        ];
    }

    /**
     * @dataProvider matrixProvider
     */
    public function testArtifactsParseAcrossMatrix(array $config): void
    {
        $harness = new NginxHarness();
        if (!$harness->available()) {
            $harness->cleanup();
            $this->markTestSkipped('nginx binary not found — skipping live -t validation.');
        }

        try {
            foreach ([false, true] as $avif) {
                $cfg = $config;
                if ($avif) {
                    $cfg['formats']['avif']['enabled'] = true;
                }
                $env = $this->env();

                // Artifact A: maps (http) + server (server).
                $maps = NginxRules::generateMapsFile($cfg, $env);
                $server = NginxRules::generateServerFile($cfg, $env);
                $harness->writeConf($maps, $server);
                [$codeA, $outA] = $harness->test();
                $this->assertSame(0, $codeA, "Artifact A failed nginx -t (avif=" . var_export($avif, true) . "):\n" . $outA);

                // Artifact B: single file (server only, no maps include).
                $single = NginxRules::generateSingleFile($cfg, $env);
                $harness->writeConf('', $single);
                [$codeB, $outB] = $harness->test();
                $this->assertSame(0, $codeB, "Artifact B failed nginx -t (avif=" . var_export($avif, true) . "):\n" . $outB);
            }
        } finally {
            $harness->cleanup();
        }
    }
}
