<?php

namespace MagicConvert\RegressionTest;

// Standalone regression for the AVIF subprocess-isolation path. Run directly:
//   php tests/regression/avif-subprocess-isolation.php
// It is deliberately NOT part of phpunit: phpunit loads classes via its own bootstrap
// autoloader and converts in-process, which would mask both the real child-process spawn
// and the wod/autoloader.php sub-namespace resolution this guards.

$pluginDir = dirname(__DIR__, 2);
define('MAGIC_CONVERT_PLUGIN_DIR', $pluginDir);
require $pluginDir . '/vendor/autoload.php';
foreach (['AbstractAvifConverter', 'AbstractAvifExecConverter', 'AvifEncBinary', 'MagickBinaryAvif',
          'CavifBinary', 'GdAvif', 'ImagickAvif', 'VipsAvif', 'AvifStack', 'AvifStackException',
          'AvifSubprocessRunner'] as $c) {
    require_once $pluginDir . "/lib/classes/Avif/$c.php";
}
require_once $pluginDir . '/lib/classes/PhpCliLocator.php';
require_once $pluginDir . '/lib/classes/ConcurrencyAdvisor.php';

use MagicConvert\Avif\AvifStack;
use MagicConvert\Avif\GdAvif;
use MagicConvert\PhpCliLocator;

function regress_skip($m)
{
    fwrite(STDOUT, "SKIP: $m\n");
    exit(0);
}
function regress_fail($m)
{
    fwrite(STDERR, "FAIL: $m\n");
    exit(1);
}
function regress_pass($m)
{
    fwrite(STDOUT, "PASS: $m\n");
    exit(0);
}

if (!function_exists('proc_open')) {
    regress_skip('proc_open is unavailable on this host');
}
if (PhpCliLocator::locate() === null) {
    regress_skip('no PHP CLI binary could be located');
}
if (!function_exists('imagecreatetruecolor')) {
    regress_skip('GD is not loaded');
}

$gd = new GdAvif();
$op = $gd->isOperational();
if (empty($op['operational'])) {
    regress_skip('GD AVIF encoder is not operational: ' . (isset($op['reason']) ? $op['reason'] : ''));
}

$tmp = sys_get_temp_dir() . '/mc-regress-' . getmypid();
@mkdir($tmp);
$src = $tmp . '/src.jpg';
$dst = $tmp . '/out.avif';

$im = imagecreatetruecolor(800, 600);
imagefilledrectangle($im, 0, 0, 800, 600, imagecolorallocate($im, 120, 60, 200));
imagejpeg($im, $src, 90);
imagedestroy($im);

$stack = new AvifStack([$gd]);
$mode = $stack->memorySafetyMode();
if ($mode !== 'isolated') {
    regress_fail("expected memorySafetyMode 'isolated' for an in-process encoder with isolation available, got '$mode'");
}

@unlink($dst);
try {
    $result = $stack->convert($src, $dst, ['quality' => 40, 'speed' => 8, 'metadata' => 'none']);
} catch (\Throwable $e) {
    regress_fail('isolated encode threw: ' . $e->getMessage());
}

if (($result['converter'] ?? '') !== 'gd') {
    regress_fail("expected converter 'gd', got '" . ($result['converter'] ?? '') . "'");
}
if (!is_file($dst) || filesize($dst) === 0) {
    regress_fail('isolated encode produced no AVIF output');
}
$size = filesize($dst);
$head = (string) file_get_contents($dst, false, null, 0, 32);
if (strpos($head, 'ftyp') === false) {
    regress_fail('output is not a valid AVIF container (no ftyp box)');
}

@unlink($src);
@unlink($dst);
@rmdir($tmp);

regress_pass('in-process GD encode ran in an isolated child process and produced a valid ' . $size . '-byte AVIF');
