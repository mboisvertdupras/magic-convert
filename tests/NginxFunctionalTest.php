<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\NginxRules;
use MagicConvert\SelfTestNginx;

require_once __DIR__ . '/NginxHarness.php';

class NginxFunctionalTest extends TestCase
{
    /** @var NginxHarness|null */
    private $harness;

    private const JPG_BODY  = 'JPEGFIXTURE';
    private const WEBP_BODY = 'WEBPFIXTUREBYTES-XX';
    private const AVIF_BODY = 'AVIFFIXTUREBYTES-LONGER-PADD';

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
            'destination-folder' => 'mingled',
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

    private function placeFixtures(NginxHarness $h): string
    {
        $dir = $h->docroot . '/wp-content/uploads';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/test.jpg', self::JPG_BODY);
        file_put_contents($dir . '/test.jpg.webp', self::WEBP_BODY);
        file_put_contents($dir . '/test.jpg.avif', self::AVIF_BODY);
        return '/wp-content/uploads/test.jpg';
    }

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

    private function placeImageRootsUploadsCacheFixtures(NginxHarness $h): string
    {
        $src = $h->docroot . '/wp-content/uploads/2024/05';
        @mkdir($src, 0777, true);
        file_put_contents($src . '/test.jpg', self::JPG_BODY);

        $webpDir = $h->docroot . '/wp-content/magic-convert/webp-images/uploads/2024/05';
        $avifDir = $h->docroot . '/wp-content/magic-convert/avif-images/uploads/2024/05';
        @mkdir($webpDir, 0777, true);
        @mkdir($avifDir, 0777, true);
        file_put_contents($webpDir . '/test.jpg.webp', self::WEBP_BODY);
        file_put_contents($avifDir . '/test.jpg.avif', self::AVIF_BODY);

        return '/wp-content/uploads/2024/05/test.jpg';
    }

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

            [$status, $headers, $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
            $this->assertSame(200, $status, "image-roots $label: avif+webp status");
            $this->assertSame(self::AVIF_BODY, $body, "image-roots $label: avif bytes expected from the per-root cache subtree");
            $this->assertSame('image/avif', $headers['content-type'] ?? '', "image-roots $label: avif content-type");
            $this->assertSame('Accept', $headers['vary'] ?? '', "image-roots $label: Vary: Accept must be present");

            [$status, $headers, $body] = $harness->get($path, 'image/webp,*/*');
            $this->assertSame(200, $status, "image-roots $label: webp status");
            $this->assertSame(self::WEBP_BODY, $body, "image-roots $label: webp bytes expected from the per-root cache subtree");
            $this->assertSame('image/webp', $headers['content-type'] ?? '', "image-roots $label: webp content-type");

            [$status, $headers, $body] = $harness->get($path, 'text/html');
            $this->assertSame(200, $status, "image-roots $label: neither-accepted status");
            $this->assertSame(self::JPG_BODY, $body, "image-roots $label: original jpg bytes expected when neither format accepted");
            $this->assertSame('image/jpeg', $headers['content-type'] ?? '', "image-roots $label: jpeg content-type");

            $fp = NginxRules::settingsFingerprint($cfg, $env);
            [$status, , $body] = $harness->get('/magic-convert-rules-version', '*/*');
            $this->assertSame(200, $status, "image-roots $label: rules-version status");
            $this->assertSame($fp, $body, "image-roots $label: /magic-convert-rules-version must return the fingerprint");

            $harness->cleanup();
            $this->harness = null;
        }
    }

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

        $harness->writeConf($maps, $server);
        [$tCode, $tOut] = $harness->test();
        $this->assertSame(0, $tCode, "Generated conf failed nginx -t:\n" . $tOut);

        $path = $this->placeFixtures($harness);

        $this->assertTrue($harness->start(), 'nginx failed to boot for functional test');

        [$status, $headers, $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::AVIF_BODY, $body, 'avif bytes expected for avif+webp Accept');
        $this->assertSame((string) strlen(self::AVIF_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/avif', $headers['content-type'] ?? '', 'content-type from local types{} block');
        $this->assertSame('Accept', $headers['vary'] ?? '', 'Vary: Accept must be present');

        [$status, $headers, $body] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::WEBP_BODY, $body, 'webp bytes expected for webp-only Accept');
        $this->assertSame((string) strlen(self::WEBP_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/webp', $headers['content-type'] ?? '');
        $this->assertSame('Accept', $headers['vary'] ?? '');

        [$status, $headers, $body] = $harness->get($path, 'text/html');
        $this->assertSame(200, $status);
        $this->assertSame(self::JPG_BODY, $body, 'original jpg bytes expected when neither format accepted');
        $this->assertSame((string) strlen(self::JPG_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');

        $fp = NginxRules::settingsFingerprint($cfg, $env);
        [$status, $headers, $body] = $harness->get('/magic-convert-rules-version', '*/*');
        $this->assertSame(200, $status);
        $this->assertSame($fp, $body, '/magic-convert-rules-version must return the settings fingerprint');
    }

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

        $harness->writeConf('', $single);
        [$tCode, $tOut] = $harness->test();
        $this->assertSame(0, $tCode, "Generated single-file conf failed nginx -t:\n" . $tOut);

        $path = $this->placeDocRootCacheFixtures($harness);

        $this->assertTrue($harness->start(), 'nginx failed to boot for single-file functional test');

        [$status, $headers, $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::AVIF_BODY, $body, 'single file: avif bytes expected for avif+webp Accept (not the original)');
        $this->assertSame((string) strlen(self::AVIF_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/avif', $headers['content-type'] ?? '', 'content-type from local types{} block');
        $this->assertSame('Accept', $headers['vary'] ?? '', 'Vary: Accept must be present');

        [$status, $headers, $body] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::WEBP_BODY, $body, 'single file: webp bytes expected for webp-only Accept (not the original)');
        $this->assertSame((string) strlen(self::WEBP_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/webp', $headers['content-type'] ?? '');
        $this->assertSame('Accept', $headers['vary'] ?? '');

        [$status, $headers, $body] = $harness->get($path, 'text/html');
        $this->assertSame(200, $status);
        $this->assertSame(self::JPG_BODY, $body, 'single file: original jpg bytes expected when neither format accepted');
        $this->assertSame((string) strlen(self::JPG_BODY), $headers['content-length'] ?? '');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');

        $fp = NginxRules::settingsFingerprint($cfg, $env);
        [$status, $headers, $body] = $harness->get('/magic-convert-rules-version', '*/*');
        $this->assertSame(200, $status);
        $this->assertSame($fp, $body, 'single file: /magic-convert-rules-version must return the settings fingerprint');
    }

    public function testConverterDisabledServesOriginalWhenNeitherFormatAccepted(): void
    {
        $harness = new NginxHarness();
        $this->harness = $harness;
        if (!$harness->available()) {
            $this->markTestSkipped('nginx binary not found — skipping converter-disabled functional serve test.');
        }

        $cfg = $this->noConverterConfig();
        $env = $this->env();

        $server = NginxRules::generateServerFile($cfg, $env);
        $single = NginxRules::generateSingleFile($cfg, $env);
        $this->assertStringNotContainsString('webp-on-demand.php', $server, 'converter must be absent (server)');
        $this->assertStringNotContainsString('webp-on-demand.php', $single, 'converter must be absent (single)');

        $maps = NginxRules::generateMapsFile($cfg, $env);
        $harness->writeConf($maps, $server);
        [$tCode, $tOut] = $harness->test();
        $this->assertSame(0, $tCode, "converter-off Artifact A failed nginx -t:\n" . $tOut);

        $path = $this->placeDocRootCacheFixtures($harness);
        $this->assertTrue($harness->start(), 'nginx failed to boot for converter-off Artifact A');

        [$status, , $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::AVIF_BODY, $body, 'A converter-off: avif bytes expected for avif+webp Accept');

        [$status, , $body] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::WEBP_BODY, $body, 'A converter-off: webp bytes expected for webp-only Accept');

        [$status, $headers, $body] = $harness->get($path, 'text/html');
        $this->assertSame(200, $status, 'A converter-off: neither-accepted must serve the original (NOT a 500 rewrite cycle)');
        $this->assertSame(self::JPG_BODY, $body, 'A converter-off: original jpg bytes expected when neither format accepted');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');

        $harness->cleanup();
        $this->harness = null;

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

        [$status, $headers, $body] = $harnessB->get($path, 'text/html');
        $this->assertSame(200, $status, 'B converter-off: neither-accepted must serve the original (NOT a stalled 418)');
        $this->assertSame(self::JPG_BODY, $body, 'B converter-off: original jpg bytes expected when neither format accepted');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');
    }

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

        $path = $this->placeDocRootCacheFixtures($harness);
        $this->assertTrue($harness->start(), 'nginx failed to boot for redirect-to-existing-off test');

        [$status, $headers, $body] = $harness->get($path, 'image/webp,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::JPG_BODY, $body, 'redirect-to-existing off: cached webp must NOT be served; original expected');
        $this->assertSame('image/jpeg', $headers['content-type'] ?? '');

        [$status, , $body] = $harness->get($path, 'image/avif,image/webp,image/*,*/*');
        $this->assertSame(200, $status);
        $this->assertSame(self::JPG_BODY, $body, 'redirect-to-existing off: cached avif must NOT be served; original expected');
    }
}
