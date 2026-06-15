<?php

$root = dirname(__DIR__, 2);

define('MAGIC_CONVERT_PLUGIN_DIR', $root);

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

$check(
    !class_exists('\ExecWithFallback\ExecWithFallback'),
    'vendor exec helper is NOT resolvable before AvifStack (reproduces the production precondition)'
);

$threw = null;
$rows = [];
try {
    $stack = new \MagicConvert\Avif\AvifStack();
    $rows = $stack->selfTest();
    $stack->isOperational();
} catch (\Throwable $e) {
    $threw = $e;
}

$check($threw === null, 'AvifStack selfTest()/isOperational() did NOT fatal'
    . ($threw ? ' (got: ' . get_class($threw) . ': ' . $threw->getMessage() . ')' : ''));

$check(count($rows) === 6, 'selfTest() returned all 6 converter rows (got ' . count($rows) . ')');

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
