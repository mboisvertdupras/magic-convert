<?php

namespace MagicConvert;

use \MagicConvert\Convert;
use \MagicConvert\FileHelper;
use \MagicConvert\DismissableMessages;
use \MagicConvert\OutputFormat;
use \MagicConvert\Paths;
use MagicConvert\MediaLibraryHelper;



// TODO! Needs to be updated to work with the new "destination-structure" setting

class CachePurge
{

    private static function formatExtAlternation()
    {
        $exts = [];
        foreach (OutputFormat::all() as $format) {
            $exts[] = preg_quote($format->extension(), '#');
        }
        return '(?:' . implode('|', $exts) . ')';
    }

    /**
     *  @return OutputFormat|null
     */
    private static function formatForFilename($filename)
    {
        foreach (OutputFormat::all() as $format) {
            if (preg_match('#\.' . preg_quote($format->extension(), '#') . '$#', $filename)) {
                return $format;
            }
        }
        return null;
    }

    public static function purge($config, $onlyPng)
    {
        DismissableMessages::dismissMessage('0.14.0/suggest-wipe-because-lossless');

        $filter = [
            'only-png' => $onlyPng,
            'only-with-corresponding-original' => false,
            'check-if-registered' => ($config['destination-folder'] === 'mingled')
        ];

        $numDeleted = 0;
        $numFailed = 0;

        foreach (OutputFormat::all() as $format) {
            $cacheDir = Paths::getCacheDirAbs($format);
            list($d, $f) = self::purgeConvertedFilesInDir($cacheDir, $filter, $config);
            $numDeleted += $d;
            $numFailed += $f;
            FileHelper::removeEmptySubFolders($cacheDir);

            $markerDir = Paths::getBiggerThanSourceDirAbs($format);
            self::purgeConvertedFilesInDir($markerDir, $filter, $config);
            FileHelper::removeEmptySubFolders($markerDir);
        }

        if ($config['destination-folder'] == 'mingled') {
            list($d, $f) = self::purgeConvertedFilesInDir(Paths::getUploadDirAbs(), $filter, $config);

            $numDeleted += $d;
            $numFailed += $f;
        }

        return [
            'delete-count' => $numDeleted,
            'fail-count' => $numFailed
        ];

        //$successInRemovingCacheDir = FileHelper::rrmdir(Paths::getCacheDirAbs());

    }

    /**
     *  Purge converted artifacts (.webp AND .avif, data-driven from OutputFormat::all())
     *  in a dir.
     *  Warning: the "only-png" option only works for mingled mode.
     *           (when not mingled, you can simply delete the whole cache dir instead)
     *
     *  @param $filter.
     *            only-png:   If true, it will only be deleted if extension is .png.<fmt> or a corresponding png exists.
     *
     *  @return [num files deleted, num files failed to delete]
     */
    private static function purgeConvertedFilesInDir($dir, &$filter, &$config)
    {
        if (!@file_exists($dir) || !@is_dir($dir)) {
            return [0, 0];
        }

        $extAlt = self::formatExtAlternation();

        $numFilesDeleted = 0;
        $numFilesFailedDeleting = 0;

        $fileIterator = new \FilesystemIterator($dir);
        while ($fileIterator->valid()) {
            $filename = $fileIterator->getFilename();

            if (($filename != ".") && ($filename != "..")) {

                if (@is_dir($dir . "/" . $filename)) {
                    list($r1, $r2) = self::purgeConvertedFilesInDir($dir . "/" . $filename, $filter, $config);
                    $numFilesDeleted += $r1;
                    $numFilesFailedDeleting += $r2;
                } else {

                    // its a file
                    // Run through filters, which each may set "skipThis" to true

                    $skipThis = false;

                    // filter: It must be a converted artifact (any registered format extension)
                    if (!$skipThis && !preg_match('#\.' . $extAlt . '$#', $filename)) {
                        $skipThis = true;
                    }

                    // filter: never delete media library originals
                    if (!$skipThis && $filter['check-if-registered'] && MediaLibraryHelper::isRegisteredAttachmentOrThumbnail($dir . '/' . $filename)) {
                        $skipThis = true;

                    }

                    $fileFormat = self::formatForFilename($filename);

                    // filter: only with corresponding original
                    $source = '';
                    if (!$skipThis && $filter['only-with-corresponding-original']) {
                        $source = Convert::findSource($dir . "/" . $filename, $config, $fileFormat);
                        if ($source === false) {
                            $skipThis = true;
                        }
                    }

                    // filter: only png
                    if (!$skipThis && $filter['only-png']) {

                        // turn logic around - we skip deletion, unless we deem it a png
                        $skipThis = true;

                        // If extension is "png.<fmt>" (e.g. png.webp / png.avif), its a png
                        if (preg_match('#\.png\.' . $extAlt . '$#', $filename)) {
                            // its a png
                            $skipThis = false;
                        } else {
                            if (preg_match('#\.jpe?g\.' . $extAlt . '$#', $filename)) {
                                // It is a jpeg, no need to investigate further.
                            } else {

                                if (!$filter['only-with-corresponding-original']) {
                                    $source = Convert::findSource($dir . "/" . $filename, $config, $fileFormat);
                                }
                                if ($source === false) {
                                    // We could not find corresponding source.
                                    // Should we delete?
                                    // No, I guess we need more evidence, so we skip
                                    // In the future, we could detect mime
                                } else {
                                    if (preg_match('#\.png$#', $source)) {
                                        // its a png
                                        $skipThis = false;
                                    }
                                }
                            }

                        }

                    }

                    if (!$skipThis) {
                        if (@unlink($dir . "/" . $filename)) {
                            $numFilesDeleted++;
                        } else {
                            $numFilesFailedDeleting++;
                        }
                    }
                }
            }
            $fileIterator->next();
        }
        return [$numFilesDeleted, $numFilesFailedDeleting];
    }

    public static function processAjaxPurgeCache()
    {

        if (!check_ajax_referer('magicconvert-ajax-purge-cache-nonce', 'nonce', false)) {
            wp_send_json_error('The security nonce has expired. You need to reload the settings page (press F5) and try again)');
            wp_die();
        }

        $onlyPng = (sanitize_text_field($_POST['only-png']) == 'true');

        $config = Config::loadConfigAndFix();
        $result = self::purge($config, $onlyPng);

        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        wp_die();
    }
}
