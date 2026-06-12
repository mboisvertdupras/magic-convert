<?php
/**
 * PHPUnit bootstrap for Magic Convert's WordPress-independent unit tests.
 *
 * These tests intentionally do NOT load WordPress, the WP test suite, or a
 * database. They exercise pure-PHP logic in classes that are designed to run
 * standalone (PathHelper, SanityCheck, the "Independent" conversion helpers).
 *
 * Two things are wired up here:
 *
 *  1. An autoloader for the `MagicConvert\` namespace that mirrors the
 *     spl_autoload_register() logic in magic-convert.php, pointing at
 *     lib/classes/.
 *  2. Composer's vendor/autoload.php, so the webp-convert library classes
 *     (WebPConvert\...) used by ConvertHelperIndependent are resolvable.
 */

define('MAGIC_CONVERT_TESTS_ROOT', dirname(__DIR__));

// --- Plugin namespace autoloader (mirrors magic-convert.php) -------------------
spl_autoload_register(function ($class) {
    $prefix = 'MagicConvert\\';
    if (strpos($class, $prefix) === 0) {
        // Convert sub-namespace separators to directory separators so
        // MagicConvert\Avif\AvifStack -> lib/classes/Avif/AvifStack.php (matches
        // the production autoloader in magic-convert.php).
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = MAGIC_CONVERT_TESTS_ROOT . '/lib/classes/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// --- Composer autoloader (webp-convert et al.) ---------------------------------
$composerAutoload = MAGIC_CONVERT_TESTS_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
