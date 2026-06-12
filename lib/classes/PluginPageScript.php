<?php

namespace MagicConvert;

class PluginPageScript
{
    // The hook was registred in AdminInit
    public static function enqueueScripts() {
        $ver = '1';             // note: Minimum 1
        $jsDir = 'js/0.16.0';   // We change dir when it is critical that no-one gets the cached version (there is a plugin that strips version strings out there...)

        if (!function_exists('magic_convert_add_inline_script')) {
            function magic_convert_add_inline_script($id, $script, $position) {
                if (function_exists('wp_add_inline_script')) {
                    // wp_add_inline_script is available from Wordpress 4.5
                    wp_add_inline_script($id, $script, $position);
                } else {
                    echo '<script>' . $script . '</script>';
                }
            }
        }

        wp_register_script('magicconvert-plugin-page', plugins_url($jsDir . '/plugin-page.js', dirname(dirname(__FILE__))), [], '1.9.0');
        wp_enqueue_script('magicconvert-plugin-page');
    }
}
