<?php

define('MAGIC_CONVERT_TESTS_ROOT', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = 'MagicConvert\\';
    if (strpos($class, $prefix) === 0) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = MAGIC_CONVERT_TESTS_ROOT . '/lib/classes/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

$composerAutoload = MAGIC_CONVERT_TESTS_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
