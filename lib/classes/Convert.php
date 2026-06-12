<?php

namespace MagicConvert;

use \WebPConvert\Convert\Converters\Ewww;

use \MagicConvert\ConvertHelperIndependent;
use \MagicConvert\Config;
use \MagicConvert\ConvertersHelper;
use \MagicConvert\ImageRoots;
use \MagicConvert\OutputFormat;
use \MagicConvert\PathHelper;
use \MagicConvert\SanityCheck;
use \MagicConvert\SanityException;
use \MagicConvert\Validate;
use \MagicConvert\ValidateException;

class Convert
{

    public static function getDestination($source, &$config = null, $format = null)
    {
        if (is_null($config)) {
            $config = Config::loadConfigAndFix();
        }
        // Phase 2.1: format is explicit here (defaults to webp). Single-format
        // (webp) convert from the admin/test UI stays webp; multi-format bulk
        // arrives in step 2.5.
        return ConvertHelperIndependent::getDestination(
            $source,
            $config['destination-folder'],
            $config['destination-extension'],
            Paths::getMagicConvertContentDirAbs(),
            Paths::getUploadDirAbs(),
            (($config['destination-structure'] == 'doc-root') && (Paths::canUseDocRootForStructuringCacheDir())),
            new ImageRoots(Paths::getImageRootsDef()),
            OutputFormat::coerce($format)
        );
    }

    public static function updateBiggerThanOriginalMark($source, $destination = null, &$config = null, $format = null)
    {
        if (is_null($config)) {
            $config = Config::loadConfigAndFix();
        }
        $format = OutputFormat::coerce($format);
        if (is_null($destination)) {
            $destination = self::getDestination($source, $config, $format);
        }
        BiggerThanSourceDummyFiles::updateStatus(
            $source,
            $destination,
            Paths::getMagicConvertContentDirAbs(),
            new ImageRoots(Paths::getImageRootsDef()),
            $config['destination-folder'],
            $config['destination-extension'],
            $format
        );
    }

    /**
     *  Convert a single source file.
     *
     *  @param  string      $source          Absolute path to the source image.
     *  @param  array|null  $config          Config array (loaded if null).
     *  @param  array|null  $convertOptions  webp-convert options (derived from
     *                                       config if null).
     *  @param  string|null $converter       Specific converter id, or null for the
     *                                       configured converter stack.
     *  @param  bool        $skipIfFresh     When true, the conversion core skips
     *                                       re-encoding a destination that is
     *                                       already newer than its source (Phase
     *                                       1.1 idempotency). Used by the parallel
     *                                       bulk path so re-runs are cheap; the
     *                                       explicit "reconvert" UI action passes
     *                                       false to force a fresh encode.
     *  @param  OutputFormat|string|null $format  Output format (defaults to webp). Bulk/REST/CLI
     *                                       keep the webp default for now; the encode core
     *                                       throws a clear "not yet supported" error for non-webp
     *                                       until the AVIF encoder lands (step 2.3).
     */
    public static function convertFile($source, $config = null, $convertOptions = null, $converter = null, $skipIfFresh = false, $format = null)
    {
        $format = OutputFormat::coerce($format);
        try {
            // Check source
            // ---------------
            $checking = 'source path';
            $source = SanityCheck::absPathExistsAndIsFile($source);
            //$filename = SanityCheck::absPathExistsAndIsFileInDocRoot($source);
            // PS: No need to check mime type as the WebPConvert library does that (it only accepts image/jpeg and image/png)

            // Check that source is within a valid image root
            // ----------------------------------------------
            // SECURITY-CRITICAL: this containment check is the guard that the
            // plugin's history (CVE-2019-15330, arbitrary file disclosure) makes
            // mandatory. It must run for EVERY conversion regardless of caller
            // (admin-ajax OR the REST endpoint). Do not bypass it.
            $activeRootIds = Paths::getImageRootIds();  // Currently, root ids cannot be selected, so all root ids are active.
            $rootId = Paths::findImageRootOfPath($source, $activeRootIds);
            if ($rootId === false) {
                throw new \Exception('Path of source is not within a valid image root');
            }

            // Check config
            // --------------
            $checking = 'configuration file';
            if (is_null($config)) {
                $config = Config::loadConfigAndFix();  // ps: if this fails to load, default config is returned.
            }
            if (!is_array($config)) {
                throw new SanityException('configuration file is corrupt');
            }

            // Check convert options
            // -------------------------------
            $checking = 'configuration file (options)';
            if (is_null($convertOptions)) {
                $wodOptions = Config::generateWodOptionsFromConfigObj($config);
                if (!isset($wodOptions['webp-convert']['convert'])) {
                    throw new SanityException('conversion options are missing');
                }
                $convertOptions = $wodOptions['webp-convert']['convert'];
            }
            if (!is_array($convertOptions)) {
                throw new SanityException('conversion options are missing');
            }

            // AVIF per-format options (Phase 2.3).
            // -------------------------------
            // When converting to AVIF, thread the formats.avif quality/speed into the
            // options array under an 'avif' key so the (WordPress-independent) core can
            // hand them to the AvifStack. Metadata is NOT duplicated here: the AVIF
            // stack reads the global 'metadata' option already present in $convertOptions,
            // keeping metadata handling consistent with the webp path. This block only
            // runs for AVIF, so with AVIF disabled the options array is byte-for-byte
            // unchanged.
            $formatObj = OutputFormat::coerce($format);
            if ($formatObj->id() === 'avif') {
                $avifCfg = (isset($config['formats']['avif']) && is_array($config['formats']['avif']))
                    ? $config['formats']['avif']
                    : [];
                $convertOptions['avif'] = [
                    'quality' => isset($avifCfg['quality']) ? intval($avifCfg['quality']) : 30,
                    'speed' => isset($avifCfg['speed']) ? intval($avifCfg['speed']) : 6,
                ];
            }


            // Check destination
            // -------------------------------
            $checking = 'destination';
            $destination = self::getDestination($source, $config, $format);

            $destination = SanityCheck::absPath($destination);

            // Check log dir
            // -------------------------------
            $checking = 'conversion log dir';
            if (isset($config['enable-logging']) && $config['enable-logging']) {
                $logDir = SanityCheck::absPath(Paths::getMagicConvertContentDirAbs() . '/log');
            } else {
                $logDir = null;
            }


        } catch (\Exception $e) {
            return [
                'success' => false,
                'msg' => 'Check failed for ' . $checking . ': '. $e->getMessage(),
                'log' => '',
            ];
        }

        // Idempotency opt-in (Phase 1.1). The 'skip-if-fresh' flag is a
        // plugin-level option that the conversion core consumes and strips before
        // it reaches webp-convert. When set, an already-fresh destination is left
        // untouched and reported as 'already-converted' rather than re-encoded.
        if ($skipIfFresh) {
            $convertOptions['skip-if-fresh'] = true;
        }

        // Done with sanitizing, lets get to work!
        // ---------------------------------------
//return false;
        $result = ConvertHelperIndependent::convert($source, $destination, $convertOptions, $logDir, $converter, $format);

//error_log('looki:' . $source . $converter);
        // If we are using stack converter, check if Ewww discovered invalid api key
        //if (is_null($converter)) {
            if (isset(Ewww::$nonFunctionalApiKeysDiscoveredDuringConversion)) {
                // We got an invalid or exceeded api key (at least one).
                //error_log('look:' . print_r(Ewww::$nonFunctionalApiKeysDiscoveredDuringConversion, true));
                EwwwTools::markApiKeysAsNonFunctional(
                    Ewww::$nonFunctionalApiKeysDiscoveredDuringConversion,
                    Paths::getConfigDirAbs()
                );
            }
        //}

        self::updateBiggerThanOriginalMark($source, $destination, $config, $format);

        if ($result['success'] === true) {
            $result['filesize-original'] = @filesize($source);
            $result['filesize-webp'] = @filesize($destination);
            $result['destination-path'] = $destination;

            $destinationOptions = DestinationOptions::createFromConfig($config, $format);

            $rootOfDestination = Paths::destinationRoot($rootId, $destinationOptions);

            $relPathFromImageRootToSource = PathHelper::getRelDir(
                realpath(Paths::getAbsDirById($rootId)),
                realpath($source)
            );
            $relPathFromImageRootToDest = ConvertHelperIndependent::appendOrSetExtension(
                $relPathFromImageRootToSource,
                $config['destination-folder'],
                $config['destination-extension'],
                ($rootId == 'uploads'),
                $format
            );

            $result['destination-url'] = $rootOfDestination['url'] . '/' . $relPathFromImageRootToDest;
        }
        return $result;
    }

    /**
     *  Determine the location of a source from the location of a destination.
     *
     *  If for example Operation mode is set to "mingled" and extension is set to "Append .webp",
     *  the result of looking passing "/path/to/logo.jpg.webp" will be "/path/to/logo.jpg".
     *
     *  Additionally, it is tested if the source exists. If not, false is returned.
     *  The destination does not have to exist.
     *
     *  @param  OutputFormat|string|null  $format  Output format (defaults to webp). When a
     *                                              destination of a non-webp format (e.g. .avif)
     *                                              is passed, supply the matching format so the
     *                                              reverse extension-stripping is correct.
     *
     *  @return  string|null  The source path corresponding to a destination path
     *                        - or false on failure (if the source does not exist or $destination is not sane)
     *
     */
    public static function findSource($destination, &$config = null, $format = null)
    {
        try {
            // Check that destination path is sane and inside document root
            $destination = SanityCheck::absPathIsInDocRoot($destination);
        } catch (SanityException $e) {
            return false;
        }

        // Load config if not already loaded
        if (is_null($config)) {
            $config = Config::loadConfigAndFix();
        }

        return ConvertHelperIndependent::findSource(
            $destination,
            $config['destination-folder'],
            $config['destination-extension'],
            $config['destination-structure'],
            Paths::getMagicConvertContentDirAbs(),
            new ImageRoots(Paths::getImageRootsDef()),
            $format
        );
    }

    public static function processAjaxConvertFile()
    {

        if (!check_ajax_referer('magicconvert-ajax-convert-nonce', 'nonce', false)) {
        //if (true) {
            //wp_send_json_error('The security nonce has expired. You need to reload the settings page (press F5) and try again)');
            //wp_die();

            $result = [
                'success' => false,
                'msg' => 'The security nonce has expired. You need to reload the settings page (press F5) and try again)',
                'stop' => true
            ];

            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
            wp_die();
        }

        // Check input
        // --------------
        $converterId = null;
        $configOverrides = null;
        try {
            // Check "filename"
            $checking = '"filename" argument';
            Validate::postHasKey('filename');

            $filename = sanitize_text_field(stripslashes($_POST['filename']));

            // holy moly! Wordpress automatically adds slashes to the global POST vars - https://stackoverflow.com/questions/2496455/why-are-post-variables-getting-escaped-in-php
            $filename = wp_unslash($_POST['filename']);

            //$filename = SanityCheck::absPathExistsAndIsFileInDocRoot($filename);
            // PS: No need to check mime version as webp-convert does that.


            // Check converter id
            // ---------------------
            $checking = '"converter" argument';
            if (isset($_POST['converter'])) {
                $converterId = sanitize_text_field($_POST['converter']);
                Validate::isConverterId($converterId);
            }


            // Check "config-overrides"
            // ---------------------------
            $checking = '"config-overrides" argument';
            if (isset($_POST['config-overrides'])) {
                $configOverridesJSON = SanityCheck::noControlChars($_POST['config-overrides']);
                $configOverridesJSON = preg_replace('/\\\\"/', '"', $configOverridesJSON); // We got crazy encoding, perhaps by jQuery. This cleans it up

                $configOverridesJSON = SanityCheck::isJSONObject($configOverridesJSON);
                $configOverrides = json_decode($configOverridesJSON, true);

                // PS: We do not need to validate the overrides.
                // webp-convert checks all options. Nothing can be passed to webp-convert which causes harm.
            }

        } catch (SanityException $e) {
            wp_send_json_error('Sanitation check failed for ' . $checking . ': '. $e->getMessage());
            wp_die();
        } catch (ValidateException $e) {
            wp_send_json_error('Validation failed for ' . $checking . ': '. $e->getMessage());
            wp_die();
        }


        // Input has been processed, now lets get to work!
        // -----------------------------------------------
        // The actual conversion (config-overrides handling, specific-converter
        // option regeneration, and the final convertFile() call) lives in the
        // shared runConversion() core so the REST endpoint executes the exact same
        // code path. The AJAX path never opts into skip-if-fresh: a manual
        // single-file convert from the test/convert UI should always re-encode.
        $result = self::runConversion(
            $filename,
            $converterId,
            $configOverrides,
            false
        );

        $nonceTick = wp_verify_nonce($_REQUEST['nonce'], 'magicconvert-ajax-convert-nonce');
        if ($nonceTick == 2) {
            $result['new-convert-nonce'] = wp_create_nonce('magicconvert-ajax-convert-nonce');
            //  wp_create_nonce('magicconvert-ajax-convert-nonce')
        }

        $result['nonce-tick'] = $nonceTick;


        $result = self::utf8ize($result);

        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);

        wp_die();
    }

    /**
     *  Shared conversion core used by BOTH the admin-ajax endpoint
     *  (processAjaxConvertFile) and the REST endpoint (RestApi::convert).
     *
     *  Given an already-sanitized absolute source path, it handles the
     *  config-overrides / specific-converter option regeneration that used to live
     *  inline in the AJAX handler and dispatches to convertFile(). Keeping this in
     *  one place guarantees the security-critical path-containment validation
     *  inside convertFile() (the CVE-2019-15330 guard) runs identically for every
     *  caller — it is refactored, never reimplemented.
     *
     *  @param  string       $source           Absolute source path. Callers MUST
     *                                          have already run it through the
     *                                          relevant SanityCheck (the AJAX path
     *                                          via wp_unslash + convertFile's
     *                                          absPathExistsAndIsFile; the REST path
     *                                          via resolveImageSourcePath()).
     *  @param  string|null  $converterId      Specific converter id, or null.
     *  @param  array|null   $configOverrides  Converter option overrides, or null.
     *  @param  bool         $skipIfFresh      Pass true to skip already-fresh
     *                                          destinations (bulk/REST default
     *                                          unless the caller forces reconvert).
     *  @param  OutputFormat|string|null $format  Output format (defaults to webp).
     *                                          Threaded through to convertFile() so the
     *                                          REST /convert endpoint can request a
     *                                          specific format (e.g. 'avif').
     *
     *  @return array  The per-file result array from convertFile().
     */
    public static function runConversion($source, $converterId = null, $configOverrides = null, $skipIfFresh = false, $format = null)
    {
        if (!is_null($configOverrides)) {
            $config = Config::loadConfigAndFix();

            // convert using specific converter
            if (!is_null($converterId)) {

                // Merge in the config-overrides (config-overrides only have effect when using a specific converter)
                $config = array_merge($config, $configOverrides);

                $converter = ConvertersHelper::getConverterById($config, $converterId);
                if ($converter === false) {
                    return [
                        'success' => false,
                        'msg' => 'Converter could not be loaded',
                        'log' => '',
                    ];
                }

                // the converter options stored in config.json is not precisely the same as the ones
                // we send to webp-convert.
                // We need to "regenerate" webp-convert options in order to use the ones specified in the config-overrides
                // And we need to merge the general options (such as quality etc) into the option for the specific converter

                $generalWebpConvertOptions = Config::generateWodOptionsFromConfigObj($config)['webp-convert']['convert'];
                $converterSpecificWebpConvertOptions = isset($converter['options']) ? $converter['options'] : [];

                $webpConvertOptions = array_merge($generalWebpConvertOptions, $converterSpecificWebpConvertOptions);
                unset($webpConvertOptions['converters']);

                // what is this? - I forgot why!
                //$config = array_merge($config, $converter['options']);
                return self::convertFile($source, $config, $webpConvertOptions, $converterId, $skipIfFresh, $format);
            }

            return self::convertFile($source, $config, null, null, $skipIfFresh, $format);
        }

        return self::convertFile($source, null, null, null, $skipIfFresh, $format);
    }

    /**
     *  Resolve a REST {root, path} pair to a sanitized absolute source path.
     *
     *  The REST endpoint receives an image-root id (e.g. "uploads") plus a path
     *  RELATIVE to that root, instead of an absolute filename. This is the safer
     *  shape: the absolute base is server-controlled and the only attacker-
     *  influenced part is the relative path, which we explicitly reject for
     *  directory traversal and stream wrappers before joining.
     *
     *  Layered defenses (any one of which is sufficient on its own):
     *    1. $rootId must be a known image-root id (whitelist).
     *    2. $relPath is run through SanityCheck::path (no NUL, no control chars,
     *       no stream wrappers) and noDirectoryTraversal (no "..").
     *    3. After joining, findImageRootOfPath() (inside convertFile) re-asserts
     *       containment — the same belt-and-suspenders check the AJAX path relies
     *       on. This is the CVE-2019-15330 guard.
     *
     *  @param  string  $rootId   Image-root id.
     *  @param  string  $relPath  Path relative to that root.
     *
     *  @return string  Sanitized absolute source path.
     *
     *  @throws SanityException  When the root id is unknown or the path is unsafe.
     */
    public static function resolveImageSourcePath($rootId, $relPath)
    {
        $rootId = SanityCheck::noControlChars((string) $rootId);
        if (!in_array($rootId, Paths::getImageRootIds(), true)) {
            throw new SanityException('Unknown image root id');
        }

        $baseDir = Paths::getAbsDirById($rootId);
        if ($baseDir === false) {
            throw new SanityException('Image root could not be resolved');
        }

        // Sanitize the relative path: reject NUL/control chars, stream wrappers
        // and any directory-traversal before we ever touch the filesystem.
        $relPath = SanityCheck::path($relPath);
        $relPath = SanityCheck::noDirectoryTraversal($relPath);
        $relPath = ltrim($relPath, '/');
        if ($relPath === '') {
            throw new SanityException('Empty source path');
        }

        $source = PathHelper::canonicalize($baseDir . '/' . $relPath);

        // Final, authoritative containment + existence check. absPathExists
        // confirms it is inside any restricted open_basedir; convertFile() will
        // additionally re-run findImageRootOfPath() on it.
        return SanityCheck::absPathExistsAndIsFile($source);
    }

    private static function utf8ize($d) {
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                $d[$k] = self::utf8ize($v);
            }
        } else if (is_string ($d)) {
            return utf8_encode($d);
        }
        return $d;
    }
}
