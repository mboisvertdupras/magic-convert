<?php

namespace MagicConvert;

use \MagicConvert\FileHelper;
use \MagicConvert\Multisite;
use \MagicConvert\PathHelper;

class Paths
{
    public static function areAllImageRootsWithinDocRoot() {
        if (!PathHelper::isDocRootAvailable()) {
            return false;
        }

        $roots = self::getImageRootIds();
        foreach ($roots as $dirId) {
            $dir = self::getAbsDirById($dirId);
            if (!PathHelper::canCalculateRelPathFromDocRootToDir($dir)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if we can use document root for calculating relative paths (which may not contain "/.." directory traversal)
     *
     * Note that this method allows document root to be outside open_basedir as long as document root is
     * non-empty AND it is possible to calculate relative paths to all image roots (including "index").
     * Here is a case when a relative CAN be calculated:
     * - Document root is configured to "/var/www/website" - which is also the absolute file path.
     * - open_basedir is set to "/var/www/website/wordpress"
     * - uploads is in "/var/www/website/wordpress/wp-content/uploads" (within open_basedir, as it should)
     * - "/wp-uploads" symlinks to "/var/www/website/wordpress")
     * - Wordpress has been configured to use "/wp-uploads" path for uploads.
     *
     * What happens?
     * First, it is tested if the configured upload path ("/wp-uploads") begins with the configured document root ("/var/www/website").
     * This fails.
     * Next, it is tested if the uploads path can be resolved. It can, as it is within the open_basedir.
     * Next, it is tested if the *resolved* the uploads path begins with the configured document root.
     * As "/var/www/website/wordpress/wp-content/uploads" begins with "/var/www/website", we have a match.
     * The relative path can be calculated to be "wordpress/wp-content/uploads".
     * Later, when the relative path is used, it will be used as $docRoot + "/" + $relPath, which
     * will be "/var/www/website/wordpress/wp-content/uploads". All is well.
     *
     * Here is a case where it CAN NOT be calculated:
     * - Document root is configured to "/the-website", which symlinks to "/var/www/website"
     * - open_basedir is set to "/var/www/website/wordpress"
     * - uploads is in "/var/www/website/wordpress/wp-content/uploads" and wordpress is configured to use that upload path.
     *
     * What happens?
     * First, it is tested if the configured upload path begins with the configured document root
     * "/var/www/website/wordpress/wp-content/uploads" does not begin with "/the-website", so it fails.
     * Next, it is tested if the *resolved* the uploads path begins with the configured document root.
     * The resolved uploads path is the same as the configured so it also fails.
     * Next, it is tested if Document root can be resolved. It can not, as the resolved path is not within open_basedir.
     * If it could, it would have been tested if the resolved path begins with the resolved document root and we would have
     * gotten a yes, and the relative path would have been "wordpress/wp-content/uploads" and it would work.
     * However: Document root could not be resolved and we could not get a result.
     * To sum the scenario up:
     * If document root is configured to a symlink which cannot be resolved then it will only be possible to get relative paths
     * when all other configured paths begins are relative to that symlink.
     */
    public static function canUseDocRootForRelPaths() {
        if (!PathHelper::isDocRootAvailable()) {
            return false;
        }
        return self::areAllImageRootsWithinDocRoot();
    }

    public static function canCalculateRelPathFromDocRootToDir($absPath) {
    }

    /**
     * Check if we can use document root for structuring the cache dir.
     *
     * In order to structure the images by doc-root, Magic Convert needs all images to be within document root.
     * Does Magic Convert in addition to this need to be able to resolve document root?
     * Short answer is yes.
     * The long answer is available as a comment inside ConvertHelperIndependent::getDestination()
     *
     */
    public static function canUseDocRootForStructuringCacheDir() {
        return (PathHelper::isDocRootAvailableAndResolvable() && self::canUseDocRootForRelPaths());
    }

    public static function docRootStatusText()
    {
        if (!PathHelper::isDocRootAvailable()) {
            if (!isset($_SERVER['DOCUMENT_ROOT'])) {
                return 'Unavailable (DOCUMENT_ROOT is not set in the global $_SERVER var)';
            }
            if ($_SERVER['DOCUMENT_ROOT'] == '') {
                return 'Unavailable (empty string)';
            }
            return 'Unavailable';
        }

        $imageRootsWithin = self::canUseDocRootForRelPaths();
        if (!PathHelper::isDocRootAvailableAndResolvable()) {
            $status = 'Available, but either non-existing or not within open_basedir.' .
                ($imageRootsWithin ? '' : ' And not all image roots are within that document root.');
        } elseif (!$imageRootsWithin) {
            $status = 'Available, but not all image roots are within that document root.';
        } else {
            $status = 'Available and its "realpath" is available too.';
        }
        if (self::canUseDocRootForStructuringCacheDir()) {
            $status .= ' Can be used for structuring cache dir.';
        } else {
            $status .= ' Cannot be used for structuring cache dir.';
        }
        return $status;
    }

    public static function getAbsDirId($absDir) {
        switch ($absDir) {
            case self::getContentDirAbs():
                return 'wp-content';
            case self::getIndexDirAbs():
                return 'index';
            case self::getHomeDirAbs():
                return 'home';
            case self::getPluginDirAbs():
                return 'plugins';
            case self::getUploadDirAbs():
                return 'uploads';
            case self::getThemesDirAbs():
                return 'themes';
            case self::getCacheDirAbs():
                return 'cache';
        }
        return false;
    }

    public static function getAbsDirById($dirId) {
        switch ($dirId) {
            case 'wp-content':
                return self::getContentDirAbs();
            case 'index':
                return self::getIndexDirAbs();
            case 'home':
                // "home" is still needed (used in PluginDeactivate.php)
                return self::getHomeDirAbs();
            case 'plugins':
                return self::getPluginDirAbs();
            case 'uploads':
                return self::getUploadDirAbs();
            case 'themes':
                return self::getThemesDirAbs();
            case 'cache':
                return self::getCacheDirAbs();
        }
        return false;
    }

    /**
     * Get ids for folders where SOURCE images may reside
     */
    public static function getImageRootIds() {
        return ['uploads', 'themes', 'plugins', 'wp-content', 'index'];
    }

    /**
     * Find which rootId a path belongs to.
     *
     * Note: If the root ids passed are ordered the way getImageRootIds() returns them, the root id
     * returned will be the "deepest"
     */
    public static function findImageRootOfPath($path, $rootIdsToSearch) {
        foreach ($rootIdsToSearch as $rootId) {
            if (PathHelper::isPathWithinExistingDirPath($path, self::getAbsDirById($rootId))) {
                return $rootId;
            }
        }
        return false;
    }

    public static function getImageRootsDefForSelectedIds($ids) {
        $canUseDocRootForRelPaths = self::canUseDocRootForRelPaths();

        $mapping = [];
        foreach ($ids as $rootId) {
            $obj = [
                'id' => $rootId,
            ];
            $absPath = self::getAbsDirById($rootId);
            if ($canUseDocRootForRelPaths) {
                $obj['rel-path'] = PathHelper::getRelPathFromDocRootToDirNoDirectoryTraversalAllowed($absPath);
            } else {
                $obj['abs-path'] = $absPath;
            }
            $obj['url'] = self::getUrlById($rootId);
            $mapping[] = $obj;
        }
        return $mapping;
    }

    public static function getImageRootsDef()
    {
        return self::getImageRootsDefForSelectedIds(self::getImageRootIds());
    }

    public static function filterOutSubRoots($rootIds)
    {
        // Get dirs of enabled roots
        $dirs = [];
        foreach ($rootIds as $rootId) {
            $dirs[] = self::getAbsDirById($rootId);
        }

        // Filter out dirs which are below other dirs
        $dirsToSkip = [];
        foreach ($dirs as $dirToExamine) {
            foreach ($dirs as $dirToCompareAgainst) {
                if ($dirToExamine == $dirToCompareAgainst) {
                    continue;
                }
                if (self::isDirInsideDir($dirToExamine, $dirToCompareAgainst)) {
                    $dirsToSkip[] = $dirToExamine;
                    break;
                }
            }
        }
        $dirs = array_diff($dirs, $dirsToSkip);

        // back to ids
        $result = [];
        foreach ($dirs as $dir) {
            $result[] = self::getAbsDirId($dir);
        }
        return $result;
    }

    public static function createDirIfMissing($dir)
    {
        if (!@file_exists($dir)) {
            // We use the wp_mkdir_p, because it takes care of setting folder
            // permissions to that of parent, and handles creating deep structures too
            wp_mkdir_p($dir);
        }
        return file_exists($dir);
    }

    /**
    *  Find out if $dir1 is inside - or equal to - $dir2
    */
    public static function isDirInsideDir($dir1, $dir2)
    {
        $rel = PathHelper::getRelDir($dir2, $dir1);
        return (substr($rel, 0, 3) != '../');
    }

    /**
     *  Return absolute dir.
     *
     *  - Path is canonicalized (without resolving symlinks)
     *  - trailing dash is removed - we don't use that around here.
     *
     *  We do not resolve symlinks anymore. Information was lost that way.
     *  And in some cases we needed the unresolved path - for example in the .htaccess.
     */
    public static function getAbsDir($dir)
    {
        $dir = PathHelper::canonicalize($dir);
        return rtrim($dir, '/');
        /*
        $result = realpath($dir);
        if ($result === false) {
            $dir = PathHelper::canonicalize($dir);
        } else {
            $dir = $result;
        }*/

    }

    // ------------ Home Dir -------------

    // PS: Home dir is not the same as index dir.
    // For example, if Wordpress folder has been moved (method 2), the home dir could be below.
    public static function getHomeDirAbs()
    {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return self::getAbsDir(get_home_path());
    }

    // ------------ Index Dir  (WP root dir) -------------
    // (The Wordpress installation dir- where index.php and wp-load.php resides)

    public static function getIndexDirAbs()
    {
        // We used to return self::getAbsDir(ABSPATH), which used realpath.
        // It has been changed now, as it seems we do not need realpath for ABSPATH, as it is defined
        // (in wp-load.php) as dirname(__FILE__) . "/" and according to this link, __FILE__ returns resolved paths:
        // https://stackoverflow.com/questions/3221771/how-do-you-get-php-symlinks-and-file-to-work-together-nicely
        // AND a user reported an open_basedir restriction problem thrown by realpath($_SERVER['DOCUMENT_ROOT']),
        // due to symlinking and opendir restriction (see #322)

        return rtrim(ABSPATH, '/');

        // TODO: read up on this, regarding realpath:
        // https://github.com/twigphp/Twig/issues/2707

    }

    // ------------ .htaccess dir -------------
    // (directory containing the relevant .htaccess)
    // (see https://github.com/rosell-dk/webp-express/issues/36)



    public static function canWriteHTAccessRulesHere($dirName) {
        return FileHelper::canEditOrCreateFileHere($dirName . '/.htaccess');
    }

    public static function canWriteHTAccessRulesInDir($dirId) {
        return self::canWriteHTAccessRulesHere(self::getAbsDirById($dirId));
    }

    public static function returnFirstWritableHTAccessDir($dirs)
    {
        foreach ($dirs as $dir) {
            if (self::canWriteHTAccessRulesHere($dir)) {
                return $dir;
            }
        }
        return false;
    }

    // ------------ Content Dir (the "WP" content dir) -------------

    public static function getContentDirAbs()
    {
        return self::getAbsDir(WP_CONTENT_DIR);
    }
    public static function getContentDirRel()
    {
        return PathHelper::getRelPathFromDocRootToDirNoDirectoryTraversalAllowed(self::getContentDirAbs());
    }
    public static function getContentDirRelToPluginDir()
    {
        return PathHelper::getRelDir(self::getPluginDirAbs(), self::getContentDirAbs());
    }
    public static function getContentDirRelToMagicConvertPluginDir()
    {
        return PathHelper::getRelDir(self::getMagicConvertPluginDirAbs(), self::getContentDirAbs());
    }


    public static function isWPContentDirMoved()
    {
        return (self::getContentDirAbs() != (ABSPATH . 'wp-content'));
    }

    public static function isWPContentDirMovedOutOfAbsPath()
    {
        return !(self::isDirInsideDir(self::getContentDirAbs(), ABSPATH));
    }

    // ------------ Themes Dir -------------

    public static function getThemesDirAbs()
    {
        return self::getContentDirAbs() . '/themes';
    }

    // ------------ MagicConvert Content Dir -------------
    // (the "magic-convert" directory inside wp-content)

    public static function getMagicConvertContentDirAbs()
    {
        return self::getContentDirAbs() . '/magic-convert';
    }

    public static function getMagicConvertContentDirRel()
    {
        return PathHelper::getRelPathFromDocRootToDirNoDirectoryTraversalAllowed(self::getMagicConvertContentDirAbs());
    }

    public static function createContentDirIfMissing()
    {
        return self::createDirIfMissing(self::getMagicConvertContentDirAbs());
    }

    // ------------ Upload Dir -------------
    public static function getUploadDirAbs()
    {
        $upload_dir = wp_upload_dir(null, false);
        return self::getAbsDir($upload_dir['basedir']);
    }
    public static function getUploadDirRel()
    {
        return PathHelper::getRelPathFromDocRootToDirNoDirectoryTraversalAllowed(self::getUploadDirAbs());
    }

    /*
    public static function getUploadDirAbs()
    {
        if ( defined( 'UPLOADS' ) ) {
            return ABSPATH . rtrim(UPLOADS, '/');
        } else {
            return self::getContentDirAbs() . '/uploads';
        }
    }*/

    public static function isUploadDirMovedOutOfWPContentDir()
    {
        return !(self::isDirInsideDir(self::getUploadDirAbs(), self::getContentDirAbs()));
    }

    public static function isUploadDirMovedOutOfAbsPath()
    {
        return !(self::isDirInsideDir(self::getUploadDirAbs(), ABSPATH));
    }

    // ------------ Config Dir -------------

    public static function getConfigDirAbs()
    {
        return self::getMagicConvertContentDirAbs() . '/config';
    }

    public static function getConfigDirRel()
    {
        return PathHelper::getRelPathFromDocRootToDirNoDirectoryTraversalAllowed(self::getConfigDirAbs());
    }

    /**
     * Get or create a random hash for config filename obfuscation (CVE-2025-11379 fix)
     * This prevents predictable config file access on Nginx servers
     */
    public static function getConfigHash()
    {
        $hash = \MagicConvert\Option::getOption('magic-convert-config-hash', false);
        if (!$hash) {
            // Generate a cryptographically secure random hash
            if (function_exists('random_bytes')) {
                $hash = bin2hex(random_bytes(16));
            } else {
                // Fallback for older PHP versions
                $hash = md5(uniqid(mt_rand(), true) . microtime(true));
            }
            \MagicConvert\Option::updateOption('magic-convert-config-hash', $hash, true);
        }
        return $hash;
    }

    // Only call if certain that config dir exists
    private static function doCreateIndexPHPInConfigDirIfMissing()
    {
        $configDir = self::getConfigDirAbs();
        $indexPHPfilename = rtrim($configDir, '/') . '/index.php';

        if (!@file_exists($indexPHPfilename)) {
          // Additional protection for Nginx: PHP-based access control (CVE-2025-11379 fix)
          @file_put_contents($indexPHPfilename, <<<'PHP'
<?php
// Prevent direct access to config files on Nginx (CVE-2025-11379 fix)
if (!defined('ABSPATH')) {
  http_response_code(403);
  die('Direct access forbidden');
}
PHP
          );
          @chmod($indexPHPfilename, 0644);
        }
    }

    // Only call if certain that config dir exists
    private static function doCreateHTAccessInConfigDirIfMissing()
    {
        $configDir = self::getConfigDirAbs();
        $filename = rtrim($configDir, '/') . '/.htaccess';

        if (!@file_exists($filename)) {
          // Additional protection for Nginx: PHP-based access control (CVE-2025-11379 fix)
          @file_put_contents(rtrim($configDir . '/') . '/.htaccess', <<<APACHE
<IfModule mod_authz_core.c>
Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
Order deny,allow
Deny from all
</IfModule>
APACHE
          );
          @chmod($filename, 0664);
        }
    }

    public static function createIndexPHPInConfigDirIfMissing()
    {
        $configDir = self::getConfigDirAbs();

        if (is_dir($configDir)) {
          self::doCreateIndexPHPInConfigDirIfMissing();
        }
    }

    public static function createConfigDirIfMissing()
    {
        $configDir = self::getConfigDirAbs();
        // Using code from Wordfence bootstrap.php...
        // Why not simply use wp_mkdir_p ? - it sets the permissions to same as parent. Isn't that better?
        // or perhaps not... - Because we need write permissions in the config dir.
        if (!is_dir($configDir)) {
            // Tolerant of the concurrent-creation race (two requests/CLI procs
            // creating the config dir at once): @mkdir then re-check is_dir().
            @mkdir($configDir, 0775, true);
            @chmod($configDir, 0775);
        }
        if (is_dir($configDir)) {
            self::doCreateIndexPHPInConfigDirIfMissing();
            self::doCreateHTAccessInConfigDirIfMissing();
        }
        return is_dir($configDir);
    }

    public static function getConfigFileName()
    {
        // Use randomized filename to prevent predictable access on Nginx (CVE-2025-11379 fix)
        $hash = self::getConfigHash();
        return self::getConfigDirAbs() . '/config.' . $hash . '.json';
    }

    public static function getWodOptionsFileName()
    {
        // Use randomized filename to prevent predictable access on Nginx (CVE-2025-11379 fix)
        $hash = self::getConfigHash();
        return self::getConfigDirAbs() . '/wod-options.' . $hash . '.json';
    }

    /**
     * Get old predictable config filename for migration purposes
     */
    public static function getOldConfigFileName()
    {
        return self::getConfigDirAbs() . '/config.json';
    }

    /**
     * Get old predictable wod-options filename for migration purposes
     */
    public static function getOldWodOptionsFileName()
    {
        return self::getConfigDirAbs() . '/wod-options.json';
    }

    // ------------ Cache Dir -------------

    /**
     * Absolute path of the per-format cache dir.
     *
     * @param  OutputFormat|string|null  $format  Output format (defaults to webp -> 'webp-images').
     */
    public static function getCacheDirAbs($format = null)
    {
        return self::getMagicConvertContentDirAbs() . '/' . OutputFormat::coerce($format)->cacheDirName();
    }

    public static function getCacheDirRelToDocRoot($format = null)
    {
        return PathHelper::getRelPathFromDocRootToDirNoDirectoryTraversalAllowed(self::getCacheDirAbs($format));
    }

    /**
     * @param  OutputFormat|string|null  $format  Output format (defaults to webp).
     */
    public static function getCacheDirForImageRoot($destinationFolder, $destinationStructure, $imageRootId, $format = null)
    {
        if (($destinationFolder == 'mingled') && ($imageRootId == 'uploads')) {
            return self::getUploadDirAbs();
        }

        if ($destinationStructure == 'doc-root') {
            $relPath = PathHelper::getRelPathFromDocRootToDirNoDirectoryTraversalAllowed(
                self::getAbsDirById($imageRootId)
            );
            return self::getCacheDirAbs($format) . '/doc-root/' . $relPath;
        } else {
            return self::getCacheDirAbs($format) . '/' . $imageRootId;
        }
    }

    public static function createCacheDirIfMissing($format = null)
    {
        return self::createDirIfMissing(self::getCacheDirAbs($format));
    }

    // ------------ Log Dir -------------

    public static function getLogDirAbs()
    {
        return self::getMagicConvertContentDirAbs() . '/log';
    }

    // ------------ Bigger-than-source  dir -------------

    /**
     * Absolute path of the per-format "bigger than source" marker dir.
     *
     * MARKER DESIGN (Phase 2.1): markers live in a per-format base dir named
     * '<cacheDirName>-bigger-than-source'. The webp dir stays
     * 'webp-images-bigger-than-source' (byte-for-byte compatible with existing
     * markers — no migration needed); avif gets 'avif-images-bigger-than-source'.
     * Inside the dir, the marker filename already carries the format extension
     * (the path is built with appendOrSetExtension), so a source can have an
     * independent webp marker and avif marker without collision.
     *
     * @param  OutputFormat|string|null  $format  Output format (defaults to webp).
     */
    public static function getBiggerThanSourceDirAbs($format = null)
    {
        return self::getMagicConvertContentDirAbs() . '/' . OutputFormat::coerce($format)->cacheDirName() . '-bigger-than-source';
    }

    // ------------ Plugin Dir (all plugins) -------------

    public static function getPluginDirAbs()
    {
        return self::getAbsDir(WP_PLUGIN_DIR);
    }


    public static function isPluginDirMovedOutOfAbsPath()
    {
        return !(self::isDirInsideDir(self::getPluginDirAbs(), ABSPATH));
    }

    public static function isPluginDirMovedOutOfWpContent()
    {
        return !(self::isDirInsideDir(self::getPluginDirAbs(), self::getContentDirAbs()));
    }

    // ------------ Magic Convert Plugin Dir -------------

    public static function getMagicConvertPluginDirAbs()
    {
        return self::getAbsDir(MAGIC_CONVERT_PLUGIN_DIR);
    }

    // ------------------------------------
    // ---------    Url paths    ----------
    // ------------------------------------

    /**
     *  Get url path (relative to domain) from absolute url.
     *  Ie: "http://example.com/blog" => "blog"
     *  Btw: By "url path" we shall always mean relative to domain
     *       By "url" we shall always mean complete URL (with domain and everything)
     *                                (or at least something that starts with it...)
     *
     *  Also note that in this library, we never returns trailing or leading slashes.
     */
    public static function getUrlPathFromUrl($url)
    {
        $parsed = parse_url($url);
        if (!isset($parsed['path'])) {
            return '';
        }
        if (is_null($parsed['path'])) {
            return '';
        }
        $path = untrailingslashit($parsed['path']);
        return ltrim($path, '/\\');
    }

    public static function getUrlById($dirId) {
        switch ($dirId) {
            case 'wp-content':
                return self::getContentUrl();
            case 'index':
                return self::getHomeUrl();
            case 'home':
                return self::getHomeUrl();
            case 'plugins':
                return self::getPluginsUrl();
            case 'uploads':
                return self::getUploadUrl();
            case 'themes':
                return self::getThemesUrl();
        }
        return false;
    }

    /**
     * Get destination root url and path, provided rootId and some configuration options
     *
     * This method kind of establishes the overall structure of the cache dir.
     * (but not quite, as the logic is also in ConverterHelperIndependent::getDestination).
     *
     * @param  string  $rootId
     * @param  DestinationOptions  $destinationOptions
     *
     * @return array   url and abs-path of destination root
     */
    public static function destinationRoot($rootId, $destinationOptions)
    {
        if (($destinationOptions->mingled) && ($rootId == 'uploads')) {
            return [
                'url' => self::getUrlById('uploads'),
                'abs-path' => self::getUploadDirAbs()
            ];
        } else {

            // Per-format cache dir name (webp -> 'webp-images', avif -> 'avif-images').
            // The format travels on the DestinationOptions (defaults to webp).
            $cacheDirName = OutputFormat::coerce(
                isset($destinationOptions->format) ? $destinationOptions->format : null
            )->cacheDirName();

            // Its within these bases:
            $destUrl = self::getUrlById('wp-content') . '/magic-convert/' . $cacheDirName;
            $destPath = self::getAbsDirById('wp-content') . '/magic-convert/' . $cacheDirName;

            if (($destinationOptions->useDocRoot) && self::canUseDocRootForStructuringCacheDir()) {
                $relPathFromDocRootToSourceImageRoot = PathHelper::getRelPathFromDocRootToDirNoDirectoryTraversalAllowed(
                    self::getAbsDirById($rootId)
                );
                return [
                    'url' => $destUrl . '/doc-root/' . $relPathFromDocRootToSourceImageRoot,
                    'abs-path' => $destPath  . '/doc-root/' . $relPathFromDocRootToSourceImageRoot
                ];
            } else {
                $extraPath = '';
                if (is_multisite() && (get_current_blog_id() != 1)) {
                    $extraPath = '/sites/' . get_current_blog_id();   // #510
                }
                return [
                    'url' => $destUrl . '/' . $rootId . $extraPath,
                    'abs-path' => $destPath  . '/' . $rootId . $extraPath
                ];
            }
        }
    }

    public static function getRootAndRelPathForDestination($destinationPath, $imageRoots) {
        foreach ($imageRoots->getArray() as $i => $imageRoot) {
            $rootPath = $imageRoot->getAbsPath();
            if (strpos($destinationPath, realpath($rootPath)) !== false) {
                $relPath = substr($destinationPath, strlen(realpath($rootPath)) + 1);
                return [$imageRoot->id, $relPath];
            }
        }
        return ['', ''];
    }



    // PST:
    // appendOrSetExtension() have been copied from ConvertHelperIndependent.
    // TODO: I should complete the move ASAP.

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
     * Get destination root url and path, provided rootId and some configuration options
     *
     * This method kind of establishes the overall structure of the cache dir.
     * (but not quite, as the logic is also in ConverterHelperIndependent::getDestination).
     *
     * @param  string  $rootId
     * @param  string  $relPath
     * @param  string  $destinationFolder     ("mingled" or "separate")
     * @param  string  $destinationExt        ('append' or 'set')
     * @param  string  $destinationStructure  ("doc-root" or "image-roots")
     *
     * @return array   url and abs-path of destination
     */
   /*
    public static function destinationPath($rootId, $relPath, $destinationFolder, $destinationExt, $destinationStructure) {

        // TODO: Current logic will not do!
        // We must use ConvertHelper::getDestination for the abs path.
        // And we must use logic from AlterHtmlHelper to get the URL
        // Perhaps this method must be abandonned

        $root = self::destinationRoot($rootId, $destinationFolder, $destinationStructure);
        $inUploadFolder = ($rootId == 'upload');
        $relPath = ConvertHelperIndependent::appendOrSetExtension($relPath, $destinationFolder, $destinationExt, $inUploadFolder);

        return [
            'abs-path' => $root['abs-path'] . '/' . $relPath,
            'url' => $root['url'] . '/' . $relPath,
        ];
    }

    public static function destinationPathConvenience($rootId, $relPath, $config) {
        return self::destinationPath(
            $rootId,
            $relPath,
            $config['destination-folder'],
            $config['destination-extension'],
            $config['destination-structure']
        );
    }*/

    public static function getDestinationPathCorrespondingToSource($source, $destinationOptions) {
        return Destination::getDestinationPathCorrespondingToSource(
            $source,
            Paths::getMagicConvertContentDirAbs(),
            Paths::getUploadDirAbs(),
            $destinationOptions,
            new ImageRoots(self::getImageRootsDef())
        );
    }

    public static function getUrlPathById($dirId) {
        return self::getUrlPathFromUrl(self::getUrlById($dirId));
    }

    public static function getHostNameOfUrl($url) {
        $urlComponents = parse_url($url);
        /* ie:
        (
            [scheme] => http
            [host] => we0
            [path] => /wordpress/uploads-moved
        )*/

        if (!isset($urlComponents['host'])) {
            return '';
        } else {
            return $urlComponents['host'];
        }
    }

    // Get complete home url (no trailing slash). Ie: "http://example.com/blog"
    public static function getHomeUrl()
    {
        if (!function_exists('home_url')) {
            // silence is golden?
            // bad joke. Need to handle this...
        }
        return untrailingslashit(home_url());
    }

    /** Get home url, relative to domain. Ie "" or "blog"
     *  If home url is for example http://example.com/blog/, the result is "blog"
     */
    public static function getHomeUrlPath()
    {
        return self::getUrlPathFromUrl(self::getHomeUrl());
    }


    public static function getUploadUrl()
    {
        $uploadDir = wp_upload_dir(null, false);
        return untrailingslashit($uploadDir['baseurl']);
    }

    public static function getUploadUrlPath()
    {
        return self::getUrlPathFromUrl(self::getUploadUrl());
    }

    public static function getContentUrl()
    {
        return untrailingslashit(content_url());
    }

    public static function getContentUrlPath()
    {
        return self::getUrlPathFromUrl(self::getContentUrl());
    }

    public static function getThemesUrl()
    {
        return self::getContentUrl() . '/themes';
    }

    /**
     *  Get Url to plugins (base)
     */
    public static function getPluginsUrl()
    {
        return untrailingslashit(plugins_url());
    }

    /**
     *  Get Url to Magic Convert plugin (this is in fact an incomplete URL, you need to append ie '/webp-on-demand.php' to get a full URL)
     */
    public static function getMagicConvertPluginUrl()
    {
        return untrailingslashit(plugins_url('', MAGIC_CONVERT_PLUGIN));
    }

    public static function getMagicConvertPluginUrlPath()
    {
        return self::getUrlPathFromUrl(self::getMagicConvertPluginUrl());
    }

    public static function getWodFolderUrlPath()
    {
        return
            self::getMagicConvertPluginUrlPath() .
            '/wod';
    }

    public static function getWod2FolderUrlPath()
    {
        return
            self::getMagicConvertPluginUrlPath() .
            '/wod2';
    }

    public static function getWodUrlPath()
    {
        return
            self::getWodFolderUrlPath() .
            '/webp-on-demand.php';
    }

    public static function getWod2UrlPath()
    {
        return
            self::getWod2FolderUrlPath() .
            '/webp-on-demand.php';
    }

    public static function getWebPRealizerUrlPath()
    {
        return
            self::getWodFolderUrlPath() .
            '/webp-realizer.php';
    }

    public static function getWebPRealizer2UrlPath()
    {
        return
            self::getWod2FolderUrlPath()  .
            '/webp-realizer.php';
    }

    public static function getWebServiceUrl()
    {
        //return self::getMagicConvertPluginUrl() . '/wpc.php';
        //return self::getHomeUrl() . '/magic-convert-server';
        return self::getHomeUrl() . '/magic-convert-web-service';
    }

    public static function getUrlsAndPathsForTheJavascript()
    {
        return [
            'urls' => [
                'magicConvertRoot' => self::getMagicConvertPluginUrlPath(),
                'content' => self::getContentUrlPath(),
            ],
            'filePaths' => [
                'magicConvertRoot' => self::getMagicConvertPluginDirAbs(),
                'destinationRoot' => self::getCacheDirAbs(),
            ]
        ];
    }

    public static function getSettingsUrl()
    {
        if (!function_exists('admin_url')) {
            require_once ABSPATH . 'wp-includes/link-template.php';
        }
        if (Multisite::isNetworkActivated()) {
            // network_admin_url is also defined in link-template.php.
            return network_admin_url('settings.php?page=magic_convert_settings_page');
        } else {
            return admin_url('options-general.php?page=magic_convert_settings_page');
        }
    }

}
