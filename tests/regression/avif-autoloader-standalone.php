<?php
/**
 * Standalone regression test for the AVIF "Class ExecWithFallback not found" fatal.
 *
 * This is NOT a PHPUnit test on purpose: the PHPUnit bootstrap always pulls in
 * vendor/autoload.php, which would mask the bug. The plugin in production does the
 * opposite — magic-convert.php registers ONLY the MagicConvert\ namespace autoloader
 * and loads Composer's vendor autoloader lazily, per code path. The AVIF self-test
 * path used to reach the exec converters without anyone having loaded vendor/autoload,
 * so ExecWithFallback\ExecWithFallback was unresolvable and isOperational() fataled.
 *
 * This script reproduces that exact production environment:
 *   - define MAGIC_CONVERT_PLUGIN_DIR (as magic-convert.php does),
 *   - register ONLY the MagicConvert\ autoloader (NO vendor/autoload),
 *   - assert the vendor class is NOT yet loadable (the bug's precondition),
 *   - build AvifStack and run selfTest()/isOperational() — the path that fataled,
 *   - assert it ran without fataling AND that AvifStack registered the vendor autoloader.
 *
 * Run:  php tests/regression/avif-autoloader-standalone.php   (exit 0 = pass)
 * Without the AvifStack::ensureVendorAutoloader() fix, this exits non-zero / fatals.
 */

$root = dirname(__DIR__, 2);

// Mirror magic-convert.php exactly: define the dir constant the lazy loader keys on...
define('MAGIC_CONVERT_PLUGIN_DIR', $root);

// ...and register ONLY the plugin's own namespace autoloader (NOT vendor/autoload).
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'MagicConvert\\';
    if (strpos($class, $prefix) === 0) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $root . '/lib/classes/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

$failures = [];
$check = function ($cond, $label) use (&$failures) {
    if ($cond) {
        fwrite(STDOUT, "  PASS: $label\n");
    } else {
        $failures[] = $label;
        fwrite(STDOUT, "  FAIL: $label\n");
    }
};

fwrite(STDOUT, "AVIF autoloader regression\n");

// Precondition: the vendor exec helper must NOT be resolvable yet — only the
// MagicConvert\ autoloader is registered, and it cannot load this namespace. With
// autoload enabled this exercises every registered loader; all fail => false.
$check(
    !class_exists('\ExecWithFallback\ExecWithFallback'),
    'vendor exec helper is NOT resolvable before AvifStack (reproduces the production precondition)'
);

// The path that used to fatal: build the default AVIF stack and exercise it.
$threw = null;
$rows = [];
try {
    $stack = new \MagicConvert\Avif\AvifStack();
    $rows = $stack->selfTest();
    // isOperational() iterates every converter incl. the exec ones — the exact call
    // chain (AbstractAvifExecConverter::isOperational -> ExecWithFallback::anyAvailable)
    // that produced "Class ExecWithFallback\ExecWithFallback not found".
    $stack->isOperational();
} catch (\Throwable $e) {
    $threw = $e;
}

$check($threw === null, 'AvifStack selfTest()/isOperational() did NOT fatal'
    . ($threw ? ' (got: ' . get_class($threw) . ': ' . $threw->getMessage() . ')' : ''));

$check(count($rows) === 6, 'selfTest() returned all 6 converter rows (got ' . count($rows) . ')');

// The fix: constructing AvifStack must have registered Composer's vendor autoloader,
// so the exec helper classes are now resolvable.
$check(
    class_exists('\ExecWithFallback\ExecWithFallback'),
    'AvifStack registered the vendor autoloader (ExecWithFallback now loadable)'
);
$check(
    class_exists('\LocateBinaries\LocateBinaries'),
    'AvifStack registered the vendor autoloader (LocateBinaries now loadable)'
);

if ($failures) {
    fwrite(STDERR, "\nREGRESSION FAILED (" . count($failures) . "):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "\nOK: AVIF autoloader regression passed.\n");
exit(0);
