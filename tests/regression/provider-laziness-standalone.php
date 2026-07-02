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

fwrite(STDOUT, "FormatProvider laziness regression\n");

$check(
    !class_exists('WebPConvert\\WebPConvert', false),
    'vendor WebPConvert is NOT loaded before providers (reproduces the production precondition)'
);

$threw = null;
$providers = [];
try {
    $providers = \MagicConvert\Format\ProviderRegistry::all();
    foreach ($providers as $provider) {
        $provider->id();
        $provider->converterIds();
        $provider->optionDefaults();
        $provider->memoryReserveBytes();
        $provider->concurrencyWeight();
    }
} catch (\Throwable $e) {
    $threw = $e;
}

$check($threw === null, 'constructing providers and calling fact methods did NOT fatal'
    . ($threw ? ' (got: ' . get_class($threw) . ': ' . $threw->getMessage() . ')' : ''));

$check(count($providers) === 2, 'registry exposed both providers (got ' . count($providers) . ')');

$check(
    !class_exists('WebPConvert\\WebPConvert', false),
    'fact methods did NOT pull in the vendor autoloader (WebPConvert still not loaded)'
);
$check(
    !class_exists('ExecWithFallback\\ExecWithFallback', false),
    'fact methods did NOT pull in the vendor autoloader (ExecWithFallback still not loaded)'
);

$threwNormalize = null;
try {
    $fixture = [
        'webp-convert' => ['convert' => ['metadata' => 'all']],
        'formats' => [
            'webp' => ['enabled' => true],
            'avif' => ['enabled' => true, 'quality' => 40, 'speed' => 5],
        ],
    ];
    foreach ($providers as $provider) {
        $out = $provider->normalizeOptions($fixture);
        if (!is_array($out)) {
            throw new \RuntimeException('normalizeOptions did not return an array for ' . $provider->id());
        }
    }
} catch (\Throwable $e) {
    $threwNormalize = $e;
}

$check($threwNormalize === null, 'normalizeOptions (fact method) did NOT fatal'
    . ($threwNormalize ? ' (got: ' . get_class($threwNormalize) . ': ' . $threwNormalize->getMessage() . ')' : ''));

$check(
    !class_exists('WebPConvert\\WebPConvert', false),
    'normalizeOptions did NOT pull in the vendor autoloader (WebPConvert still not loaded)'
);

if ($failures) {
    fwrite(STDERR, "\nREGRESSION FAILED (" . count($failures) . "):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "\nOK: FormatProvider laziness regression passed.\n");
exit(0);
