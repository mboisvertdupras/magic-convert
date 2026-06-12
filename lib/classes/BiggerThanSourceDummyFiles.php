<?php

/*
This class is made to not be dependent on Wordpress functions and must be kept like that.
It is used by webp-on-demand.php. It is also used for bulk conversion.
*/
namespace MagicConvert;


class BiggerThanSourceDummyFiles
{


    /**
     * Create the directory for log files and put a .htaccess file into it, which prevents
     * it to be viewed from the outside (not that it contains any sensitive information btw, but for good measure).
     *
     * @param  string  $logDir  The folder where log files are kept
     *
     * @return boolean  Whether it was created successfully or not.
     *
     */
    private static function createBiggerThanSourceBaseDir($dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            @chmod($dir, 0775);
            @file_put_contents(rtrim($dir . '/') . '/.htaccess', <<<APACHE
<IfModule mod_authz_core.c>
Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
Order deny,allow
Deny from all
</IfModule>
APACHE
            );
            @chmod($dir . '/.htaccess', 0664);
        }
        return is_dir($dir);
    }

    /**
     * @param  OutputFormat|string|null  $format  Output format (defaults to webp).
     */
    public static function pathToDummyFile($source, $basedir, $imageRoots, $destinationFolder, $destinationExt, $format = null)
    {
        $sourceResolved = realpath($source);

        // Check roots until we (hopefully) get a match.
        // (that is: find a root which the source is inside)
        foreach ($imageRoots->getArray() as $i => $imageRoot) {
            $rootPath = $imageRoot->getAbsPath();

            // We can assume that $rootPath is resolvable using realpath (it ought to exist and be within open_basedir for WP to function)
            // We can also assume that $source is resolvable (it ought to exist and within open_basedir)
            // So: Resolve both! and test if the resolved source begins with the resolved rootPath.
            if (strpos($sourceResolved, realpath($rootPath)) !== false) {
                $relPath = substr($sourceResolved, strlen(realpath($rootPath)) + 1);
                $relPath = ConvertHelperIndependent::appendOrSetExtension($relPath, $destinationFolder, $destinationExt, false, $format);

                return $basedir . '/' . $imageRoot->id . '/' . $relPath;
                break;
            }
        }
        return false;
    }

    public static function pathToDummyFileRootAndRelKnown($source, $basedir, $rootId, $destinationFolder, $destinationExt)
    {
    }

    /**
     * Check if the converted file is bigger than original.
     *
     * @return boolean|null   True if it is bigger than original, false if not. NULL if it cannot be determined
     */
    public static function bigger($source, $destination)
    {
        /*
        if ((!@file_exists($source)) || (!@file_exists($destination) {
            return null;
        }*/
        $filesizeDestination = @filesize($destination);
        $filesizeSource = @filesize($source);

        // sizes are FALSE on failure (ie if file does not exists)
        if (($filesizeDestination === false) || ($filesizeDestination === false)) {
            return null;
        }

        return ($filesizeDestination > $filesizeSource);
    }

    /**
     * Update the status for a single image (when rootId is unknown)
     *
     * Checks if the converted file is bigger than original. If it is, a dummy file is placed.
     * Otherwise, it is removed (if exists)
     *
     * @param  string                    $source   Path to the source file that was converted
     * @param  OutputFormat|string|null  $format   Output format (defaults to webp).
     *
     * MARKER DESIGN (Phase 2.1): per-format marker base dir named
     * '<cacheDirName>-bigger-than-source' (webp -> 'webp-images-bigger-than-source',
     * unchanged; avif -> 'avif-images-bigger-than-source'). Inside it the marker
     * filename carries the format extension, so webp and avif markers for the same
     * source never collide. Webp markers are byte-for-byte compatible with existing
     * ones (no migration needed).
     */
    public static function updateStatus($source, $destination, $webExpressContentDirAbs, $imageRoots, $destinationFolder, $destinationExt, $format = null)
    {
        $format = OutputFormat::coerce($format);
        $basedir = $webExpressContentDirAbs . '/' . $format->cacheDirName() . '-bigger-than-source';
        if (!file_exists($basedir)) {
            self::createBiggerThanSourceBaseDir($basedir);
        }
        $bigWebP = BiggerThanSource::bigger($source, $destination);

        $file = self::pathToDummyFile($source, $basedir, $imageRoots, $destinationFolder, $destinationExt, $format);
        if ($file === false) {
            return;
        }

        if ($bigWebP === true) {
            // place dummy file, which marks that webp is bigger than source

            // Tolerant of the concurrent-creation race: @mkdir then re-check is_dir().
            $folder = @dirname($file);
            if (!@is_dir($folder)) {
                @mkdir($folder, 0777, true);
            }
            if (@is_dir($folder)) {
                // The dummy file is empty; a partial/racing write is harmless (its
                // mere existence is the signal), so a plain write is fine here.
                @file_put_contents($file, '');
            }

        } else {
            // remove dummy file (if exists)
            if (@file_exists($file)) {
                @unlink($file);
            }
        }

    }

}
