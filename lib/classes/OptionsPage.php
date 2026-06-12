<?php

namespace MagicConvert;

/**
 *
 */

class OptionsPage
{

    // callback (registred in AdminUi)
    public static function display() {
        include MAGIC_CONVERT_PLUGIN_DIR . '/lib/options/page.php';
    }

    public static function enqueueScripts() {
        include MAGIC_CONVERT_PLUGIN_DIR . '/lib/options/enqueue_scripts.php';
    }
}
