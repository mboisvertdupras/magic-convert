<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\SelfTestNginx;

/**
 * Pure-logic tests for the Phase 3.3 live nginx self-test classification core. These exercise the
 * type/length -> verdict matrix, the drift comparison outcomes, the CDN guidance selection, and the
 * overall verdict enum — all WITHOUT WordPress, HTTP, or the filesystem (the WP-http glue stays
 * thin and is covered functionally by NginxFunctionalTest).
 *
 * The fixture sizes mirror what the runner deploys: webp dummy 24 bytes, avif dummy 46 bytes, and a
 * distinct "original" size so content-length uniquely identifies the served file.
 */
class SelfTestNginxClassifyTest extends TestCase
{
    /** Distinct known sizes (must differ from each other). */
    private function lengths(): array
    {
        return [
            'avif'     => 46,
            'webp'     => 24,
            'original' => 3195, // very-small.jpg
        ];
    }

    // =====================================================================================
    //  classifyFetch — content-type + content-length matrix
    // =====================================================================================

    public function testWebpServedCleanly(): void
    {
        $headers = ['content-type' => 'image/webp', 'content-length' => '24'];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_WEBP,
            SelfTestNginx::classifyFetch('webp', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    public function testAvifServedCleanly(): void
    {
        $headers = ['content-type' => 'image/avif', 'content-length' => '46'];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_AVIF,
            SelfTestNginx::classifyFetch('avif', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    public function testOriginalServedCleanly(): void
    {
        $headers = ['content-type' => 'image/jpeg', 'content-length' => '3195'];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_ORIGINAL,
            SelfTestNginx::classifyFetch('original', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    public function testContentTypeWithCharsetSuffixIsParsed(): void
    {
        $headers = ['content-type' => 'image/webp; charset=binary', 'content-length' => '24'];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_WEBP,
            SelfTestNginx::classifyFetch('webp', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    /**
     * Rules active but mime mapping missing: the webp BYTES were served (content-length == 24) but
     * the content-type is the original's (image/jpeg) because the types{} block is absent.
     */
    public function testWebpBytesServedWithWrongTypeIsMimeMissing(): void
    {
        $headers = ['content-type' => 'image/jpeg', 'content-length' => '24'];
        $this->assertSame(
            SelfTestNginx::FETCH_MIME_MISSING_WEBP,
            SelfTestNginx::classifyFetch('webp', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    public function testAvifBytesServedWithWrongTypeIsMimeMissing(): void
    {
        $headers = ['content-type' => 'image/jpeg', 'content-length' => '46'];
        $this->assertSame(
            SelfTestNginx::FETCH_MIME_MISSING_AVIF,
            SelfTestNginx::classifyFetch('avif', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    public function testAvifBytesServedWithGenericOctetStreamIsMimeMissing(): void
    {
        $headers = ['content-type' => 'application/octet-stream', 'content-length' => '46'];
        $this->assertSame(
            SelfTestNginx::FETCH_MIME_MISSING_AVIF,
            SelfTestNginx::classifyFetch('avif', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    /**
     * Preference/order wrong: expected avif but got a clean webp (content-type image/webp). This is
     * the signal the runner uses to flag bad try_files ordering.
     */
    public function testExpectedAvifButGotWebpIsServedWebp(): void
    {
        $headers = ['content-type' => 'image/webp', 'content-length' => '24'];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_WEBP,
            SelfTestNginx::classifyFetch('avif', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    /**
     * Rules NOT active: expected webp but got the original jpeg bytes (content-length 3195).
     */
    public function testExpectedWebpButGotOriginalIsServedOriginal(): void
    {
        $headers = ['content-type' => 'image/jpeg', 'content-length' => '3195'];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_ORIGINAL,
            SelfTestNginx::classifyFetch('webp', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    /**
     * Missing-content-length edge (chunked transfer / on-the-fly gzip): the response carries the
     * ORIGINAL content-type and NO content-length header, but we expected a webp. We cannot confirm
     * the original BYTES were served, so the right diagnosis is mime-missing (rules active, type
     * wrong) — NOT the more pessimistic "rules not active" served-original.
     */
    public function testWebpExpectedOriginalTypeNoContentLengthIsMimeMissing(): void
    {
        $headers = ['content-type' => 'image/jpeg']; // no content-length
        $this->assertSame(
            SelfTestNginx::FETCH_MIME_MISSING_WEBP,
            SelfTestNginx::classifyFetch('webp', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    public function testAvifExpectedOriginalTypeNoContentLengthIsMimeMissing(): void
    {
        $headers = ['content-type' => 'image/jpeg']; // no content-length
        $this->assertSame(
            SelfTestNginx::FETCH_MIME_MISSING_AVIF,
            SelfTestNginx::classifyFetch('avif', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    /**
     * Same missing-content-length edge, but with a generic (non-original) content-type — still
     * mime-missing when a conversion was expected.
     */
    public function testWebpExpectedGenericTypeNoContentLengthIsMimeMissing(): void
    {
        $headers = ['content-type' => 'application/octet-stream']; // no content-length
        $this->assertSame(
            SelfTestNginx::FETCH_MIME_MISSING_WEBP,
            SelfTestNginx::classifyFetch('webp', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    /**
     * When the ORIGINAL is what we expected (Accept without an image type), the absence of a
     * content-length must NOT be misread as a conversion — there is nothing to convert. The
     * original content-type with no content-length stays served-original.
     */
    public function testOriginalExpectedOriginalTypeNoContentLengthIsServedOriginal(): void
    {
        $headers = ['content-type' => 'image/jpeg']; // no content-length
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_ORIGINAL,
            SelfTestNginx::classifyFetch('original', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    /**
     * Regression guard: a PRESENT content-length that matches the original (and a conversion was
     * expected) must still be served-original — the present-length path is unchanged by the
     * missing-content-length fix.
     */
    public function testWebpExpectedOriginalBytesWithMatchingLengthStaysServedOriginal(): void
    {
        $headers = ['content-type' => 'image/jpeg', 'content-length' => '3195'];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_ORIGINAL,
            SelfTestNginx::classifyFetch('webp', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    public function testPngOriginalRecognized(): void
    {
        $headers = ['content-type' => 'image/png', 'content-length' => '3118'];
        $lengths = ['avif' => 46, 'webp' => 24, 'original' => 3118];
        $this->assertSame(
            SelfTestNginx::FETCH_SERVED_ORIGINAL,
            SelfTestNginx::classifyFetch('original', true, $headers, $lengths, 'png')
        );
    }

    public function testRequestFailed(): void
    {
        $this->assertSame(
            SelfTestNginx::FETCH_REQUEST_FAILED,
            SelfTestNginx::classifyFetch('webp', false, [], $this->lengths(), 'jpeg')
        );
    }

    public function testNoContentType(): void
    {
        $this->assertSame(
            SelfTestNginx::FETCH_NO_CONTENT_TYPE,
            SelfTestNginx::classifyFetch('webp', true, ['content-length' => '24'], $this->lengths(), 'jpeg')
        );
    }

    public function testWrongFormatWhenNothingMatches(): void
    {
        // Unknown content-type and a length matching no fixture.
        $headers = ['content-type' => 'text/html', 'content-length' => '999'];
        $this->assertSame(
            SelfTestNginx::FETCH_WRONG_FORMAT,
            SelfTestNginx::classifyFetch('webp', true, $headers, $this->lengths(), 'jpeg')
        );
    }

    public function testFetchIsClean(): void
    {
        $this->assertTrue(SelfTestNginx::fetchIsClean(SelfTestNginx::FETCH_SERVED_AVIF));
        $this->assertTrue(SelfTestNginx::fetchIsClean(SelfTestNginx::FETCH_SERVED_WEBP));
        $this->assertTrue(SelfTestNginx::fetchIsClean(SelfTestNginx::FETCH_SERVED_ORIGINAL));
        $this->assertFalse(SelfTestNginx::fetchIsClean(SelfTestNginx::FETCH_MIME_MISSING_WEBP));
        $this->assertFalse(SelfTestNginx::fetchIsClean(SelfTestNginx::FETCH_MIME_MISSING_AVIF));
        $this->assertFalse(SelfTestNginx::fetchIsClean(SelfTestNginx::FETCH_REQUEST_FAILED));
        $this->assertFalse(SelfTestNginx::fetchIsClean(SelfTestNginx::FETCH_WRONG_FORMAT));
    }

    // =====================================================================================
    //  classifyDrift — version endpoint comparison
    // =====================================================================================

    public function testDriftUpToDate(): void
    {
        $this->assertSame(
            SelfTestNginx::DRIFT_UP_TO_DATE,
            SelfTestNginx::classifyDrift(true, 'abc123', 'abc123')
        );
    }

    public function testDriftUpToDateTrimsWhitespace(): void
    {
        $this->assertSame(
            SelfTestNginx::DRIFT_UP_TO_DATE,
            SelfTestNginx::classifyDrift(true, "abc123\n", 'abc123')
        );
    }

    public function testDriftStaleWhenDiffers(): void
    {
        $this->assertSame(
            SelfTestNginx::DRIFT_STALE,
            SelfTestNginx::classifyDrift(true, 'OLDFINGERPRINT', 'NEWFINGERPRINT')
        );
    }

    public function testDriftAbsentOn404(): void
    {
        // request failed (e.g. 404) => absent
        $this->assertSame(
            SelfTestNginx::DRIFT_ABSENT,
            SelfTestNginx::classifyDrift(false, null, 'abc123')
        );
    }

    public function testDriftAbsentOnEmptyBody(): void
    {
        $this->assertSame(
            SelfTestNginx::DRIFT_ABSENT,
            SelfTestNginx::classifyDrift(true, '   ', 'abc123')
        );
    }

    public function testDriftAbsentOnNullBody(): void
    {
        $this->assertSame(
            SelfTestNginx::DRIFT_ABSENT,
            SelfTestNginx::classifyDrift(true, null, 'abc123')
        );
    }

    // =====================================================================================
    //  selectCdnGuidance — CDN detection from headers
    // =====================================================================================

    public function testCloudflareGuidance(): void
    {
        $lines = SelfTestNginx::selectCdnGuidance(['cf-ray' => 'abc-LAX', 'server' => 'cloudflare']);
        $joined = strtolower(implode("\n", $lines));
        $this->assertStringContainsString('cloudflare', $joined);
        $this->assertStringContainsString('vary for images', $joined);
        $this->assertStringContainsString('alter html', $joined);
    }

    public function testCloudfrontGuidance(): void
    {
        $lines = SelfTestNginx::selectCdnGuidance(['x-amz-cf-id' => 'xyz', 'via' => '1.1 abc.cloudfront.net']);
        $joined = strtolower(implode("\n", $lines));
        $this->assertStringContainsString('cloudfront', $joined);
        $this->assertStringContainsString('accept', $joined);
        $this->assertStringContainsString('cache policy', $joined);
    }

    public function testFastlyGuidance(): void
    {
        $lines = SelfTestNginx::selectCdnGuidance(['x-served-by' => 'cache-lax', 'server' => 'fastly']);
        $joined = strtolower(implode("\n", $lines));
        $this->assertStringContainsString('fastly', $joined);
    }

    public function testNoCdnGuidance(): void
    {
        $lines = SelfTestNginx::selectCdnGuidance(['server' => 'nginx/1.25.0']);
        $joined = strtolower(implode("\n", $lines));
        $this->assertStringContainsString('no specific cdn', $joined);
        $this->assertStringContainsString('add_header vary accept', $joined);
    }

    // =====================================================================================
    //  verdict — overall enum
    // =====================================================================================

    public function testVerdictWorkingWebpAvif(): void
    {
        $this->assertSame(
            SelfTestNginx::VERDICT_WORKING_WEBP_AVIF,
            SelfTestNginx::verdict([
                'avifEnabled' => true, 'webpServed' => true, 'avifServed' => true,
                'avifPreferred' => true, 'originalServed' => true, 'varyOk' => true,
            ])
        );
    }

    public function testVerdictWorkingWebpOnly(): void
    {
        $this->assertSame(
            SelfTestNginx::VERDICT_WORKING_WEBP,
            SelfTestNginx::verdict([
                'avifEnabled' => false, 'webpServed' => true,
                'originalServed' => true, 'varyOk' => true,
            ])
        );
    }

    public function testVerdictPartialWhenAvifPreferenceWrong(): void
    {
        // avif enabled + served but ordering wrong => partial.
        $this->assertSame(
            SelfTestNginx::VERDICT_PARTIAL,
            SelfTestNginx::verdict([
                'avifEnabled' => true, 'webpServed' => true, 'avifServed' => true,
                'avifPreferred' => false, 'originalServed' => true, 'varyOk' => true,
            ])
        );
    }

    public function testVerdictPartialWhenVaryMissing(): void
    {
        $this->assertSame(
            SelfTestNginx::VERDICT_PARTIAL,
            SelfTestNginx::verdict([
                'avifEnabled' => false, 'webpServed' => true,
                'originalServed' => true, 'varyOk' => false,
            ])
        );
    }

    public function testVerdictPartialWhenAvifEnabledButNotServed(): void
    {
        $this->assertSame(
            SelfTestNginx::VERDICT_PARTIAL,
            SelfTestNginx::verdict([
                'avifEnabled' => true, 'webpServed' => true, 'avifServed' => false,
                'avifPreferred' => false, 'originalServed' => true, 'varyOk' => true,
            ])
        );
    }

    public function testVerdictNotWorkingWhenWebpFails(): void
    {
        $this->assertSame(
            SelfTestNginx::VERDICT_NOT_WORKING,
            SelfTestNginx::verdict([
                'avifEnabled' => true, 'webpServed' => false, 'avifServed' => true,
                'avifPreferred' => true, 'originalServed' => true, 'varyOk' => true,
            ])
        );
    }

    public function testVerdictNotWorkingWhenOriginalFails(): void
    {
        $this->assertSame(
            SelfTestNginx::VERDICT_NOT_WORKING,
            SelfTestNginx::verdict([
                'avifEnabled' => false, 'webpServed' => true,
                'originalServed' => false, 'varyOk' => true,
            ])
        );
    }

    public function testVerdictHeadlines(): void
    {
        $this->assertStringContainsString('WORKING (webp + avif)', SelfTestNginx::verdictHeadline(SelfTestNginx::VERDICT_WORKING_WEBP_AVIF));
        $this->assertStringContainsString('WORKING (webp)', SelfTestNginx::verdictHeadline(SelfTestNginx::VERDICT_WORKING_WEBP));
        $this->assertStringContainsString('PARTIALLY', SelfTestNginx::verdictHeadline(SelfTestNginx::VERDICT_PARTIAL));
        $this->assertStringContainsString('NOT working', SelfTestNginx::verdictHeadline(SelfTestNginx::VERDICT_NOT_WORKING));
    }
}
