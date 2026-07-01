<?php

namespace MagicConvert\RegressionTest;

// Standalone regression for VipsAvif::isOperational(). Run directly:
//   php tests/regression/avif-vips-isoperational-standalone.php
// A prior version probed heifsave support with vips_call('heifsave', null); on a libvips
// build that HAS heifsave, libvips dereferenced the null image and SEGFAULTED, taking the
// whole PHP worker down (the settings page returned a 502). A segfault aborts the phpunit
// runner, so this guard lives outside phpunit and asserts via its own process exit code.

$pluginDir = dirname(__DIR__, 2);
define('MAGIC_CONVERT_PLUGIN_DIR', $pluginDir);
require $pluginDir . '/vendor/autoload.php';
foreach (['AbstractAvifConverter', 'VipsAvif'] as $c) {
    require_once $pluginDir . "/lib/classes/Avif/$c.php";
}

use MagicConvert\Avif\VipsAvif;

function regress_skip($m) { fwrite(STDOUT, "SKIP: $m\n"); exit(0); }
function regress_fail($m) { fwrite(STDERR, "FAIL: $m\n"); exit(1); }
function regress_pass($m) { fwrite(STDOUT, "PASS: $m\n"); exit(0); }

if (!extension_loaded('vips')) {
    regress_skip('libvips (vips) extension is not loaded');
}

$op = (new VipsAvif())->isOperational();

if (!is_array($op) || !array_key_exists('operational', $op) || !array_key_exists('reason', $op)) {
    regress_fail('isOperational() did not return a well-formed {operational, reason} array');
}
if ($op['operational'] === false && trim($op['reason']) === '') {
    regress_fail('isOperational() reported not-operational without a reason');
}

regress_pass('VipsAvif::isOperational() returned without crashing (operational=' . var_export($op['operational'], true) . ')');
