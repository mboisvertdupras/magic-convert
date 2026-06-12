<?php

/*
This class is made to not be dependent on Wordpress functions and must be kept like that.
It is used by webp-on-demand.php. It is also used for bulk conversion.
*/
namespace MagicConvert;

use \WebPConvert\WebPConvert;
use \WebPConvert\Convert\ConverterFactory;
use \WebPConvert\Exceptions\WebPConvertException;
use \WebPConvert\Loggers\BufferLogger;

use \MagicConvert\FileHelper;
use \MagicConvert\FileLock;
use \MagicConvert\OutputFormat;
use \MagicConvert\SanityCheck;
use \MagicConvert\SanityException;

class ConvertHelperIndependent
{

    /**
     *  Compute the path of the lock file guarding a given destination.
     *
     *  Pure string logic (no filesystem access) so it can be unit-tested.
     *  The lock lives right next to the destination: '<destination>.lock'.
     *
     *  @param  string  $destination  The FINAL destination path.
     *  @return string                The lock file path.
     */
    public static function lockPathForDestination($destination)
    {
        return $destination . '.lock';
    }

    /**
     *  Compute the temp path the library converts INTO before we atomically
     *  rename it onto the final destination.
     *
     *  The temp name is derived from the final destination and MUST still end in
     *  the format extension (e.g. ".webp") so that both the plugin's own
     *  '#\.<ext>$#' destination sanity check AND the underlying encode library's
     *  destination validator accept it. We insert a per-process token before the
     *  trailing extension so two concurrent writers (which should be serialized by
     *  the lock anyway, but belt-and-suspenders) never collide on the temp file,
     *  and a crash leaves an obviously-temporary artifact next to the destination
     *  rather than a corrupt destination.
     *
     *  Example (webp):
     *    /cache/logo.jpg.webp  ->  /cache/logo.jpg.<pid>.tmp.webp
     *  Example (avif):
     *    /cache/logo.jpg.avif  ->  /cache/logo.jpg.<pid>.tmp.avif
     *
     *  Pure string logic (no filesystem access) so it can be unit-tested.
     *
     *  @param  string                      $destination  The FINAL destination path (ends in .<ext>).
     *  @param  int|null                    $pid          Process id token (defaults to getmypid()).
     *  @param  OutputFormat|string|null    $format       Output format (defaults to webp).
     *  @return string                                    The temp destination path (ends in .<ext>).
     */
    public static function tempDestinationFor($destination, $pid = null, $format = null)
    {
        if ($pid === null) {
            $pid = function_exists('getmypid') ? getmypid() : 0;
        }
        $ext = OutputFormat::coerce($format)->extension();
        // Strip a trailing ".<ext>" (case-insensitive) and re-append our token + ".<ext>",
        // guaranteeing the result still matches '#\.<ext>$#'.
        $base = preg_replace('#\.' . preg_quote($ext, '#') . '$#i', '', $destination);
        return $base . '.' . $pid . '.tmp.' . $ext;
    }

    /**
     *  Idempotency test: is an existing destination "fresh" relative to its source?
     *
     *  A destination is considered fresh when it exists and its mtime is greater
     *  than or equal to the source's mtime (i.e. it was produced from the current
     *  source and the source has not changed since). Used to skip re-encoding when
     *  the caller opts in via the 'skip-if-fresh' option.
     *
     *  Pure logic (only stat-style numeric comparison); callers pass in the mtimes
     *  so it is trivially unit-testable without touching the filesystem.
     *
     *  @param  int|false  $destinationMtime  mtime of destination, or false if missing.
     *  @param  int|false  $sourceMtime       mtime of source, or false if missing.
     *  @return bool                          True if destination is fresh (skip convert).
     */
    public static function isDestinationFresh($destinationMtime, $sourceMtime)
    {
        if ($destinationMtime === false || $sourceMtime === false) {
            return false;
        }
        return $destinationMtime >= $sourceMtime;
    }

    /**
     *
     * @return boolean  Whether or not the destination corresponding to a given source should be stored in the same folder or the separate (in wp-content/magic-convert)
     */
    private static function storeMingledOrNot($source, $destinationFolder, $uploadDirAbs)
    {
        if ($destinationFolder != 'mingled') {
            return false;
        }

        // Option is set for mingled, but this does not neccessarily means we should store "mingled".
        // - because the mingled option only applies to upload folder, the rest is stored in separate cache folder
        // So, return true, if $source is located in upload folder
        return (strpos($source, $uploadDirAbs) === 0);
    }

    /**
     *  Verify if source is inside in document root
     *  Note: This function relies on the existence of both.
     *
     *  @return true if windows; false if not.
     */
    public static function sourceIsInsideDocRoot($source, $docRoot){

        $normalizedSource = realpath($source);
        $normalizedDocRoot = realpath($docRoot);

        return strpos($normalizedSource, $normalizedDocRoot) === 0;
    }

    public static function getSource()
    {

    }

    /**
     * Append the format extension (e.g. ".webp") to path or replace the source
     * extension with it, depending on what is appropriate.
     *
     * If destination-folder is set to mingled and destination-extension is set to "set" and
     * the path is inside upload folder, the appropriate thing is to SET the extension.
     * Otherwise, it is to APPEND.
     *
     * @param  string                    $path
     * @param  string                    $destinationFolder
     * @param  string                    $destinationExt
     * @param  boolean                   $inUploadFolder
     * @param  OutputFormat|string|null  $format             Output format (defaults to webp).
     */
    public static function appendOrSetExtension($path, $destinationFolder, $destinationExt, $inUploadFolder, $format = null)
    {
        $dotExt = OutputFormat::coerce($format)->dotExtension();
        if (($destinationFolder == 'mingled') && ($destinationExt == 'set') && $inUploadFolder) {
            return preg_replace('/\\.(jpe?g|png)$/i', '', $path) . $dotExt;
        } else {
            return $path . $dotExt;
        }
    }

    /**
     * Get destination path corresponding to the source path given (and some configurations)
     *
     * If for example Operation mode is set to "mingled" and extension is set to "Append .webp",
     * the result of finding the destination path that corresponds to "/path/to/logo.jpg" will be "/path/to/logo.jpg.webp".
     *
     * @param  string   $source                     Path to source file
     * @param  string   $destinationFolder          'mingled' or 'separate'
     * @param  string   $destinationExt             Extension ('append' or 'set')
     * @param  string   $webExpressContentDirAbs
     * @param  string   $uploadDirAbs
     * @param  boolean  $useDocRootForStructuringCacheDir
     * @param  ImageRoots  $imageRoots                An image roots object
     * @param  OutputFormat|string|null  $format     Output format (defaults to webp).
     *
     * @return string|false   Returns path to destination corresponding to source, or false on failure
     */
    public static function getDestination(
        $source,
        $destinationFolder,
        $destinationExt,
        $webExpressContentDirAbs,
        $uploadDirAbs,
        $useDocRootForStructuringCacheDir,
        $imageRoots,
        $format = null)
    {
        $format = OutputFormat::coerce($format);
        $cacheDirName = $format->cacheDirName();
        // At this point, everything has already been checked for sanity. But for good meassure, lets
        // check the most important parts again. This is after all a public method.
        // ------------------------------------------------------------------

        try {
            // Check source
            // --------------
            // TODO: make this check work with symlinks
            //$source = SanityCheck::absPathExistsAndIsFileInDocRoot($source);

            // Calculate destination and check that the result is sane
            // -------------------------------------------------------
            if (self::storeMingledOrNot($source, $destinationFolder, $uploadDirAbs)) {
                $destination = self::appendOrSetExtension($source, $destinationFolder, $destinationExt, true, $format);
            } else {

                if ($useDocRootForStructuringCacheDir) {
                    // We must find the relative path from document root to source.
                    // However, we dont know if document root is resolved or not.
                    // We also do not know if source begins with a resolved or unresolved document root.
                    // And we cannot be sure that document root is resolvable.

                    // Lets say:
                    // 1. document root is unresolvable.
                    // 2. document root is configured to something unresolved ("/my-website")
                    // 3. source is resolved and within an image root ("/var/www/my-website/wp-content/uploads/test.jpg")
                    // 4. all image roots are resolvable.
                    // 5. Paths::canUseDocRootForRelPaths()) returned true

                    // Can the relative path then be found?
                    // Actually, yes.
                    // We can loop through the image roots.
                    // When we get to the "uploads" root, it must neccessarily contain the unresolved document root.
                    // It will in other words be: "my-website/wp-content/uploads"
                    // It can not be configured to the resolved path because canUseDocRootForRelPaths would have then returned false as
                    // It would not be possible to establish that "/var/www/my-website/wp-content/uploads/" is within document root, as
                    // document root is "/my-website" and unresolvable.
                    // To sum up, we have:
                    // If document root is unresolvable while canUseDocRootForRelPaths() succeeded, then the image roots will all begin with
                    // the unresolved path.
                    // In this method, if $useDocRootForStructuringCacheDir is true, then it is assumed that canUseDocRootForRelPaths()
                    // succeeded.
                    // OH!
                    // I realize that the image root can be passed as well:
                    // $imageRoot = $webExpressContentDirAbs . '/webp-images';
                    // So the question is: Will $webExpressContentDirAbs also be the unresolved path?
                    // That variable is calculated in WodConfigLoader based on various methods available.
                    // I'm not digging into it, but would expect it to in some cases be resolved. Which means that relative path can not
                    // be found.
                    // So. Lets play it safe and require that document root is resolvable in order to use docRoot for structure

                    if (!PathHelper::isDocRootAvailable()) {
                        throw new \Exception(
                            'Can not calculate destination using "doc-root" structure as document root is not available. $_SERVER["DOCUMENT_ROOT"] is empty. ' .
                            'This is probably a misconfiguration on the server. ' .
                            'However, Magic Convert can function without using documument root. If you resave options and regenerate the .htaccess files, it should ' .
                            'automatically start to structure the webp files in subfolders that are relative the image root folders rather than document-root.'
                        );
                    }

                    if (!PathHelper::isDocRootAvailableAndResolvable()) {
                        throw new \Exception(
                            'Can not calculate destination using "doc-root" structure as document root cannot be resolved for symlinks using "realpath". The ' .
                            'reason for that is probably that open_basedir protection has been set up and that document root is outside outside that open_basedir. ' .
                            'Magic Convert can function in that setting, however you will need to resave options and regenerate the .htaccess files. It should then ' .
                            'automatically stop to structure the webp files as relative to document root and instead structure them as relative to image root folders.'
                        );
                    }
                    $docRoot = rtrim(realpath($_SERVER["DOCUMENT_ROOT"]), '/');
                    $imageRoot = $webExpressContentDirAbs . '/' . $cacheDirName;

                    // TODO: make this check work with symlinks
                    //SanityCheck::absPathIsInDocRoot($imageRoot);

                    $sourceRel = substr(realpath($source), strlen($docRoot) + 1);
                    $destination = $imageRoot . '/doc-root/' . $sourceRel;
                    $destination = self::appendOrSetExtension($destination, $destinationFolder, $destinationExt, false, $format);


                    // TODO: make this check work with symlinks
                    //$destination = SanityCheck::absPathIsInDocRoot($destination);
                } else {
                    $destination = '';

                    $sourceResolved = realpath($source);


                    // Check roots until we (hopefully) get a match.
                    // (that is: find a root which the source is inside)
                    foreach ($imageRoots->getArray() as $i => $imageRoot) {
                        // in $obj, "rel-path" is only set when document root can be used for relative paths.
                        // So, if it is set, we can use it (beware: we cannot neccessarily use realpath on document root,
                        // but we do not need to - see the long comment in Paths::canUseDocRootForRelPaths())

                        $rootPath = $imageRoot->getAbsPath();
                        /*
                        if (isset($obj['rel-path'])) {
                            $docRoot = rtrim($_SERVER["DOCUMENT_ROOT"], '/');
                            $rootPath = $docRoot . '/' . $obj['rel-path'];
                        } else {
                            // If "rel-path" isn't set, then abs-path is, and we can use that.
                            $rootPath = $obj['abs-path'];
                        }*/

                        // $source may be resolved or not. Same goes for $rootPath.
                        // We can assume that $rootPath is resolvable using realpath (it ought to exist and be within open_basedir for WP to function)
                        // We can also assume that $source is resolvable (it ought to exist and within open_basedir)
                        // So: Resolve both! and test if the resolved source begins with the resolved rootPath.
                        if (strpos($sourceResolved, realpath($rootPath)) !== false) {
                            $relPath = substr($sourceResolved, strlen(realpath($rootPath)) + 1);
                            $relPath = self::appendOrSetExtension($relPath, $destinationFolder, $destinationExt, false, $format);

                            $destination = $webExpressContentDirAbs . '/' . $cacheDirName . '/' . $imageRoot->id . '/' . $relPath;
                            break;
                        }
                    }
                    if ($destination == '') {
                        return false;
                    }
                }
            }

        } catch (SanityException $e) {
            return false;
        }

        return $destination;
    }


    /**
     * Find source corresponding to destination, separate.
     *
     * We can rely on destinationExt being "append" for separate.
     * Returns false if source file is not found or if a path is not sane. Otherwise returns path to source
     * destination does not have to exist.
     *
     * @param  string      $destination               Path to destination file (does not have to exist)
     * @param  string      $destinationStructure      "doc-root" or "image-roots"
     * @param  string      $webExpressContentDirAbs
     * @param  ImageRoots  $imageRoots                An image roots object
     * @param  OutputFormat  $format                  Output format (already coerced).
     *
     * @return string|false   Returns path to source, if found. If not - or a path is not sane, false is returned
     */
    private static function findSourceSeparate($destination, $destinationStructure, $webExpressContentDirAbs, $imageRoots, $format)
    {
        $cacheDirName = $format->cacheDirName();
        $extQuoted = preg_quote($format->extension(), '/');
        try {

            if ($destinationStructure == 'doc-root') {

                // Check that destination path is sane and inside document root
                // --------------------------
                $destination = SanityCheck::absPathIsInDocRoot($destination);


                // Check that calculated image root is sane and inside document root
                // --------------------------
                $imageRoot = SanityCheck::absPathIsInDocRoot($webExpressContentDirAbs . '/' . $cacheDirName . '/doc-root');


                // Calculate source and check that it is sane and exists
                // -----------------------------------------------------

                // TODO: This does not work on Windows yet.
                if (strpos($destination, $imageRoot . '/') === 0) {

                    // "Eat" the left part off the $destination parameter. $destination is for example:
                    // "/var/www/magic-convert-tests/we0/wp-content-moved/magic-convert/webp-images/doc-root/wordpress/uploads-moved/2018/12/tegning5-300x265.jpg.webp"
                    // We also eat the slash (+1)
                    $sourceRel = substr($destination, strlen($imageRoot) + 1);

                    $docRoot = rtrim(realpath($_SERVER["DOCUMENT_ROOT"]), '/');
                    $source = $docRoot . '/' . $sourceRel;
                    $source =  preg_replace('/\\.(' . $extQuoted . ')$/', '', $source);
                } else {
                    // Try with symlinks resolved
                    // This is not trivial as this must also work when the destination path doesn't exist, and
                    // realpath can only be used to resolve symlinks for files that exists.
                    // But here is how we achieve it anyway:
                    //
                    // 1. We make sure imageRoot exists (if not, create it) - this ensures that we can resolve it.
                    // 2. Find closest folder existing folder (resolved) of destination - using PathHelper::findClosestExistingFolderSymLinksExpanded()
                    // 3. Test that resolved closest existing folder starts with resolved imageRoot
                    // 4. If it does, we could create a dummy file at the destination to get its real path, but we want to avoid that, so instead
                    //    we can create the containing directory.
                    // 5. We can now use realpath to get the resolved path of the containing directory. The rest is simple enough.
                    // Tolerant of the concurrent-creation race (@mkdir then re-check):
                    // a racer winning the create makes our mkdir fail with EEXIST, but
                    // the dir then exists, which is all realpath() below needs.
                    if (!@is_dir($imageRoot)) {
                        @mkdir($imageRoot, 0777, true);
                    }
                    $closestExistingResolved = PathHelper::findClosestExistingFolderSymLinksExpanded($destination);
                    if ($closestExistingResolved == '') {
                        return false;
                    } else {
                        $imageRootResolved = realpath($imageRoot);
                        if (strpos($closestExistingResolved . '/', $imageRootResolved . '/') === 0) {
//                            echo $destination . '<br>' . $closestExistingResolved . '<br>' . $imageRootResolved . '/'; exit;
                            // Create containing dir for destination (tolerant of the
                            // concurrent-creation race: @mkdir then re-check is_dir()).
                            $containingDir = PathHelper::dirname($destination);
                            if (!@is_dir($containingDir)) {
                                @mkdir($containingDir, 0777, true);
                            }
                            $containingDirResolved = realpath($containingDir);

                            $filename = PathHelper::basename($destination);
                            $destinationResolved = $containingDirResolved . '/' . $filename;

                            $sourceRel = substr($destinationResolved, strlen($imageRootResolved) + 1);

                            $docRoot = rtrim(realpath($_SERVER["DOCUMENT_ROOT"]), '/');
                            $source = $docRoot . '/' . $sourceRel;
                            $source =  preg_replace('/\\.(' . $extQuoted . ')$/', '', $source);
                            return $source;
                        } else {
                            return false;
                        }
                    }
                }

                return SanityCheck::absPathExistsAndIsFileInDocRoot($source);
            } else {

                // Mission: To find source corresponding to destination (separate) - using the "image-roots" structure.

                // How can we do that?
                // We got the destination (unresolved) - ie '/website-symlinked/wp-content/magic-convert/webp-images/uploads/2018/07/hello.jpg.webp'
                // If we were lazy and unprecise, we could simply:
                // - search for "magic-convert/webp-images/"
                // - strip anything before that - result: 'uploads/2018/07/hello.jpg.webp'
                // - the first path component is the root id.
                // - the rest of the path is the relative path to the source - if we strip the ".webp" ending

                // So, are we lazy? - what is the alternative?
                // - Get closest existing resolved folder of destination (ie "/var/www/website/wp-content-moved/magic-convert/webp-images/wp-content")
                // - Check if that folder is below the cache root (resolved) (cache root is the "wp-content" image root + 'magic-convert/webp-images')
                // - Create dir for destination (if missing)
                // - We can now resolve destination. With cache root also being resolved, we can get the relative dir.
                //   ie 'uploads/2018/07/hello.jpg.webp'.
                //   The first path component is the root id, the rest is the relative path to the source.

                $closestExistingResolved = PathHelper::findClosestExistingFolderSymLinksExpanded($destination);
                $cacheRoot = $webExpressContentDirAbs . '/' . $cacheDirName;
                if ($closestExistingResolved == '') {
                    return false;
                } else {
                    $cacheRootResolved = realpath($cacheRoot);
                    if (strpos($closestExistingResolved . '/', $cacheRootResolved . '/') === 0) {

                        // Create containing dir for destination (tolerant of the
                        // concurrent-creation race: @mkdir then re-check is_dir()).
                        $containingDir = PathHelper::dirname($destination);
                        if (!@is_dir($containingDir)) {
                            @mkdir($containingDir, 0777, true);
                        }
                        $containingDirResolved = realpath($containingDir);

                        $filename = PathHelper::basename($destination);
                        $destinationResolved = $containingDirResolved . '/' . $filename;
                        $destinationRelToCacheRoot = substr($destinationResolved, strlen($cacheRootResolved) + 1);

                        $parts = explode('/', $destinationRelToCacheRoot);
                        $imageRoot = array_shift($parts);
                        $sourceRel = implode('/', $parts);

                        $source = $imageRoots->byId($imageRoot)->getAbsPath() . '/' . $sourceRel;
                        $source = preg_replace('/\\.(' . $extQuoted . ')$/', '', $source);
                        return $source;
                    } else {
                        return false;
                    }
                }
                return false;
            }
        } catch (SanityException $e) {
            return false;
        }

        return $source;
    }

    /**
     * Find source corresponding to destination (mingled)
     * Returns false if not found. Otherwise returns path to source
     *
     * @param  string  $destination             Path to destination file (does not have to exist)
     * @param  string  $destinationExt          Extension ('append' or 'set')
     * @param  string  $destinationStructure    "doc-root" or "image-roots"
     * @param  OutputFormat  $format             Output format (already coerced).
     *
     * @return string|false   Returns path to source, if found. If not - or a path is not sane, false is returned
     */
    private static function findSourceMingled($destination, $destinationExt, $destinationStructure, $format)
    {
        $extQuoted = preg_quote($format->extension(), '#');
        try {

            if ($destinationStructure == 'doc-root') {
                // Check that destination path is sane and inside document root
                // --------------------------
                $destination = SanityCheck::absPathIsInDocRoot($destination);
            } else {
                // The following will fail if path contains directory traversal. TODO: Is that ok?
                $destination = SanityCheck::absPath($destination);
            }

            // Calculate source and check that it is sane and exists
            // -----------------------------------------------------
            if ($destinationExt == 'append') {
                $source =  preg_replace('#\\.(' . $extQuoted . ')$#', '', $destination);
            } else {
                $source =  preg_replace('#\\.' . $extQuoted . '$#', '.jpg', $destination);
                // TODO!
                // Also check for "Jpeg", "JpEg" etc.
                if (!@file_exists($source)) {
                    $source =  preg_replace('#\\.' . $extQuoted . '$#', '.jpeg', $destination);
                }
                if (!@file_exists($source)) {
                    $source =  preg_replace('#\\.' . $extQuoted . '$#', '.JPG', $destination);
                }
                if (!@file_exists($source)) {
                    $source =  preg_replace('#\\.' . $extQuoted . '$#', '.JPEG', $destination);
                }
                if (!@file_exists($source)) {
                    $source =  preg_replace('#\\.' . $extQuoted . '$#', '.png', $destination);
                }
                if (!@file_exists($source)) {
                    $source =  preg_replace('#\\.' . $extQuoted . '$#', '.PNG', $destination);
                }
            }
            if ($destinationStructure == 'doc-root') {
                $source = SanityCheck::absPathExistsAndIsFileInDocRoot($source);
            } else {
                $source = SanityCheck::absPathExistsAndIsFile($source);
            }


        } catch (SanityException $e) {
            return false;
        }

        return $source;
    }

    /**
     * Get source from destination (and some configurations)
     * Returns false if not found. Otherwise returns path to source
     *
     * @param  string  $destination               Path to destination file (does not have to exist). May not contain directory traversal
     * @param  string  $destinationFolder         'mingled' or 'separate'
     * @param  string  $destinationExt            Extension ('append' or 'set')
     * @param  string  $destinationStructure      "doc-root" or "image-roots"
     * @param  string  $webExpressContentDirAbs
     * @param  ImageRoots  $imageRoots                An image roots object
     * @param  OutputFormat|string|null  $format     Output format (defaults to webp).
     *
     * @return string|false  Returns path to source, if found. If not - or a path is not sane, false is returned
     */
    public static function findSource($destination, $destinationFolder, $destinationExt, $destinationStructure, $webExpressContentDirAbs, $imageRoots, $format = null)
    {
        $format = OutputFormat::coerce($format);

        try {

            if ($destinationStructure == 'doc-root') {
                // Check that destination path is sane and inside document root
                // --------------------------
                $destination = SanityCheck::absPathIsInDocRoot($destination);
            } else {
                // The following will fail if path contains directory traversal. TODO: Is that ok?
                $destination = SanityCheck::absPath($destination);
            }

        } catch (SanityException $e) {
            return false;
        }

        if ($destinationFolder == 'mingled') {
            $result = self::findSourceMingled($destination, $destinationExt, $destinationStructure, $format);
            if ($result === false) {
                $result = self::findSourceSeparate($destination, $destinationStructure, $webExpressContentDirAbs, $imageRoots, $format);
            }
            return $result;
        } else {
            return self::findSourceSeparate($destination, $destinationStructure, $webExpressContentDirAbs, $imageRoots, $format);
        }
    }

    /**
     *
     * @param  string                    $source  Path to source file
     * @param  string                    $logDir  The folder where log files are kept
     * @param  OutputFormat|string|null  $format  Output format (defaults to webp).
     *
     * @return string|false   Returns computed filename of log - or false if a path is not sane
     *
     * LOG LAYOUT (Phase 2.1): logs are stored per-format under
     * '/log/conversions/<format-id>/doc-root/...'. The format-id subdir lets
     * webp and avif conversions of the same source keep distinct logs instead of
     * clobbering each other. This is an internal-only path change (no public
     * compat needed in a fork); old webp logs are migrated by convention (see
     * the migration note in the roadmap summary) and the log viewer
     * (ConvertLog.php) is adjusted to the new layout.
     */
    public static function getLogFilename($source, $logDir, $format = null)
    {
        $formatId = OutputFormat::coerce($format)->id();
        try {

            // Check that source path is sane and inside document root
            // -------------------------------------------------------
            $source = SanityCheck::absPathIsInDocRoot($source);


            // Check that log path is sane and inside document root
            // -------------------------------------------------------
            $logDir = SanityCheck::absPathIsInDocRoot($logDir);


            // Compute and check log path
            // --------------------------
            // Per-format subdir: /log/conversions/<format-id>/...
            $logDir .= '/conversions/' . $formatId;

            // We store relative to document root.
            // "Eat" the left part off the source parameter which contains the document root.
            // and also eat the slash (+1)

            $docRoot = rtrim(realpath($_SERVER["DOCUMENT_ROOT"]), '/');
            $sourceRel = substr($source, strlen($docRoot) + 1);
            $logFileName = $logDir . '/doc-root/' . $sourceRel . '.md';
            SanityCheck::absPathIsInDocRoot($logFileName);

        } catch (SanityException $e) {
            return false;
        }
        return $logFileName;

    }

    /**
     * Create the directory for log files and put a .htaccess file into it, which prevents
     * it to be viewed from the outside (not that it contains any sensitive information btw, but for good measure).
     *
     * @param  string  $logDir  The folder where log files are kept
     *
     * @return boolean  Whether it was created successfully or not.
     *
     */
    private static function createLogDir($logDir)
    {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
            @chmod($logDir, 0775);
            @file_put_contents(rtrim($logDir . '/') . '/.htaccess', <<<APACHE
<IfModule mod_authz_core.c>
Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
Order deny,allow
Deny from all
</IfModule>
APACHE
            );
            @chmod($logDir . '/.htaccess', 0664);
        }
        return is_dir($logDir);
    }

    /**
     * Saves the log file corresponding to a conversion.
     *
     * @param  string                    $source   Path to the source file that was converted
     * @param  string                    $logDir   The folder where log files are kept
     * @param  string                    $text     Content of the log file
     * @param  string                    $msgTop   A message that is printed before the conversion log (containing version info)
     * @param  OutputFormat|string|null  $format   Output format (defaults to webp).
     *
     *
     */
    private static function saveLog($source, $logDir, $text, $msgTop, $format = null)
    {

        if (!file_exists($logDir)) {
            self::createLogDir($logDir);
        }

        $text = preg_replace('#' . preg_quote($_SERVER["DOCUMENT_ROOT"]) . '#', '[doc-root]', $text);

        // TODO: Put version number somewhere else. Ie \MagicConvert\VersionNumber::version
        $text = 'Magic Convert 0.25.14. ' . $msgTop . ', ' . date("Y-m-d H:i:s") . "\n\r\n\r" . $text;

        $logFile = self::getLogFilename($source, $logDir, $format);

        if ($logFile === false) {
            return;
        }

        $logFolder = @dirname($logFile);
        // Tolerant of the concurrent-creation race: @mkdir then re-check is_dir().
        if (!@is_dir($logFolder)) {
            @mkdir($logFolder, 0777, true);
        }
        if (@is_dir($logFolder)) {
            // Atomic write (temp + rename). The same-destination lock already
            // serializes same-source log writes; temp+rename adds crash safety so
            // a killed process never leaves a truncated .md log behind.
            FileHelper::atomicPutContents($logFile, $text);
        }
    }

    /**
     * Trigger an actual conversion with webp-convert.
     *
     * PS: To convert with a specific converter, set it in the $converter param.
     *
     * @param  string                    $source          Full path to the source file that was converted.
     * @param  string                    $destination     Full path to the destination file (may exist or not).
     * @param  array                     $convertOptions  Conversion options.
     * @param  string                    $logDir          The folder where log files are kept or null for no logging
     * @param  string                    $converter       (optional) Set it to convert with a specific converter.
     * @param  OutputFormat|string|null  $format          (optional) Output format (defaults to webp). The
     *                                                     encode dispatch is WebP-only for now; a non-webp
     *                                                     format throws a clear "not yet supported" exception
     *                                                     (the AVIF encoder lands in step 2.3).
     *
     * Concurrency / atomicity (Phase 1.1):
     *  - A cross-process lock on '<destination>.lock' serializes writers of the
     *    same destination (parallel FPM requests AND concurrent CLI procs). When
     *    the lock is held by another live process this returns a structured,
     *    non-fatal failure with 'status' => 'in-progress' so bulk callers can retry.
     *  - Idempotency: when $convertOptions['skip-if-fresh'] === true and the
     *    destination already exists and is newer than the source, the conversion
     *    is skipped and 'status' => 'already-converted' is returned. Without the
     *    flag the behaviour is exactly as before (always (re)convert) so explicit
     *    reconvert from the UI and the wod path keep their semantics.
     *  - Atomic write: the library converts into '<destination>.<pid>.tmp.webp'
     *    and we rename() that onto the final destination on success. On any
     *    failure/exception the temp file is removed in the finally block, so a
     *    concurrent reader never sees a half-written destination.
     */
    public static function convert($source, $destination, $convertOptions, $logDir = null, $converter = null, $format = null) {
        include_once __DIR__ . '/../../vendor/autoload.php';

        $format = OutputFormat::coerce($format);
        $extQuoted = preg_quote($format->extension(), '#');

        // The 'skip-if-fresh' flag is a plugin-level option, not a webp-convert
        // option. Pull it out so it never reaches the library.
        $skipIfFresh = false;
        if (is_array($convertOptions) && isset($convertOptions['skip-if-fresh'])) {
            $skipIfFresh = ($convertOptions['skip-if-fresh'] === true);
            unset($convertOptions['skip-if-fresh']);
        }

        // At this point, everything has already been checked for sanity. But for good meassure, lets
        // check the most important parts again. This is after all a public method.
        // ------------------------------------------------------------------
        try {

            // Check that source path is sane, exists, is a file and is inside document root
            // -------------------------------------------------------
            $source = SanityCheck::absPathExistsAndIsFileInDocRoot($source);


            // Check that destination path is sane and is inside document root
            // -------------------------------------------------------
            // NOTE: We validate the FINAL destination here. The temp file we hand
            // to the library is derived from it and also ends in the format
            // extension (e.g. ".webp"), so it satisfies both this check and the
            // library's own validator.
            $destination = SanityCheck::absPathIsInDocRoot($destination);
            $destination = SanityCheck::pregMatch('#\.' . $extQuoted . '$#', $destination, 'Destination does not end with .' . $format->extension());


            // Check that log path is sane and inside document root
            // -------------------------------------------------------
            if (!is_null($logDir)) {
                $logDir = SanityCheck::absPathIsInDocRoot($logDir);
            }


            // PS: No need to check $logMsgTop. Log files are markdown and stored as ".md". They can do no harm.

        } catch (SanityException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
                'log' => '',
            ];
        }

        // Acquire the cross-process lock guarding this destination.
        // -------------------------------------------------------
        $lockPath = self::lockPathForDestination($destination);
        $lockToken = FileLock::acquire($lockPath);
        if ($lockToken === false) {
            // Another process is converting this exact destination right now.
            // Surface a distinct, non-fatal status so callers can retry rather
            // than treat it as a hard failure.
            return [
                'success' => false,
                'status' => 'in-progress',
                'msg' => 'Conversion already in progress for this destination (held by another process)',
                'log' => '',
            ];
        }

        // Everything from here MUST release the lock (and clean up the temp file).
        $tempDestination = self::tempDestinationFor($destination, null, $format);
        $success = false;
        $msg = '';
        $logger = new BufferLogger();
        try {

            // Idempotency: skip re-encoding when the caller opted in and the
            // destination is already fresh relative to the source.
            if ($skipIfFresh && self::isDestinationFresh(@filemtime($destination), @filemtime($source))) {
                return [
                    'success' => true,
                    'status' => 'already-converted',
                    'msg' => '',
                    'log' => '',
                ];
            }

            try {
                // Encode dispatch is WebP-only for now. The OutputFormat parameter is
                // threaded everywhere (paths, temp names, logs, markers, cache dirs)
                // so the rest of the core is multi-format ready, but the actual
                // encoder for non-webp formats arrives in step 2.3. Until then any
                // non-webp format is a clear, logged failure rather than a silent
                // mis-encode.
                if (!$format->isDefault()) {
                    throw new \Exception(
                        'Output format "' . $format->id() . '" is not yet supported by the conversion core ' .
                        '(the ' . strtoupper($format->id()) . ' encoder is added in a later step). Only "webp" is currently encodable.'
                    );
                }

                if (!is_null($converter)) {
                //if (isset($convertOptions['converter'])) {
                    //print_r($convertOptions);exit;
                    $logger->logLn('Converter set to: ' . $converter);
                    $logger->logLn('');
                    $converterObj = ConverterFactory::makeConverter($converter, $source, $tempDestination, $convertOptions, $logger);
                    $converterObj->doConvert();
                } else {
    //error_log('options:' . print_r(json_encode($convertOptions,JSON_PRETTY_PRINT), true));
                    WebPConvert::convert($source, $tempDestination, $convertOptions, $logger);
                }

                // The library wrote (atomically, for the 'auto' path) into the temp
                // file. Atomically move it onto the final destination. rename() within
                // the same directory is atomic on POSIX, so a concurrent reader sees
                // either the previous destination or the new one, never a partial file.
                if (@file_exists($tempDestination)) {
                    if (@rename($tempDestination, $destination)) {
                        $success = true;
                    } else {
                        $msg = 'Conversion succeeded but the converted file could not be moved into place';
                    }
                } else {
                    // Defensive: library reported success but produced no file.
                    $msg = 'Conversion did not produce an output file';
                }
            } catch (\WebpConvert\Exceptions\WebPConvertException $e) {
                $msg = $e->getMessage();
            } catch (\Exception $e) {
                //$msg = 'An exception was thrown!';
                $msg = $e->getMessage();
            } catch (\Throwable $e) {
                //Executed only in PHP 7 and 8, will not match in PHP 5
                $msg = $e->getMessage();
            }

            if (!is_null($logDir)) {
                self::saveLog($source, $logDir, $logger->getMarkDown("\n\r"), 'Conversion triggered using bulk conversion', $format);
            }

            return [
                'success' => $success,
                'msg' => $msg,
                'log' => $logger->getMarkDown("\n"),
            ];
        } finally {
            // Always clean up the temp file (it only lingers on failure/crash-before-rename)
            // and always release the lock.
            if (@file_exists($tempDestination)) {
                @unlink($tempDestination);
            }
            // Release only OUR lock: release() verifies the token still matches,
            // so if this conversion ran long and another process stole the lock
            // as stale and re-acquired it, we will not delete their live lock.
            FileLock::release($lockPath, $lockToken);
        }
    }

    /**
     *  Serve a converted file (if it does not already exist, a conversion is triggered - all handled in webp-convert).
     *
     *  @param  OutputFormat|string|null  $format  Output format (defaults to webp). On-demand serving stays
     *                                             WebP-only by default for now (on-demand AVIF is gated and
     *                                             arrives in step 2.4); the parameter is threaded so the path
     *                                             validation / inner convert() / log layout are format-aware.
     */
    public static function serveConverted($source, $destination, $serveOptions, $logDir = null, $logMsgTop = '', $format = null)
    {
        include_once __DIR__ . '/../../vendor/autoload.php';

        $format = OutputFormat::coerce($format);
        $extQuoted = preg_quote($format->extension(), '#');

        // At this point, everything has already been checked for sanity. But for good meassure, lets
        // check again. This is after all a public method.
        // ---------------------------------------------
        try {

            // Check that source path is sane, exists, is a file.
            // -------------------------------------------------------
            //$source = SanityCheck::absPathExistsAndIsFileInDocRoot($source);
            $source = SanityCheck::absPathExistsAndIsFile($source);


            // Check that destination path is sane
            // -------------------------------------------------------
            //$destination = SanityCheck::absPathIsInDocRoot($destination);
            $destination = SanityCheck::absPath($destination);
            $destination = SanityCheck::pregMatch('#\.' . $extQuoted . '$#', $destination, 'Destination does not end with .' . $format->extension());


            // Check that log path is sane
            // -------------------------------------------------------
            //$logDir = SanityCheck::absPathIsInDocRoot($logDir);
            if ($logDir != null) {
                $logDir = SanityCheck::absPath($logDir);
            }

            // PS: No need to check $logMsgTop. Log files are markdown and stored as ".md". They can do no harm.

        } catch (SanityException $e) {
            $msg = $e->getMessage();
            echo $msg;
            header('X-Magic-Convert-Error: ' . $msg, true);
            // TODO: error_log() ?
            exit;
        }

        // Concurrency hardening of the on-demand (wod) serve-and-convert path
        // (Phase 1.1).
        // -------------------------------------------------------------------
        // The library's serveConverted() converts directly into $destination when
        // the file is missing — a non-atomic write that a second concurrent reader
        // could observe half-finished. To avoid that, when the destination is
        // missing we first run our OWN hardened convert() (cross-process lock +
        // convert-into-temp + atomic rename + idempotency). On success the library
        // call below simply finds and serves the now-existing, fully-written file.
        //
        // We only intervene when the destination is missing AND we have convert
        // options to work with; everything else (serving an existing file, serving
        // the original, header/redirect handling, serve-on-failure) is left to the
        // library exactly as before.
        //
        // Residual race (acceptable): if our convert() reports 'in-progress' (a
        // sibling process holds the lock for this exact destination), we fall
        // through to the library serve. The sibling is writing atomically via our
        // path, so the worst case is that this request's library call also tries to
        // convert and writes $destination directly; because that only happens for
        // the rare overlapping first-request-per-missing-file, and any file written
        // by our path lands atomically, a corrupt file is never *served* from our
        // path. This narrow window is documented in docs/development.md.
        if (!@file_exists($destination)
            && isset($serveOptions['convert'])
            && is_array($serveOptions['convert'])
        ) {
            $preConvertResult = self::convert($source, $destination, $serveOptions['convert'], $logDir, null, $format);
            // If another process holds the lock ('in-progress'), do nothing special
            // here — fall through and let the library serve/convert as a fallback.
            // On our success the file now exists and the library just serves it.
        }

        $convertLogger = new BufferLogger();
        WebPConvert::serveConverted($source, $destination, $serveOptions, null, $convertLogger);
        if (!is_null($logDir)) {
            $convertLog = $convertLogger->getMarkDown("\n\r");
            if ($convertLog != '') {
                self::saveLog($source, $logDir, $convertLog, $logMsgTop, $format);
            }
        }
    }
}
