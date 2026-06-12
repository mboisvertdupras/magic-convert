<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\NginxRules;
use MagicConvert\SelfTestNginx;

require_once __DIR__ . '/NginxHarness.php';

/**
 * Functional test (local, skip-gated). Boots nginx on 127.0.0.1:18999 with a temp docroot
 * containing test.jpg + test.jpg.webp + test.jpg.avif (distinct, known sizes), then performs
 * HTTP GETs with different Accept headers and asserts the correct format is served.
 *
 * What this proves (for BOTH artifacts — maps+server AND the single file):
 *   - Accept: image/avif,image/webp  -> serves the .avif bytes
 *   - Accept: image/webp             -> serves the .webp bytes
 *   - Accept (neither)               -> serves the original .jpg bytes
 *   - Vary: Accept header is present
 *   - /magic-convert-rules-version returns the fingerprint
 *
 * Artifact B (the single-file fallback) is booted with fixtures at the REAL doc-root cache
 * location (separate/doc-root, the GridPane/RunCloud/Plesk path) and curled with each Accept
 * header — this is the regression guard for the "if inside location is evil" defect, where the
 * single file passed `nginx -t` yet always served the original image regardless of Accept.
 *
 * The converter-fallback location is syntax-validated only (no PHP-FPM here); its try_files
 * final-URI entry still parses (covered by NginxValidationTest), and since the cached files
 * exist, the converter entry is never reached in this functional flow.
 *
 * SKIPS when no nginx binary is present.
 */
class NginxFunctionalTest extends TestCase
{
    /** @var NginxHarness|null */
    private $harness;

    // Distinct sizes so content-length uniquely identifies which fixture was served.
    private const JPG_BODY  = 'JPEGFIXTURE';                 // 11 bytes
    private const WEBP_BODY = 'WEBPFIXTUREBYTES-XX';         // 19 bytes
    private const AVIF_BODY = 'AVIFFIXTUREBYTES-LONGER-PADD'; // 28 bytes

    protected function tearDown(): void
    {
        if ($this->harness !== null) {
            $this->harness->cleanup();
            $this->harness = null;
        }
    }

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

    private function config(): array
    {
        return [
            'destination-folder' => 'mingled',     // mingled+append => same-dir siblings work
            'destination-extension' => 'append',
            'destination-structure' => 'doc-root',
            'image-types' => 3,
            'scope' => ['themes', 'uploads'],
            'redirect-to-existing-in-htaccess' => true,
            'enable-redirection-to-converter' => true,
            'only-redirect-to-converter-for-webp-enabled-browsers' => true,
            'formats' => ['avif' => ['enabled' => true]],
        ];
    }

    /**
     * Config for the single-file (Artifact B) path: separate/doc-root — the GridPane/RunCloud/
     * Plesk shape, where the converted files live in the doc-root cache dir (not as siblings).
     */
    private function singleFileConfig(): array
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
            'formats' => ['avif' => ['enabled' => true]],
        ];
    }

    /**
     * Config with the converter DISABLED (enable-redirection-to-converter => false) — a supported,
     * fingerprinted matrix point. This is the regression case for the bare-$uri terminal defect:
     * with no converter URI to terminate the try_files chain, $uri used to be the LAST argument,
     * which nginx treats as an internal-redirect target -> rewrite/redirect cycle (HTTP 500 for the
     * maps+server artifact, a stalled 418 with empty body for the single-file artifact) whenever a
     * request reached the original (e.g. the browser accepts neither avif nor webp). separate/
     * doc-root so the converted files live in the doc-root cache dir (works for both artifacts).
     */
    private function noConverterConfig(): array
    {
        return [
            'destination-folder' => 'separate',
            'destination-extension' => 'append',
            'destination-structure' => 'doc-root',
            'image-types' => 3,
            'scope' => ['themes', 'uploads'],
            'redirect-to-existing-in-htaccess' => true,
            'enable-redirection-to-converter' => false,
            'only-redirect-to-converter-for-webp-enabled-browsers' => true,
            'formats' => ['avif' => ['enabled' => true]],
        ];
    }

    /**
     * Config for the image-roots structure (separate, avif on). The converted files live under a
     * PER-ROOT cache subtree keyed by the image-root id + root-relative remainder — NOT the full
     * doc-root path. This is the regression case for the silently-unsupported image-roots gap.
     */
    private function imageRootsConfig(): array
    {
        return [
            'destination-folder' => 'separate',
            'destination-extension' => 'append',
            'destination-structure' => 'image-roots',
            'image-types' => 3,
            'scope' => ['themes', 'uploads'],
            'redirect-to-existing-in-htaccess' => true,
            'enable-redirection-to-converter' => true,
            'only-redirect-to-converter-for-webp-enabled-browsers' => true,
            'formats' => ['avif' => ['enabled' => true]],
        ];
    }

    /**
     * Lay down test.jpg + test.jpg.webp + test.jpg.avif under wp-content/uploads in the docroot.
     */
    private function placeFixtures(NginxHarness $h): string
    {
        $dir = $h->docroot . '/wp-content/uploads';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/test.jpg', self::JPG_BODY);
        file_put_contents($dir . '/test.jpg.webp', self::WEBP_BODY);
        file_put_contents($dir . '/test.jpg.avif', self::AVIF_BODY);
        return '/wp-content/uploads/test.jpg';
    }

    /**
     * Lay down the original under wp-content/uploads and the converted webp/avif at the REAL
     * doc-root cache location (separate/doc-root): /wp-content/magic-convert/<fmt>-images/doc-root
     * + the full source URI. This mirrors where the plugin actually writes for this mode, so the
     * single-file artifact's try_files cache entries must hit.
     */
    private function placeDocRootCacheFixtures(NginxHarness $h): string
    {
        $src = $h->docroot . '/wp-content/uploads';
        @mkdir($src, 0777, true);
        file_put_contents($src . '/test.jpg', self::JPG_BODY);

        $webpDir = $h->docroot . '/wp-content/magic-convert/webp-images/doc-root/wp-content/uploads';
        $avifDir = $h->docroot . '/wp-content/magic-convert/avif-images/doc-root/wp-content/uploads';
        @mkdir($webpDir, 0777, true);
        @mkdir($avifDir, 0777, true);
        file_put_contents($webpDir . '/test.jpg.webp', self::WEBP_BODY);
        file_put_contents($avifDir . '/test.jpg.avif', self::AVIF_BODY);

        return '/wp-content/uploads/test.jpg';
    }

    /**
     * Lay down the original under wp-content/uploads/<sub> and the converted webp/avif at the REAL
     * IMAGE-ROOTS cache location for the 'uploads' root:
     *   /wp-content/magic-convert/<fmt>-images/uploads/<remainder>.<ext>
     * where <remainder> is the source path RELATIVE TO THE uploads root (NOT the full doc-root
     * path). This mirrors exactly where the plugin writes in image-roots mode (see
     * ConvertHelperIndependent::getDestination + Paths::destinationRoot), so the per-root location's
     * try_files cache entries must hit. A nested sub-path is used on purpose so the named-capture
     * remainder ($mc_rest_uploads) is exercised non-trivially.
     */
    private function placeImageRootsUploadsCacheFixtures(NginxHarness $h): string
    {
        $src = $h->docroot . '/wp-content/uploads/2024/05';
        @mkdir($src, 0777, true);
        file_put_contents($src . '/test.jpg', self::JPG_BODY);

        // Per-root cache subtree, keyed by the 'uploads' rootId + the root-relative remainder.
        $webpDir = $h->docroot . '/wp-content/magic-convert/webp-images/uploads/2024/05';
        $avifDir = $h->docroot . '/wp-content/magic-convert/avif-images/uploads/2024/05';
        @mkdir($webpDir, 0777, true);
        @mkdir($avifDir, 0777, true);
        file_put_contents($webpDir . '/test.jpg.webp', self::WEBP_BODY);
        file_put_contents($avifDir . '/test.jpg.avif', self::AVIF_BODY);

        return '/wp-content/uploads/2024/05/test.jpg';
    }

    /**
     * FUNCTIONAL boot test for IMAGE-ROOTS mode (the closed gap). Boots BOTH artifacts with a
     * fixture at the REAL image-roots cache location for the uploads root and asserts
     * Accept-negotiated serving through booted nginx — the same harness as the doc-root tests.
     * Before the fix, image-roots configs generated try_files containing only $uri + the converter
     * entry, so these cache files were never served (silently unsupported). This proves the
     * per-root location + named-capture cache lookup actually hits.
     */
    public function testImageRootsServesNegotiatedFormatByAccept(): void
    {
        foreach (['A' => true, 'B' => false] as $label => $useMaps) {
            $harness = new NginxHarness();
            $this->harness = $harness;
            if (!$harness->available()) {
                $harness->cleanup();
                $this->harness = null;
                $this->markTestSkipped('nginx binary not found — skipping image-roots functional serve test.');
            }

            $cfg = $this->imageRootsConfig();
            $env = $this->env();

            if ($useMaps) {
                $maps = NginxRules::generateMapsFile($cfg, $env);
                $server = NginxRules::generateServerFile($cfg, $env);
                $harness->writeConf($maps, $server);
            } else {
                $single = NginxRules::generateSingleFile($cfg, $env);
                $harness->writeConf('', $single);
            }

            [$tCode, $tOut] = $harness->test();
            $this->assertSame(0, $tCode, "image-roots Artifact $label failed nginx -t:\n" . $tOut);

            $path = $this->placeImageRootsUploadsCacheFixtures($harness);
            $this->assertTrue($harness->start(), "nginx failed to boot for image-roots Artifact $label");

            // 1) avif preferred when both accepted — the per-root cache hit, NOT the original.
            [$status, $headers, $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
            $this->assertSame(200, $status, "image-roots $label: avif+webp status");
            $this->assertSame(self::AVIF_BODY, $body, "image-roots $label: avif bytes expected from the per-root cache subtree");
            $this->assertSame('image/avif', $headers['content-type'] ?? '', "image-roots $label: avif content-type");
            $this->assertSame('Accept', $headers['vary'] ?? '', "image-roots $label: Vary: Accept must be present");

            // 2) webp when only webp accepted.
            [$status, $headers, $body] = $harness->get($path, 'image/webp,*/*');
            $this->assertSame(200, $status, "image-roots $label: webp status");
            $this->assertSame(self::WEBP_BODY, $body, "image-roots $label: webp bytes expected from the per-root cache subtree");
            $this->assertSame('image/webp', $headers['content-type'] ?? '', "image-roots $label: webp content-type");

            // 3) original jpg when neither accepted.
            [$status, $headers, $body] = $harness->get($path, 'text/html');
            $this->assertSame(200, $status, "image-roots $label: neither-accepted status");
            $this->assertSame(self::JPG_BODY, $body, "image-roots $label: original jpg bytes expected when neither format accepted");
            $this->assertSame('image/jpeg', $headers['content-type'] ?? '', "image-roots $label: jpeg content-type");

            // 4) rules-version marker returns the fingerprint.
            $fp = NginxRules::settingsFingerprint($cfg, $env);
            [$status, , $body] = $harness->get('/magic-convert-rules-version', '*/*');
            $this->assertSame(200, $status, "image-roots $label: rules-version status");
            $this->assertSame($fp, $body, "image-roots $label: /magic-convert-rules-version must return the fingerprint");

            $harness->cleanup();
            $this->harness = null;
        }
    }

    /**
     * Phase 3.3 end-to-end: boots Artifact A and drives the live self-test's drift classifier
     * (SelfTestNginx::classifyDrift) AND the preference-ordering classifier (classifyFetch) against
     * the REAL booted nginx version endpoint + a negotiated fetch. This ties the pure 3.3 logic to a
     * live server: the /magic-convert-rules-version body must classify UP_TO_DATE against the
     * matching fingerprint and STALE against a different one, and an avif+webp fetch must classify as
     * SERVED_AVIF (preference correct), not SERVED_WEBP.
     *
     * @return void
     */
    public function testLiveDriftAndPreferenceClassification(): void
    {
        $harness = new NginxHarness();
        $this->harness = $harness;
        if (!$harness->available()) {
            $this->markTestSkipped('nginx binary not found — skipping live drift/preference classification test.');
        }

        $cfg = $this->config();
        $env = $this->env();
        $maps = NginxRules::generateMapsFile($cfg, $env);
        $server = NginxRules::generateServerFile($cfg, $env);
        $harness->writeConf($maps, $server);
        [$tCode, $tOut] = $harness->test();
        $this->assertSame(0, $tCode, "drift/preference conf failed nginx -t:\n" . $tOut);

        $path = $this->placeFixtures($harness);
        $this->assertTrue($harness->start(), 'nginx failed to boot for live drift/preference test');

        $fp = NginxRules::settingsFingerprint($cfg, $env);

        // --- Drift: GET the version endpoint, classify against matching + mismatching fingerprints.
        [$vStatus, , $vBody] = $harness->get('/magic-convert-rules-version', '*/*');
        $this->assertSame(200, $vStatus, 'version endpoint must respond 200');

        $this->assertSame(
            SelfTestNginx::DRIFT_UP_TO_DATE,
            SelfTestNginx::classifyDrift(true, $vBody, $fp),
            'installed version endpoint must classify as up-to-date against the matching fingerprint'
        );
        $this->assertSame(
            SelfTestNginx::DRIFT_STALE,
            SelfTestNginx::classifyDrift(true, $vBody, $fp . 'X'),
            'a changed fingerprint must classify the installed rules as stale'
        );

        // --- Preference ordering: avif+webp Accept must classify as SERVED_AVIF (not webp).
        [$aStatus, $aHeaders, ] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $aStatus);
        $lengths = [
            'avif'     => strlen(self::AVIF_BODY),
            'webp'     => strlen(self::WEBP_BODY),
            'original' => strlen(self::JPG_BODY),
        ];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_AVIF,
            SelfTestNginx::classifyFetch('avif', true, $aHeaders, $lengths, 'jpeg'),
            'avif+webp Accept must classify as SERVED_AVIF — proving avif precedes webp (preference)'
        );

        // --- webp-only Accept must classify as SERVED_WEBP.
        [, $wHeaders, ] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_WEBP,
            SelfTestNginx::classifyFetch('webp', true, $wHeaders, $lengths, 'jpeg')
        );
    }

    public function testServesNegotiatedFormatByAccept(): void
    {
        $harness = new NginxHarness();
        $this->harness = $harness;
        if (!$harness->available()) {
            $this->markTestSkipped('nginx binary not found — skipping functional serve test.');
        }

        $cfg = $this->config();
        $env = $this->env();
        $maps = NginxRules::generateMapsFile($cfg, $env);
        $server = NginxRules::generateServerFile($cfg, $env);

        // Sanity: the conf must pass -t before we try to boot.
        $harness->writeConf($maps, $server);
        [$tCode, $tOut] = $harness->test();
        $this->assertSame(0, $tCode, "Generated conf failed nginx -t:\n" . $tOut);

        $path = $this->placeFixtures($harness);

        $this->assertTrue($harness->start(), 'nginx failed to boot for functional test');

        // 1) avif preferred when both accepted.
        [$status, $headers, $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::AVIF_BODY, $body, 'avif bytes expected for avif+webp Accept');
        $this->assertSame((string) strlen(self::AVIF_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/avif', $headers['content-type'] ?? '', 'content-type from local types{} block');
        $this->assertSame('Accept', $headers['vary'] ?? '', 'Vary: Accept must be present');

        // 2) webp when only webp accepted.
        [$status, $headers, $body] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::WEBP_BODY, $body, 'webp bytes expected for webp-only Accept');
        $this->assertSame((string) strlen(self::WEBP_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/webp', $headers['content-type'] ?? '');
        $this->assertSame('Accept', $headers['vary'] ?? '');

        // 3) original jpg when neither accepted.
        [$status, $headers, $body] = $harness->get($path, 'text/html');
        $this->assertSame(200, $status);
        $this->assertSame(self::JPG_BODY, $body, 'original jpg bytes expected when neither format accepted');
        $this->assertSame((string) strlen(self::JPG_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');

        // 4) rules-version marker returns the fingerprint.
        $fp = NginxRules::settingsFingerprint($cfg, $env);
        [$status, $headers, $body] = $harness->get('/magic-convert-rules-version', '*/*');
        $this->assertSame(200, $status);
        $this->assertSame($fp, $body, '/magic-convert-rules-version must return the settings fingerprint');
    }

    /**
     * Boots Artifact B (the single-file fallback) and curls it with each Accept header. This is
     * the regression guard for the "if inside location is evil" defect: the single file passes
     * `nginx -t` but, with the naive set/if-then-try_files-in-one-location shape, served the
     * ORIGINAL image regardless of Accept. With the decider + @mc_negotiate handoff it must
     * negotiate avif/webp/original correctly, exactly like Artifact A.
     */
    public function testSingleFileServesNegotiatedFormatByAccept(): void
    {
        $harness = new NginxHarness();
        $this->harness = $harness;
        if (!$harness->available()) {
            $this->markTestSkipped('nginx binary not found — skipping single-file functional serve test.');
        }

        $cfg = $this->singleFileConfig();
        $env = $this->env();
        $single = NginxRules::generateSingleFile($cfg, $env);

        // Sanity: the single file must pass -t before we try to boot (server context only, no maps).
        $harness->writeConf('', $single);
        [$tCode, $tOut] = $harness->test();
        $this->assertSame(0, $tCode, "Generated single-file conf failed nginx -t:\n" . $tOut);

        $path = $this->placeDocRootCacheFixtures($harness);

        $this->assertTrue($harness->start(), 'nginx failed to boot for single-file functional test');

        // 1) avif preferred when both accepted (the cache hit, NOT the original).
        [$status, $headers, $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::AVIF_BODY, $body, 'single file: avif bytes expected for avif+webp Accept (not the original)');
        $this->assertSame((string) strlen(self::AVIF_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/avif', $headers['content-type'] ?? '', 'content-type from local types{} block');
        $this->assertSame('Accept', $headers['vary'] ?? '', 'Vary: Accept must be present');

        // 2) webp when only webp accepted.
        [$status, $headers, $body] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::WEBP_BODY, $body, 'single file: webp bytes expected for webp-only Accept (not the original)');
        $this->assertSame((string) strlen(self::WEBP_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/webp', $headers['content-type'] ?? '');
        $this->assertSame('Accept', $headers['vary'] ?? '');

        // 3) original jpg when neither accepted.
        [$status, $headers, $body] = $harness->get($path, 'text/html');
        $this->assertSame(200, $status);
        $this->assertSame(self::JPG_BODY, $body, 'single file: original jpg bytes expected when neither format accepted');
        $this->assertSame((string) strlen(self::JPG_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');

        // 4) rules-version marker returns the fingerprint (drift detection over HTTP).
        $fp = NginxRules::settingsFingerprint($cfg, $env);
        [$status, $headers, $body] = $harness->get('/magic-convert-rules-version', '*/*');
        $this->assertSame(200, $status);
        $this->assertSame($fp, $body, 'single file: /magic-convert-rules-version must return the settings fingerprint');
    }

    /**
     * REGRESSION GUARD for the converter-DISABLED matrix point (the HTTP 500 / 418-empty defect).
     *
     * Every other boot+curl test here runs with enable-redirection-to-converter => true, so the
     * converter URI was always the terminal try_files entry and the bare-$uri-as-last-arg cycle
     * never surfaced; the no-converter point was validated by `nginx -t` ONLY, which passes because
     * the config is syntactically valid. This boots BOTH artifacts with the converter OFF and curls
     * each Accept header. The critical assertion is the third one: when the browser accepts NEITHER
     * format the ORIGINAL .jpg MUST be served (HTTP 200), not a rewrite-cycle 500 (maps+server) or a
     * stalled 418 with an empty body (single file).
     *
     * @return void
     */
    public function testConverterDisabledServesOriginalWhenNeitherFormatAccepted(): void
    {
        $harness = new NginxHarness();
        $this->harness = $harness;
        if (!$harness->available()) {
            $this->markTestSkipped('nginx binary not found — skipping converter-disabled functional serve test.');
        }

        $cfg = $this->noConverterConfig();
        $env = $this->env();

        // Sanity: converter is genuinely absent from both artifacts.
        $server = NginxRules::generateServerFile($cfg, $env);
        $single = NginxRules::generateSingleFile($cfg, $env);
        $this->assertStringNotContainsString('webp-on-demand.php', $server, 'converter must be absent (server)');
        $this->assertStringNotContainsString('webp-on-demand.php', $single, 'converter must be absent (single)');

        // ---- Artifact A: maps + server ----
        $maps = NginxRules::generateMapsFile($cfg, $env);
        $harness->writeConf($maps, $server);
        [$tCode, $tOut] = $harness->test();
        $this->assertSame(0, $tCode, "converter-off Artifact A failed nginx -t:\n" . $tOut);

        $path = $this->placeDocRootCacheFixtures($harness);
        $this->assertTrue($harness->start(), 'nginx failed to boot for converter-off Artifact A');

        // avif/webp still negotiated from the existing cache (redirect-to-existing is on).
        [$status, , $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::AVIF_BODY, $body, 'A converter-off: avif bytes expected for avif+webp Accept');

        [$status, , $body] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::WEBP_BODY, $body, 'A converter-off: webp bytes expected for webp-only Accept');

        // THE BUG CASE: neither format accepted -> must serve the ORIGINAL (was a 500 rewrite cycle).
        [$status, $headers, $body] = $harness->get($path, 'text/html');
        $this->assertSame(200, $status, 'A converter-off: neither-accepted must serve the original (NOT a 500 rewrite cycle)');
        $this->assertSame(self::JPG_BODY, $body, 'A converter-off: original jpg bytes expected when neither format accepted');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');

        $harness->cleanup();
        $this->harness = null;

        // ---- Artifact B: single file ----
        $harnessB = new NginxHarness();
        $this->harness = $harnessB;
        $harnessB->writeConf('', $single);
        [$tCode, $tOut] = $harnessB->test();
        $this->assertSame(0, $tCode, "converter-off Artifact B failed nginx -t:\n" . $tOut);

        $path = $this->placeDocRootCacheFixtures($harnessB);
        $this->assertTrue($harnessB->start(), 'nginx failed to boot for converter-off Artifact B');

        [$status, , $body] = $harnessB->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::AVIF_BODY, $body, 'B converter-off: avif bytes expected for avif+webp Accept');

        [$status, , $body] = $harnessB->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::WEBP_BODY, $body, 'B converter-off: webp bytes expected for webp-only Accept');

        // THE BUG CASE: neither format accepted -> original (was a 418 with an empty body).
        [$status, $headers, $body] = $harnessB->get($path, 'text/html');
        $this->assertSame(200, $status, 'B converter-off: neither-accepted must serve the original (NOT a stalled 418)');
        $this->assertSame(self::JPG_BODY, $body, 'B converter-off: original jpg bytes expected when neither format accepted');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');
    }

    /**
     * REGRESSION GUARD for redirect-to-existing semantics (the MAJOR parity defect): with
     * 'redirect-to-existing-in-htaccess' => false the nginx rules used to STILL serve a
     * pre-existing .webp/.avif from cache (tryEntries() emitted the cache/sibling lookups
     * unconditionally), diverging from .htaccess, which suppresses those rules on this exact flag.
     *
     * This boots Artifact A with the flag OFF and a .webp cache fixture in place, then curls with
     * 'Accept: image/webp'. The cache entry must NOT be consulted: the ORIGINAL .jpg must be served,
     * proving the flag now gates the cache lookups (matching HTAccessRules). Converter is left off
     * so the original is the only remaining option.
     *
     * @return void
     */
    public function testRedirectToExistingDisabledDoesNotServeCachedWebp(): void
    {
        $harness = new NginxHarness();
        $this->harness = $harness;
        if (!$harness->available()) {
            $this->markTestSkipped('nginx binary not found — skipping redirect-to-existing-off functional test.');
        }

        $cfg = $this->noConverterConfig();
        $cfg['redirect-to-existing-in-htaccess'] = false;
        $env = $this->env();

        $maps = NginxRules::generateMapsFile($cfg, $env);
        $server = NginxRules::generateServerFile($cfg, $env);
        $harness->writeConf($maps, $server);
        [$tCode, $tOut] = $harness->test();
        $this->assertSame(0, $tCode, "redirect-to-existing-off conf failed nginx -t:\n" . $tOut);

        // Place the original AND a cached .webp/.avif — they must be IGNORED with the flag off.
        $path = $this->placeDocRootCacheFixtures($harness);
        $this->assertTrue($harness->start(), 'nginx failed to boot for redirect-to-existing-off test');

        // webp accepted, cached .webp exists — but the flag is OFF, so the original must be served.
        [$status, $headers, $body] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::JPG_BODY, $body, 'redirect-to-existing off: cached webp must NOT be served; original expected');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');

        // avif+webp accepted, cached .avif exists — still the original (cache lookups suppressed).
        [$status, , $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::JPG_BODY, $body, 'redirect-to-existing off: cached avif must NOT be served; original expected');
    }
}
