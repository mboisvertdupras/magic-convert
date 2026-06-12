<?php

namespace MagicConvert;

/**
 * SelfTestNginx — the live "Nginx rules live test" (roadmap Phase 3.3).
 *
 * This is the nginx counterpart of SelfTestRedirectToExisting / SelfTestRedirectToConverter: it
 * deploys known-size dummy artifacts into the per-format cache locations, fetches the test image
 * over HTTP with different Accept headers, and asserts that nginx serves the negotiated format —
 * proving the operator-installed nginx include is actually live and correct (nginx does NOT read
 * .htaccess, so the only way to know is to probe it from the outside).
 *
 * It builds directly on the existing machinery:
 *   - SelfTestHelper::copyTestImageToRoot / copyDummyWebPToCacheFolder for deploy + cleanup,
 *   - SelfTestHelper::remoteGet for the HTTP probes,
 *   - SelfTestHelper::hasVaryAcceptHeader for the Vary check,
 *   - NginxRules::settingsFingerprint + the /magic-convert-rules-version marker for drift.
 *
 * TWO LAYERS (the SelfTest* convention here, and what keeps it unit-testable):
 *   - A PURE classification core (classifyFetch / classifyDrift / selectCdnGuidance / verdict) that
 *     takes plain response data (content-type, content-length, body, status) and returns enum
 *     verdicts. NO WordPress, NO filesystem, NO HTTP — exercised directly by SelfTestNginxClassifyTest.
 *   - A THIN WP-glue runner (runTestForImageType / startTest) that deploys files, performs the
 *     wp_remote_get probes, hands the raw response data to the pure core, and renders the report in
 *     the same markdown-ish dialect SelfTestHelper produces. The glue is deliberately minimal.
 *
 * CONTENT-LENGTH FINGERPRINTING: the dummy .webp and dummy .avif are deployed at DIFFERENT byte
 * sizes (see WEBP_DUMMY_BODY / AVIF_DUMMY_BODY). That lets classifyFetch() tell apart:
 *   - "rules active AND mime mapping present"  (correct content-type)
 *   - "rules active but mime mapping missing"  (wrong/odd content-type, but the content-LENGTH
 *     matches the format's dummy — i.e. the right file WAS served, only the type{} block is absent)
 *   - "rules NOT active"                        (the original was served / neither length matched).
 */
class SelfTestNginx
{
    // --- Fetch classification enum -----------------------------------------------------------
    /** The negotiated format's dummy was served WITH the correct content-type. */
    const FETCH_SERVED_AVIF = 'served-avif';
    const FETCH_SERVED_WEBP = 'served-webp';
    /** The original source image was served (content-type image/jpeg|png). */
    const FETCH_SERVED_ORIGINAL = 'served-original';
    /** The correct format's BYTES were served (content-length matches) but the content-type is
     *  wrong/missing — the rules ARE active, only the types{} mime mapping is missing. */
    const FETCH_MIME_MISSING_AVIF = 'mime-missing-avif';
    const FETCH_MIME_MISSING_WEBP = 'mime-missing-webp';
    /** Some other image type came back (e.g. webp when avif was expected => preference/order wrong). */
    const FETCH_WRONG_FORMAT = 'wrong-format';
    /** No content-type header at all (cannot classify). */
    const FETCH_NO_CONTENT_TYPE = 'no-content-type';
    /** The HTTP request itself failed (non-200 / wp_error). */
    const FETCH_REQUEST_FAILED = 'request-failed';

    // --- Drift classification enum -----------------------------------------------------------
    const DRIFT_UP_TO_DATE = 'up-to-date';
    const DRIFT_STALE = 'drift';
    const DRIFT_ABSENT = 'absent';

    // --- Overall verdict enum ----------------------------------------------------------------
    const VERDICT_WORKING_WEBP_AVIF = 'working-webp-avif';
    const VERDICT_WORKING_WEBP = 'working-webp';
    const VERDICT_PARTIAL = 'partial';
    const VERDICT_NOT_WORKING = 'not-working';

    /**
     * The dummy bodies deployed into the cache. They MUST be different sizes from each other AND
     * from the real test source so content-length uniquely identifies which file nginx served.
     * (very-small.jpg is 3195 bytes, test.png is 3118 bytes — both far from these.)
     */
    const WEBP_DUMMY_BODY = 'MAGIC-CONVERT-DUMMY-WEBP';                       // 24 bytes
    const AVIF_DUMMY_BODY = 'MAGIC-CONVERT-DUMMY-AVIF-LONGER-TO-DIFFER-XXXX';  // 46 bytes

    /** @var array */
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    // =====================================================================================
    //  PURE CLASSIFICATION CORE (no WP / no FS / no HTTP — unit tested in isolation)
    // =====================================================================================

    /**
     * Classify a single fetch response.
     *
     * @param  string  $expectFormat   what we EXPECT nginx to have served: 'avif'|'webp'|'original'.
     * @param  bool    $requestSucceeded  whether the HTTP request returned 200.
     * @param  array   $headers        response headers (lowercased keys), as SelfTestHelper sees them.
     * @param  array   $lengths        the known dummy/source sizes keyed by format:
     *                                  ['avif'=>int, 'webp'=>int, 'original'=>int].
     * @param  string  $imageType      'jpeg' | 'png' (the original's content-type half).
     * @return string  one of the FETCH_* constants.
     */
    public static function classifyFetch($expectFormat, $requestSucceeded, $headers, $lengths, $imageType = 'jpeg')
    {
        if (!$requestSucceeded) {
            return self::FETCH_REQUEST_FAILED;
        }
        if (!isset($headers['content-type'])) {
            return self::FETCH_NO_CONTENT_TYPE;
        }
        $contentType = strtolower(trim((string) $headers['content-type']));
        // Strip any "; charset=..." suffix some servers append.
        if (($pos = strpos($contentType, ';')) !== false) {
            $contentType = trim(substr($contentType, 0, $pos));
        }
        $contentLength = isset($headers['content-length']) ? (int) $headers['content-length'] : null;

        // Helper: does the served byte-count match a given format's known dummy/source size?
        // A null (absent) content-length never POSITIVELY matches — see $lengthMatchesOrAbsent for
        // the ambiguous-absent handling below.
        $lengthMatches = function ($fmt) use ($contentLength, $lengths) {
            return ($contentLength !== null)
                && isset($lengths[$fmt])
                && ((int) $lengths[$fmt] === $contentLength);
        };
        // Helper: treats an ABSENT content-length (chunked transfer / on-the-fly gzip — no
        // content-length header) as "could be this format". When the content-length is present we
        // require an exact byte match; when it is absent we cannot rule the format in OR out, so we
        // do not let its absence collapse the mime-missing diagnosis into the more pessimistic
        // "rules not active" verdict. (mime-missing is still NOT counted as clean, so this can never
        // produce a false-positive "all working".)
        $lengthMatchesOrAbsent = function ($fmt) use ($contentLength, $lengthMatches) {
            return ($contentLength === null) || $lengthMatches($fmt);
        };

        // --- Correct content-type cases ---
        if ($contentType === 'image/avif') {
            return self::FETCH_SERVED_AVIF;
        }
        if ($contentType === 'image/webp') {
            return self::FETCH_SERVED_WEBP;
        }
        if ($contentType === 'image/' . $imageType
            || $contentType === 'image/jpeg'
            || $contentType === 'image/png') {
            // Original content-type. BUT if the content-length matches a converted dummy, the
            // converted file was actually served and only the mime mapping is wrong. When the
            // content-length is ABSENT and we expected a conversion, we still diagnose mime-missing:
            // we cannot confirm the ORIGINAL bytes were served, and "rules active but type wrong" is
            // the more accurate (and still non-passing) verdict for the missing-content-length edge.
            if ($expectFormat === 'avif' && $lengthMatchesOrAbsent('avif')) {
                return self::FETCH_MIME_MISSING_AVIF;
            }
            if (($expectFormat === 'avif' || $expectFormat === 'webp') && $lengthMatchesOrAbsent('webp')) {
                return self::FETCH_MIME_MISSING_WEBP;
            }
            return self::FETCH_SERVED_ORIGINAL;
        }

        // --- Unexpected / generic content-type: lean on content-length fingerprinting ---
        if ($expectFormat === 'avif' && $lengthMatchesOrAbsent('avif')) {
            return self::FETCH_MIME_MISSING_AVIF;
        }
        if (($expectFormat === 'avif' || $expectFormat === 'webp') && $lengthMatchesOrAbsent('webp')) {
            return self::FETCH_MIME_MISSING_WEBP;
        }
        if ($lengthMatches('original')) {
            return self::FETCH_SERVED_ORIGINAL;
        }

        return self::FETCH_WRONG_FORMAT;
    }

    /**
     * Whether a fetch verdict means "the negotiated format was actually delivered to the client"
     * (correct content-type). mime-missing is NOT counted as a pass — the bytes were right but the
     * browser would mis-handle the response, so the user must still fix the types{} block.
     *
     * @param  string  $fetchVerdict
     * @return bool
     */
    public static function fetchIsClean($fetchVerdict)
    {
        return in_array($fetchVerdict, [
            self::FETCH_SERVED_AVIF,
            self::FETCH_SERVED_WEBP,
            self::FETCH_SERVED_ORIGINAL,
        ], true);
    }

    /**
     * Classify the drift-check outcome from the /magic-convert-rules-version probe.
     *
     * @param  bool         $requestSucceeded  whether the marker returned 200.
     * @param  string|null  $body              the response body (the installed fingerprint), or null.
     * @param  string       $persistedFingerprint  the fingerprint we computed from current settings.
     * @return string  one of the DRIFT_* constants.
     */
    public static function classifyDrift($requestSucceeded, $body, $persistedFingerprint)
    {
        // 404 / unreachable / no marker => absent (older manual installs may simply lack it).
        if (!$requestSucceeded || $body === null) {
            return self::DRIFT_ABSENT;
        }
        $installed = trim((string) $body);
        if ($installed === '') {
            return self::DRIFT_ABSENT;
        }
        if ($installed === (string) $persistedFingerprint) {
            return self::DRIFT_UP_TO_DATE;
        }
        return self::DRIFT_STALE;
    }

    /**
     * Pick CDN-specific Vary guidance from the response headers (consolidates the README CDN notes
     * + diagnoseNoVaryHeader). Returns markdown lines.
     *
     * @param  array  $headers  response headers (lowercased keys).
     * @return string[]
     */
    public static function selectCdnGuidance($headers)
    {
        $log = [];

        $isCloudflare = self::headerHints($headers, ['cf-ray', 'cf-cache-status'])
            || self::serverContains($headers, 'cloudflare');
        $isCloudfront = self::headerHints($headers, ['x-amz-cf-id', 'x-amz-cf-pop'])
            || self::viaContains($headers, 'cloudfront');
        $isFastly = self::headerHints($headers, ['x-served-by', 'fastly-io-info'])
            || self::serverContains($headers, 'fastly');

        if ($isCloudflare) {
            $log[] = '**It looks like Cloudflare is in front of this site.**{: .warn} Cloudflare on the ' .
                'free plan IGNORES the `Vary: Accept` header for images, so it may cache a webp/avif and ' .
                'serve it to a browser that asked for neither (blank images).';
            $log[] = 'What to do on Cloudflare:';
            $log[] = '- Easiest: switch Magic Convert to **Alter HTML &rarr; picture tags** (no reliance on ' .
                'Vary at all — the browser picks the format), OR use Cloudflare\'s own image format conversion (Polish/Image Resizing).';
            $log[] = '- On **Pro and above**, enable **"Vary for Images"** (via the API / `cf-polish`), which ' .
                'makes Cloudflare honour `Vary: Accept` for image responses.';
        } elseif ($isCloudfront) {
            $log[] = '**It looks like Amazon CloudFront is in front of this site.**{: .warn} By default CloudFront ' .
                'does not forward (or vary on) the `Accept` header, so it can cache one format and serve it to all clients.';
            $log[] = 'What to do on CloudFront: add **`Accept`** to the headers your cache policy includes in the ' .
                'cache key (Cache Policy &rarr; Headers &rarr; include `Accept`). Then CloudFront keeps a separate ' .
                'cache entry per Accept value and the negotiation is preserved.';
        } elseif ($isFastly) {
            $log[] = '**It looks like Fastly is in front of this site.**{: .warn} Make sure your VCL keeps `Accept` ' .
                'in the cache variance (Fastly honours `Vary: Accept` by default, but custom VCL can strip it).';
        } else {
            $log[] = 'No specific CDN was detected from the response headers. If a CDN/proxy sits in front of this ' .
                'site, make sure it is configured to vary its image cache on the `Accept` request header — otherwise ' .
                'it may serve a cached webp/avif to a browser that does not support it.';
            $log[] = 'If there is no CDN, then the `Vary: Accept` header is simply not being emitted by your nginx ' .
                'rules; re-generate and re-install the rules from the nginx panel (the generated `add_header Vary Accept;` ' .
                'provides it).';
        }
        return $log;
    }

    /**
     * Compute the overall verdict enum from the per-step booleans collected during a run.
     *
     * @param  array  $signals  [
     *     'webpServed'      => bool,   // Accept: image/webp got a clean webp
     *     'avifEnabled'     => bool,   // avif serving is configured
     *     'avifServed'      => bool,   // Accept: avif,webp got a clean avif (only meaningful if avifEnabled)
     *     'avifPreferred'   => bool,   // avif came BEFORE webp (ordering correct; only if avifEnabled)
     *     'originalServed'  => bool,   // no-image Accept got the original
     *     'varyOk'          => bool,   // Vary: Accept present on converted responses
     * ]
     * @return string  one of the VERDICT_* constants.
     */
    public static function verdict($signals)
    {
        $webp = !empty($signals['webpServed']);
        $orig = !empty($signals['originalServed']);
        $avifEnabled = !empty($signals['avifEnabled']);
        $avif = !empty($signals['avifServed']);
        $avifPref = !empty($signals['avifPreferred']);
        $vary = !empty($signals['varyOk']);

        // Core negotiation must hold: webp on webp-Accept AND original on no-Accept.
        $coreOk = $webp && $orig;

        if (!$coreOk) {
            return self::VERDICT_NOT_WORKING;
        }

        if ($avifEnabled) {
            if ($avif && $avifPref && $vary) {
                return self::VERDICT_WORKING_WEBP_AVIF;
            }
            // webp works but avif preference/serving or Vary is off => partial.
            return self::VERDICT_PARTIAL;
        }

        // avif not enabled: webp+original+vary is full working; missing vary => partial.
        return $vary ? self::VERDICT_WORKING_WEBP : self::VERDICT_PARTIAL;
    }

    /**
     * A short human verdict line for the summary at the top of the report.
     *
     * @param  string  $verdict  one of the VERDICT_* constants.
     * @return string
     */
    public static function verdictHeadline($verdict)
    {
        switch ($verdict) {
            case self::VERDICT_WORKING_WEBP_AVIF:
                return '**nginx serving: WORKING (webp + avif)**{: .ok}';
            case self::VERDICT_WORKING_WEBP:
                return '**nginx serving: WORKING (webp)**{: .ok}';
            case self::VERDICT_PARTIAL:
                return '**nginx serving: PARTIALLY working — see the remediation below**{: .warn}';
            case self::VERDICT_NOT_WORKING:
            default:
                return '**nginx serving: NOT working — see the remediation below**{: .error}';
        }
    }

    // --- small header-inspection helpers (pure) ----------------------------------------------

    private static function headerHints($headers, $names)
    {
        foreach ($names as $n) {
            if (isset($headers[$n])) {
                return true;
            }
        }
        return false;
    }

    private static function serverContains($headers, $needle)
    {
        return isset($headers['server']) && (stripos((string) $headers['server'], $needle) !== false);
    }

    private static function viaContains($headers, $needle)
    {
        return isset($headers['via']) && (stripos((string) $headers['via'], $needle) !== false);
    }

    // =====================================================================================
    //  THIN WP-GLUE RUNNER (deploy -> probe -> classify -> render). Kept minimal.
    // =====================================================================================

    /**
     * Whether avif serving is enabled (config v2). Defensive read so a v1 config behaves as off.
     *
     * @return bool
     */
    private function avifEnabled()
    {
        return (
            isset($this->config['formats']['avif']['enabled']) &&
            $this->config['formats']['avif']['enabled'] === true
        );
    }

    /**
     * Deploy the known-size dummy webp + (when avif enabled) dummy avif into the per-format cache
     * locations, alongside the test source image. Returns [$ok, $log, $deployed] where $deployed is
     * a descriptor used for cleanup and for the content-length expectations.
     *
     * @param  string  $rootId
     * @param  string  $imageType  'jpeg' | 'png'
     * @return array{0:bool,1:array,2:array}
     */
    private function deployFixtures($rootId, $imageType)
    {
        $log = [];
        $deployed = [
            'rootId' => $rootId,
            'sourceFileName' => null,
            'paths' => [],            // absolute paths to delete on cleanup
            'lengths' => [],          // 'avif'|'webp'|'original' => byte size
        ];

        // 1) Source image.
        list($subResult, $success, $sourceFileName) = SelfTestHelper::copyTestImageToRoot($rootId, $imageType);
        $log = array_merge($log, $subResult);
        if (!$success) {
            return [false, $log, $deployed];
        }
        $deployed['sourceFileName'] = $sourceFileName;
        $srcAbs = Paths::getAbsDirById($rootId) . '/magic-convert-test-images/' . $sourceFileName;
        $deployed['lengths']['original'] = @filesize($srcAbs);

        // 2) Dummy webp (KNOWN distinct size) at the destination the rules look up.
        list($okWebp, $subResult, $webpAbs) = $this->deployDummyConverted(
            $rootId,
            $sourceFileName,
            'webp',
            self::WEBP_DUMMY_BODY
        );
        $log = array_merge($log, $subResult);
        if (!$okWebp) {
            return [false, $log, $deployed];
        }
        $deployed['paths'][] = $webpAbs;
        $deployed['lengths']['webp'] = strlen(self::WEBP_DUMMY_BODY);

        // 3) Dummy avif (DIFFERENT known size) — only when avif serving is enabled.
        if ($this->avifEnabled()) {
            list($okAvif, $subResult, $avifAbs) = $this->deployDummyConverted(
                $rootId,
                $sourceFileName,
                'avif',
                self::AVIF_DUMMY_BODY
            );
            $log = array_merge($log, $subResult);
            if (!$okAvif) {
                return [false, $log, $deployed];
            }
            $deployed['paths'][] = $avifAbs;
            $deployed['lengths']['avif'] = strlen(self::AVIF_DUMMY_BODY);
        }

        return [true, $log, $deployed];
    }

    /**
     * Write a dummy converted file of the given format into the per-format cache location that the
     * generated nginx rules look up (the same destination math the converter would write to). Mirrors
     * SelfTestHelper::copyDummyWebPToCacheFolder but parameterized by format + body so avif and webp
     * (with different sizes) can both be placed.
     *
     * @param  string  $rootId
     * @param  string  $sourceFileName
     * @param  string  $formatId   'webp' | 'avif'
     * @param  string  $body       the dummy bytes to write.
     * @return array{0:bool,1:array,2:string}  [ok, log, absolutePathWritten]
     */
    private function deployDummyConverted($rootId, $sourceFileName, $formatId, $body)
    {
        $log = [];

        $cacheDir = Paths::getCacheDirForImageRoot(
            $this->config['destination-folder'],
            $this->config['destination-structure'],
            $rootId,
            $formatId
        );
        if (!@is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
            if (!@is_dir($cacheDir)) {
                $log[] = 'Failed creating the ' . $formatId . ' cache folder: ' . $cacheDir;
                return [false, $log, ''];
            }
        }
        $destDir = $cacheDir . '/magic-convert-test-images';
        if (!@is_dir($destDir)) {
            @mkdir($destDir, 0755, false);
            if (!@is_dir($destDir)) {
                $log[] = 'Failed creating the ' . $formatId . ' test-images folder: ' . $destDir;
                return [false, $log, ''];
            }
        }

        $destFileName = ConvertHelperIndependent::appendOrSetExtension(
            $sourceFileName,
            $this->config['destination-folder'],
            $this->config['destination-extension'],
            ($rootId == 'uploads'),
            $formatId
        );
        $destination = $destDir . '/' . $destFileName;

        if (@file_put_contents($destination, $body) === false) {
            $log[] = 'Failed writing the dummy ' . $formatId . ' file: ' . $destination;
            return [false, $log, ''];
        }
        $log[] = 'Deployed a dummy ' . strtoupper($formatId) . ' (' . strlen($body) . ' bytes) at:';
        $log[] = '*' . $destination . '*';
        return [true, $log, $destination];
    }

    /**
     * Remove all deployed fixtures (idempotent / failure-safe). Removes both the source test-images
     * dir and the per-format cache test-images files.
     *
     * @param  array  $deployed
     */
    private function cleanupFixtures($deployed)
    {
        $rootId = isset($deployed['rootId']) ? $deployed['rootId'] : null;
        if ($rootId !== null) {
            // Source test images.
            SelfTestHelper::deleteTestImagesInFolder($rootId);
            // Per-format cache test-images dirs (webp + avif).
            foreach (['webp', 'avif'] as $fmt) {
                $cacheDir = Paths::getCacheDirForImageRoot(
                    $this->config['destination-folder'],
                    $this->config['destination-structure'],
                    $rootId,
                    $fmt
                );
                SelfTestHelper::deleteDir($cacheDir . '/magic-convert-test-images');
            }
        }
        // Belt-and-braces: unlink any explicitly tracked paths still present.
        if (isset($deployed['paths']) && is_array($deployed['paths'])) {
            foreach ($deployed['paths'] as $p) {
                if (is_string($p) && $p !== '' && @file_exists($p)) {
                    @unlink($p);
                }
            }
        }
    }

    /**
     * Run the full live nginx test for one root + image type. Deploys fixtures, performs the Accept
     * probes, classifies via the pure core, and renders the report. ALWAYS cleans up (success,
     * failure, exception).
     *
     * @param  string  $rootId
     * @param  string  $imageType  'jpeg' | 'png'
     * @return array{0:bool,1:array}  [allClean, log]
     */
    protected function runTestForImageType($rootId, $imageType)
    {
        $log = [];
        $deployed = ['rootId' => $rootId, 'paths' => [], 'lengths' => []];
        $avifEnabled = $this->avifEnabled();

        // Per-step signals fed to verdict().
        $signals = [
            'avifEnabled'    => $avifEnabled,
            'webpServed'     => false,
            'avifServed'     => false,
            'avifPreferred'  => false,
            'originalServed' => false,
            'varyOk'         => true, // assume ok unless a converted response lacks Vary
        ];
        $remediation = []; // remediation lines for failed steps, surfaced first in the summary.

        try {
            $log[] = '### Deploying test fixtures into the per-format cache (' . strtoupper($imageType) . ')';
            list($ok, $deployLog, $deployed) = $this->deployFixtures($rootId, $imageType);
            $log = array_merge($log, $deployLog);
            if (!$ok) {
                $log[] = 'The test cannot be completed (could not deploy the fixtures).';
                $this->cleanupFixtures($deployed);
                return [false, $log];
            }

            $requestUrl = Paths::getUrlById($rootId) . '/magic-convert-test-images/' . $deployed['sourceFileName'];
            $lengths = $deployed['lengths'];

            // ---- (b) Accept: image/webp -> expect webp dummy --------------------------------
            $log[] = '';
            $log[] = '### Step 1 — Accept: image/webp should return the WEBP';
            list($success, $rlog, $headers) = $this->probe($requestUrl, 'image/webp');
            $log = array_merge($log, $rlog);
            $cls = self::classifyFetch('webp', $success, $headers, $lengths, $imageType);
            list($webpOk, $stepLog, $stepRemediation) = $this->renderFetch(
                $cls, 'webp', $headers, $imageType, $rootId
            );
            $log = array_merge($log, $stepLog);
            $remediation = array_merge($remediation, $stepRemediation);
            $signals['webpServed'] = $webpOk;
            if ($webpOk && !SelfTestHelper::hasVaryAcceptHeader($headers)) {
                $signals['varyOk'] = false;
            }

            // ---- (c) Accept: image/avif,image/webp -> preference test -----------------------
            $log[] = '';
            if ($avifEnabled) {
                $log[] = '### Step 2 — Accept: image/avif,image/webp should return the AVIF (preference test)';
                list($success, $rlog, $headers) = $this->probe($requestUrl, 'image/avif,image/webp,image/*,*/*');
                $log = array_merge($log, $rlog);
                $cls = self::classifyFetch('avif', $success, $headers, $lengths, $imageType);
                list($avifOk, $stepLog, $stepRemediation) = $this->renderFetch(
                    $cls, 'avif', $headers, $imageType, $rootId
                );
                $log = array_merge($log, $stepLog);
                $remediation = array_merge($remediation, $stepRemediation);
                $signals['avifServed'] = $avifOk;
                // Preference: avif must come BEFORE webp. If we got a (clean) webp here, ordering is wrong.
                if ($cls === self::FETCH_SERVED_WEBP || $cls === self::FETCH_MIME_MISSING_WEBP) {
                    $signals['avifPreferred'] = false;
                    $remediation[] = '**Preference/ordering is wrong: avif+webp Accept returned the WEBP, not the AVIF.**{: .error} ' .
                        'The avif try_files entries must precede the webp ones. Re-generate the rules from the nginx panel.';
                } else {
                    $signals['avifPreferred'] = $avifOk;
                }
                if ($avifOk && !SelfTestHelper::hasVaryAcceptHeader($headers)) {
                    $signals['varyOk'] = false;
                }
            } else {
                $log[] = '### Step 2 — Accept: image/avif,image/webp should return the WEBP (avif serving disabled)';
                list($success, $rlog, $headers) = $this->probe($requestUrl, 'image/avif,image/webp,image/*,*/*');
                $log = array_merge($log, $rlog);
                $cls = self::classifyFetch('webp', $success, $headers, $lengths, $imageType);
                list($webp2Ok, $stepLog, $stepRemediation) = $this->renderFetch(
                    $cls, 'webp', $headers, $imageType, $rootId
                );
                $log = array_merge($log, $stepLog);
                $remediation = array_merge($remediation, $stepRemediation);
                // avif not enabled => "avifServed/avifPreferred" stay false but don't drag the verdict
                // (verdict() ignores them when avifEnabled is false). webpServed already covers step 1.
            }

            // ---- (d) No image Accept -> expect the original ---------------------------------
            $log[] = '';
            $log[] = '### Step 3 — Accept without any image type should return the ORIGINAL';
            list($success, $rlog, $headers) = $this->probe($requestUrl, 'text/html');
            $log = array_merge($log, $rlog);
            $cls = self::classifyFetch('original', $success, $headers, $lengths, $imageType);
            list($origOk, $stepLog, $stepRemediation) = $this->renderFetch(
                $cls, 'original', $headers, $imageType, $rootId
            );
            $log = array_merge($log, $stepLog);
            $remediation = array_merge($remediation, $stepRemediation);
            $signals['originalServed'] = $origOk;

            // ---- (e) Vary: Accept check on converted responses ------------------------------
            $log[] = '';
            $log[] = '### Step 4 — Vary: Accept on the converted responses';
            if ($signals['varyOk']) {
                $log[] = 'The converted responses carried a `Vary: Accept` header. **Great!**{: .ok}';
            } else {
                $log[] = '**The converted responses did NOT carry a `Vary: Accept` header.**{: .warn} ' .
                    'This matters when a CDN/proxy caches your images.';
                $log = array_merge($log, self::selectCdnGuidance($headers));
                $remediation[] = '**Add the `Vary: Accept` header** (the generated rules include `add_header Vary Accept;`). ' .
                    'If a CDN is in front of the site, see the CDN-specific guidance in the report.';
            }

            // ---- (f) Drift check ------------------------------------------------------------
            $log[] = '';
            $log[] = '### Step 5 — Rules-version drift check';
            list($driftLog, $driftRemediation) = $this->runDriftStep();
            $log = array_merge($log, $driftLog);
            $remediation = array_merge($remediation, $driftRemediation);

            // ---- (g) Converter-fallback probe (only when redirect-to-converter enabled) -----
            if (!empty($this->config['enable-redirection-to-converter'])) {
                $log[] = '';
                $log[] = '### Step 6 — Converter fallback (never-converted source, Accept: image/webp)';
                list($convLog, $convRemediation) = $this->runConverterFallbackStep($rootId, $imageType);
                $log = array_merge($log, $convLog);
                $remediation = array_merge($remediation, $convRemediation);
            }
        } catch (\Throwable $e) {
            $log[] = '**The test threw an exception: ' . $e->getMessage() . '**{: .error}';
            $this->cleanupFixtures($deployed);
            // Re-render with whatever we collected; treat as not-clean.
            return [false, $this->prependSummary($log, $signals, $remediation), $deployed];
        }

        // Always clean up.
        $this->cleanupFixtures($deployed);

        $allClean = $signals['webpServed'] && $signals['originalServed']
            && (!$avifEnabled || ($signals['avifServed'] && $signals['avifPreferred']))
            && $signals['varyOk'] && empty($remediation);

        return [$allClean, $this->prependSummary($log, $signals, $remediation)];
    }

    /**
     * Render one fetch result into report lines + remediation, returning [clean, log, remediation].
     *
     * @param  string  $cls        a FETCH_* constant.
     * @param  string  $expect     'avif' | 'webp' | 'original'
     * @param  array   $headers
     * @param  string  $imageType
     * @param  string  $rootId
     * @return array{0:bool,1:array,2:array}
     */
    private function renderFetch($cls, $expect, $headers, $imageType, $rootId)
    {
        $log = [];
        $remediation = [];

        switch ($cls) {
            case self::FETCH_SERVED_AVIF:
                if ($expect === 'avif') {
                    $log[] = 'Got the AVIF, with `content-type: image/avif`. **Great!**{: .ok}';
                    return [true, $log, $remediation];
                }
                $log[] = '**Got an AVIF but expected the ' . $expect . '.**{: .error}';
                $remediation[] = 'An avif was served when the ' . $expect . ' was expected — check the try_files ordering.';
                return [false, $log, $remediation];

            case self::FETCH_SERVED_WEBP:
                if ($expect === 'webp') {
                    $log[] = 'Got the WEBP, with `content-type: image/webp`. **Great!**{: .ok}';
                    return [true, $log, $remediation];
                }
                if ($expect === 'avif') {
                    $log[] = '**Got the WEBP, but with avif+webp Accept we expected the AVIF (preference/order wrong).**{: .error}';
                    return [false, $log, $remediation];
                }
                $log[] = '**Got the WEBP but expected the ' . $expect . '.**{: .error}';
                return [false, $log, $remediation];

            case self::FETCH_SERVED_ORIGINAL:
                if ($expect === 'original') {
                    $log[] = 'Got the original ' . strtoupper($imageType) . '. **Great!**{: .ok}';
                    return [true, $log, $remediation];
                }
                $log[] = '**Got the original ' . strtoupper($imageType) . ' but expected the ' . $expect . '.**{: .error} ' .
                    'The nginx rules do not appear to be active (or the cached ' . $expect . ' was not found).';
                $remediation[] = 'The ' . $expect . ' was NOT served for a browser that accepts it. Verify the ' .
                    'nginx include is installed and that nginx was reloaded; re-run `nginx -t` and `systemctl reload nginx`.';
                return [false, $log, $remediation];

            case self::FETCH_MIME_MISSING_AVIF:
            case self::FETCH_MIME_MISSING_WEBP:
                $fmt = ($cls === self::FETCH_MIME_MISSING_AVIF) ? 'avif' : 'webp';
                $log[] = '**The ' . strtoupper($fmt) . ' file WAS served (its content-length matches the dummy), ' .
                    'but the content-type is wrong.**{: .warn} The rules are active — only the mime mapping is missing.';
                $log[] = 'Your nginx config is probably missing the `types { image/' . $fmt . ' ' . $fmt . '; ... }` ' .
                    'block. Re-generate the rules from the nginx panel (the generated rules include a local `types{}` ' .
                    'block so you do not have to edit mime.types).';
                $remediation[] = '**Rules active but mime mapping missing for ' . $fmt . '** — your config may lack the ' .
                    '`types{}` block. Re-generate and re-install the rules from the nginx panel.';
                return [false, $log, $remediation];

            case self::FETCH_NO_CONTENT_TYPE:
                $log[] = '**No `content-type` response header — cannot classify.**{: .error}';
                $remediation[] = 'No content-type came back; inspect the response headers above and your nginx config.';
                return [false, $log, $remediation];

            case self::FETCH_REQUEST_FAILED:
                $log[] = '**The HTTP request failed.**{: .error} The test cannot be completed for this step.';
                $remediation[] = 'The loopback HTTP request failed; check the URL and that the site is reachable from the server.';
                return [false, $log, $remediation];

            case self::FETCH_WRONG_FORMAT:
            default:
                $log[] = '**Unexpected response — neither the expected ' . $expect . ' nor a recognizable original.**{: .error}';
                $remediation[] = 'An unexpected response was served; inspect the headers above.';
                return [false, $log, $remediation];
        }
    }

    /**
     * The drift step: GET /magic-convert-rules-version, compare to the persisted/current fingerprint.
     *
     * @return array{0:array,1:array}  [log, remediation]
     */
    private function runDriftStep()
    {
        $log = [];
        $remediation = [];

        $fingerprint = $this->currentFingerprint();
        $markerUrl = self::rulesVersionUrl();
        list($success, $rlog, $body) = $this->probeBody($markerUrl);
        $log = array_merge($log, $rlog);

        $drift = self::classifyDrift($success, $body, $fingerprint);
        switch ($drift) {
            case self::DRIFT_UP_TO_DATE:
                $log[] = 'The installed rules-version matches your current settings. **Rules up to date.**{: .ok}';
                break;
            case self::DRIFT_STALE:
                $log[] = '**The installed rules were generated from OLDER settings.**{: .warn} ' .
                    'Installed fingerprint: `' . trim((string) $body) . '`, current: `' . $fingerprint . '`.';
                $log[] = 'Update the rules from the nginx panel, then reload nginx.';
                $remediation[] = '**Installed rules are stale** (generated from older settings) — update from the nginx panel.';
                // Arm the dismissable update notice (3.2), defensively.
                $this->armDriftNotice();
                break;
            case self::DRIFT_ABSENT:
            default:
                $log[] = 'No `/magic-convert-rules-version` marker was returned (404 / absent). ' .
                    'This is informational: either the rules are not installed, or they were generated by an ' .
                    'older manual setup that predates the version marker.';
                break;
        }
        return [$log, $remediation];
    }

    /**
     * The converter-fallback step: request a NEVER-converted source with Accept: image/webp and
     * verify a webp comes back (the wod endpoint converted on demand). Reuses the source-deploy
     * helper but deploys NO dummy converted file, so the only way to get a webp is the converter.
     *
     * @param  string  $rootId
     * @param  string  $imageType
     * @return array{0:array,1:array}  [log, remediation]
     */
    private function runConverterFallbackStep($rootId, $imageType)
    {
        $log = [];
        $remediation = [];

        // Deploy ONLY a fresh source (no dummy converted files) under a different random name.
        list($subResult, $ok, $sourceFileName) = SelfTestHelper::copyTestImageToRoot($rootId, $imageType);
        $log = array_merge($log, $subResult);
        if (!$ok) {
            $log[] = 'Could not deploy a fresh source for the converter probe; skipping this step.';
            return [$log, $remediation];
        }

        $requestUrl = Paths::getUrlById($rootId) . '/magic-convert-test-images/' . $sourceFileName;
        list($success, $rlog, $headers) = $this->probe($requestUrl, 'image/webp');
        $log = array_merge($log, $rlog);

        $contentType = isset($headers['content-type']) ? strtolower(trim((string) $headers['content-type'])) : '';
        if ($success && strpos($contentType, 'image/webp') !== false) {
            $log[] = 'A WEBP came back for a never-converted source — the converter fallback works. **Great!**{: .ok}';
        } else {
            $log[] = '**The converter fallback did not return a webp** (content-type: ' .
                ($contentType === '' ? 'none' : $contentType) . ').{: .warn}';
            $log[] = 'This can be normal if no conversion method is available on the server, or if the ' .
                'converter/realizer location is not installed. Check the converter location in the nginx rules ' .
                'and that PHP-FPM can run `wod/webp-on-demand.php`.';
            $remediation[] = 'The converter fallback did not produce a webp — verify the converter location is ' .
                'installed and a conversion method is available.';
        }

        // Cleanup just this source.
        SelfTestHelper::deleteTestImagesInFolder($rootId);
        return [$log, $remediation];
    }

    /**
     * Prepend the verdict summary (headline + remediation-first) to the per-type log.
     *
     * @param  array  $log
     * @param  array  $signals
     * @param  array  $remediation
     * @return array
     */
    private function prependSummary($log, $signals, $remediation)
    {
        $verdict = self::verdict($signals);
        $summary = [];
        $summary[] = '## Verdict';
        $summary[] = self::verdictHeadline($verdict);
        if (!empty($remediation)) {
            $summary[] = '### What to fix first';
            // De-duplicate while preserving order.
            $seen = [];
            foreach ($remediation as $r) {
                if (!isset($seen[$r])) {
                    $seen[$r] = true;
                    $summary[] = '- ' . $r;
                }
            }
        }
        $summary[] = '';
        $summary[] = '### Full report';
        return array_merge($summary, $log);
    }

    // --- HTTP probe wrappers (thin) ----------------------------------------------------------

    /**
     * GET $url with the given Accept header. Returns [success, log, headers].
     *
     * @param  string  $url
     * @param  string  $accept
     * @return array{0:bool,1:array,2:array}
     */
    private function probe($url, $accept)
    {
        list($success, $log, $results) = SelfTestHelper::remoteGet($url, [
            'headers' => ['ACCEPT' => $accept],
        ]);
        $headers = (is_array($results) && count($results) > 0)
            ? $results[count($results) - 1]['headers']
            : [];
        return [$success, $log, $headers];
    }

    /**
     * GET $url and return [success, log, body].
     *
     * @param  string  $url
     * @return array{0:bool,1:array,2:string|null}
     */
    private function probeBody($url)
    {
        list($success, $log, $results) = SelfTestHelper::remoteGet($url);
        $body = null;
        if (is_array($results) && count($results) > 0) {
            $last = $results[count($results) - 1];
            $body = isset($last['body']) ? $last['body'] : null;
        }
        return [$success, $log, $body];
    }

    // --- seams overridable in tests / used by glue -------------------------------------------

    /** Current settings fingerprint (rule-affecting subset). */
    protected function currentFingerprint()
    {
        return NginxRules::settingsFingerprint($this->config, NginxRules::environmentFromPaths($this->config));
    }

    /**
     * Absolute URL of the rules-version marker. The marker is an EXACT-match location at the nginx
     * SERVER root (`location = /magic-convert-rules-version`), so it lives at the host root regardless
     * of any WordPress subdirectory — request the scheme+host root, not home_url(path).
     */
    protected static function rulesVersionUrl()
    {
        return self::hostRoot() . '/magic-convert-rules-version';
    }

    /**
     * scheme://host (no path, no trailing slash) from home_url. Used so root-anchored nginx
     * locations are requested at the host root even on subdirectory WordPress installs.
     *
     * @return string
     */
    protected static function hostRoot()
    {
        $home = home_url('/');
        $parts = parse_url($home);
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? (':' . $parts['port']) : '';
        return $scheme . '://' . $host . $port;
    }

    /** Arm the 3.2 dismissable "rules need updating" notice (defensive — never let it block). */
    protected function armDriftNotice()
    {
        try {
            DismissableGlobalMessages::addDismissableMessage(NginxRulesNotice::MESSAGE_ID);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    // =====================================================================================
    //  ENTRY POINTS (mirror the other SelfTest* classes)
    // =====================================================================================

    /**
     * Run the live test across the configured scope. Returns [success, log].
     *
     * @return array{0:bool,1:array}
     */
    public function startTest()
    {
        $log = [];
        $log[] = '# Nginx rules live test';

        if (!PlatformInfo::isNginx()) {
            $log[] = '**Note: this server does not look like nginx.**{: .warn} This live test probes the ' .
                'operator-installed nginx include. If nginx serves your images (e.g. behind a reverse proxy), ' .
                'you can still run it; otherwise use the Apache self-tests above.';
        }

        if (!file_exists(Paths::getConfigFileName())) {
            $log[] = 'Hold on — you need to save settings before running this test (there is no config file yet).';
            return [true, $log];
        }
        if ((int) $this->config['image-types'] === 0) {
            $log[] = 'No image types are enabled — nothing to test.';
            return [true, $log];
        }
        if (empty($this->config['redirect-to-existing-in-htaccess'])) {
            $log[] = 'Redirect-to-existing is turned off, so there is nothing to test for cached-format serving. ' .
                '(If you just turned it on, remember to save settings — this is a live test.)';
            return [true, $log];
        }

        // Test the first enabled image type per root (the report for the second type is nearly
        // identical; mirrors SelfTestRedirectAbstract's behaviour to keep the report readable).
        $imageType = ((int) $this->config['image-types'] & 1) ? 'jpeg' : 'png';

        $overallClean = true;
        foreach ($this->config['scope'] as $rootId) {
            $log[] = '';
            $log[] = '## Image root: ' . $rootId;
            list($clean, $subLog) = $this->runTestForImageType($rootId, $imageType);
            $log = array_merge($log, $subLog);
            $overallClean = $overallClean && $clean;
        }

        return [$overallClean, $log];
    }

    /**
     * Static entry used by SelfTest::nginxLiveTest() (the AJAX dispatcher).
     *
     * @return array{0:bool,1:array}
     */
    public static function runTest()
    {
        $config = Config::loadConfigAndFix(false);
        $me = new SelfTestNginx($config);
        return $me->startTest();
    }
}
