<?php
define('WOD_DIR', __DIR__);

// setLocale - for converting files with non ascii characters (#406)
setlocale(LC_CTYPE, "C.UTF-8");

function magic_convert_autoloader($class) {
    $prefix = 'MagicConvert\\';
    if (strpos($class, $prefix) === 0) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        require_once WOD_DIR . '/../lib/classes/' . $relative . '.php';
    }
}
spl_autoload_register('magic_convert_autoloader');
