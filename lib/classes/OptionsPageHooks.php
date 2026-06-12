<?php

namespace MagicConvert;

/**
 *
 */

class OptionsPageHooks
{

    // callback for 'admin_post_magicconvert_settings_submit' (registred in AdminInit::addHooks)
    public static function submitHandler() {
        include MAGIC_CONVERT_PLUGIN_DIR . '/lib/options/submit.php';
    }
}
