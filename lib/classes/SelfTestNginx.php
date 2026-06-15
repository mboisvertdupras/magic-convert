<?php

namespace MagicConvert;

class SelfTestNginx
{
    const FETCH_SERVED_AVIF = 'served-avif';
    const FETCH_SERVED_WEBP = 'served-webp';
    const FETCH_SERVED_ORIGINAL = 'served-original';
    const FETCH_MIME_MISSING_AVIF = 'mime-missing-avif';
    const FETCH_MIME_MISSING_WEBP = 'mime-missing-webp';
    const FETCH_WRONG_FORMAT = 'wrong-format';
    const FETCH_NO_CONTENT_TYPE = 'no-content-type';
    const FETCH_REQUEST_FAILED = 'request-failed';

    const DRIFT_UP_TO_DATE = 'up-to-date';
    const DRIFT_STALE = 'drift';
    const DRIFT_ABSENT = 'absent';

    const VERDICT_WORKING_WEBP_AVIF = 'working-webp-avif';
    const VERDICT_WORKING_WEBP = 'working-webp';
    const VERDICT_PARTIAL = 'partial';
    const VERDICT_NOT_WORKING = 'not-working';

    const WEBP_DUMMY_BODY = 'MAGIC-CONVERT-DUMMY-WEBP';
    const AVIF_DUMMY_BODY = 'MAGIC-CONVERT-DUMMY-AVIF-LONGER-TO-DIFFER-XXXX';

    /** @var array */
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public static function classifyFetch($expectFormat, $requestSucceeded, $headers, $lengths, $imageType = 'jpeg')
    {
        if (!$requestSucceeded) {
            return self::FETCH_REQUEST_FAILED;
        }
        if (!isset($headers['content-type'])) {
            return self::FETCH_NO_CONTENT_TYPE;
        }
        $contentType = strtolower(trim((string) $headers['content-type']));
        if (($pos = strpos($contentType, ';')) !== false) {
            $contentType = trim(substr($contentType, 0, $pos));
        }
        $contentLength = isset($headers['content-length']) ? (int) $headers['content-length'] : null;

        $lengthMatches = function ($fmt) use ($contentLength, $lengths) {
            return ($contentLength !== null)
                && isset($lengths[$fmt])
                && ((int) $lengths[$fmt] === $contentLength);
        };
        $lengthMatchesOrAbsent = function ($fmt) use ($contentLength, $lengthMatches) {
            return ($contentLength === null) || $lengthMatches($fmt);
        };

        if ($contentType === 'image/avif') {
            return self::FETCH_SERVED_AVIF;
        }
        if ($contentType === 'image/webp') {
            return self::FETCH_SERVED_WEBP;
        }
        if ($contentType === 'image/' . $imageType
            || $contentType === 'image/jpeg'
            || $contentType === 'image/png') {
            if ($expectFormat === 'avif' && $lengthMatchesOrAbsent('avif')) {
                return self::FETCH_MIME_MISSING_AVIF;
            }
            if (($expectFormat === 'avif' || $expectFormat === 'webp') && $lengthMatchesOrAbsent('webp')) {
                return self::FETCH_MIME_MISSING_WEBP;
            }
            return self::FETCH_SERVED_ORIGINAL;
        }

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

    public static function fetchIsClean($fetchVerdict)
    {
        return in_array($fetchVerdict, [
            self::FETCH_SERVED_AVIF,
            self::FETCH_SERVED_WEBP,
            self::FETCH_SERVED_ORIGINAL,
        ], true);
    }

    public static function classifyDrift($requestSucceeded, $body, $persistedFingerprint)
    {
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

    public static function verdict($signals)
    {
        $webp = !empty($signals['webpServed']);
        $orig = !empty($signals['originalServed']);
        $avifEnabled = !empty($signals['avifEnabled']);
        $avif = !empty($signals['avifServed']);
        $avifPref = !empty($signals['avifPreferred']);
        $vary = !empty($signals['varyOk']);

        $coreOk = $webp && $orig;

        if (!$coreOk) {
            return self::VERDICT_NOT_WORKING;
        }

        if ($avifEnabled) {
            if ($avif && $avifPref && $vary) {
                return self::VERDICT_WORKING_WEBP_AVIF;
            }
            return self::VERDICT_PARTIAL;
        }

        return $vary ? self::VERDICT_WORKING_WEBP : self::VERDICT_PARTIAL;
    }

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

    private function avifEnabled()
    {
        return (
            isset($this->config['formats']['avif']['enabled']) &&
            $this->config['formats']['avif']['enabled'] === true
        );
    }

    /**
     * @return array{0:bool,1:array,2:array}
     */
    private function deployFixtures($rootId, $imageType)
    {
        $log = [];
        $deployed = [
            'rootId' => $rootId,
            'sourceFileName' => null,
            'paths' => [],
            'lengths' => [],
        ];

        list($subResult, $success, $sourceFileName) = SelfTestHelper::copyTestImageToRoot($rootId, $imageType);
        $log = array_merge($log, $subResult);
        if (!$success) {
            return [false, $log, $deployed];
        }
        $deployed['sourceFileName'] = $sourceFileName;
        $srcAbs = Paths::getAbsDirById($rootId) . '/magic-convert-test-images/' . $sourceFileName;
        $deployed['lengths']['original'] = @filesize($srcAbs);

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
     * @return array{0:bool,1:array,2:string}
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

    private function cleanupFixtures($deployed)
    {
        $rootId = isset($deployed['rootId']) ? $deployed['rootId'] : null;
        if ($rootId !== null) {
            SelfTestHelper::deleteTestImagesInFolder($rootId);
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
        if (isset($deployed['paths']) && is_array($deployed['paths'])) {
            foreach ($deployed['paths'] as $p) {
                if (is_string($p) && $p !== '' && @file_exists($p)) {
                    @unlink($p);
                }
            }
        }
    }

    /**
     * @return array{0:bool,1:array}
     */
    protected function runTestForImageType($rootId, $imageType)
    {
        $log = [];
        $deployed = ['rootId' => $rootId, 'paths' => [], 'lengths' => []];
        $avifEnabled = $this->avifEnabled();

        $signals = [
            'avifEnabled'    => $avifEnabled,
            'webpServed'     => false,
            'avifServed'     => false,
            'avifPreferred'  => false,
            'originalServed' => false,
            'varyOk'         => true,
        ];
        $remediation = [];

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
            }

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

            $log[] = '';
            $log[] = '### Step 5 — Rules-version drift check';
            list($driftLog, $driftRemediation) = $this->runDriftStep();
            $log = array_merge($log, $driftLog);
            $remediation = array_merge($remediation, $driftRemediation);

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
            return [false, $this->prependSummary($log, $signals, $remediation), $deployed];
        }

        $this->cleanupFixtures($deployed);

        $allClean = $signals['webpServed'] && $signals['originalServed']
            && (!$avifEnabled || ($signals['avifServed'] && $signals['avifPreferred']))
            && $signals['varyOk'] && empty($remediation);

        return [$allClean, $this->prependSummary($log, $signals, $remediation)];
    }

    /**
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
     * @return array{0:array,1:array}
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
     * @return array{0:array,1:array}
     */
    private function runConverterFallbackStep($rootId, $imageType)
    {
        $log = [];
        $remediation = [];

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

        SelfTestHelper::deleteTestImagesInFolder($rootId);
        return [$log, $remediation];
    }

    private function prependSummary($log, $signals, $remediation)
    {
        $verdict = self::verdict($signals);
        $summary = [];
        $summary[] = '## Verdict';
        $summary[] = self::verdictHeadline($verdict);
        if (!empty($remediation)) {
            $summary[] = '### What to fix first';
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

    /**
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

    protected function currentFingerprint()
    {
        return NginxRules::settingsFingerprint($this->config, NginxRules::environmentFromPaths($this->config));
    }

    protected static function rulesVersionUrl()
    {
        return self::hostRoot() . '/magic-convert-rules-version';
    }

    protected static function hostRoot()
    {
        $home = home_url('/');
        $parts = parse_url($home);
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? (':' . $parts['port']) : '';
        return $scheme . '://' . $host . $port;
    }

    protected function armDriftNotice()
    {
        try {
            DismissableGlobalMessages::addDismissableMessage(NginxRulesNotice::MESSAGE_ID);
        } catch (\Throwable $e) {
        }
    }

    /**
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
     * @return array{0:bool,1:array}
     */
    public static function runTest()
    {
        $config = Config::loadConfigAndFix(false);
        $me = new SelfTestNginx($config);
        return $me->startTest();
    }
}
