<?php

namespace MagicConvert;

if (PHP_SAPI !== 'cli') {
    if (function_exists('http_response_code')) {
        http_response_code(403);
    }
    exit(1);
}

define('MAGIC_CONVERT_PLUGIN_DIR', dirname(__DIR__));
require __DIR__ . '/autoloader.php';

Avif\AvifStack::ensureVendorAutoloader();

$argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : [];
if (count($argv) < 5) {
    fwrite(STDERR, "usage: avif-encode-worker.php <source> <destination> <converterId> <optionsBase64>\n");
    exit(1);
}

$source        = $argv[1];
$destination   = $argv[2];
$converterId   = $argv[3];
$optionsBase64 = $argv[4];

$optionsJson = base64_decode($optionsBase64, true);
$options = ($optionsJson === false) ? null : json_decode($optionsJson, true);
if (!is_array($options)) {
    $options = [];
}

$converter = Avif\AvifStack::makeById($converterId);
if ($converter === null) {
    fwrite(STDERR, 'unknown converter id: ' . $converterId . "\n");
    exit(2);
}

$op = $converter->isOperational();
if (empty($op['operational'])) {
    fwrite(STDERR, 'not-operational: ' . (isset($op['reason']) ? $op['reason'] : '') . "\n");
    exit(3);
}

try {
    $converter->convert($source, $destination, $options);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

exit(0);
