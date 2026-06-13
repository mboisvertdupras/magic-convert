<?php

namespace MagicConvert;

use \MagicConvert\Multisite;

/**
 *
 */

class AdminUi
{

    // Add settings link on the plugins page
    // The hook was registred in AdminInit
    public static function pluginActionLinksFilter($links)
    {
        if (Multisite::isNetworkActivated()) {
            $mylinks= [
                '<a href="https://github.com/mboisvertdupras/magic-convert" target="_blank">GitHub</a>',
            ];
        } else {
            $mylinks = array(
                '<a href="' . admin_url('options-general.php?page=magic_convert_settings_page') . '">Settings</a>',
                '<a href="https://github.com/mboisvertdupras/magic-convert" target="_blank">GitHub</a>',
            );

        }
        return array_merge($links, $mylinks);
    }

    // Add settings link in multisite
    // The hook was registred in AdminInit
    public static function networkPluginActionLinksFilter($links)
    {
        $mylinks = array(
            '<a href="' . network_admin_url('settings.php?page=magic_convert_settings_page') . '">Settings</a>',
            '<a href="https://github.com/mboisvertdupras/magic-convert" target="_blank">GitHub</a>',
        );
        return array_merge($links, $mylinks);
    }


    // callback for 'network_admin_menu' (registred in AdminInit)
    public static function networAdminMenuHook()
    {
        add_submenu_page(
            'settings.php', // Parent element
            'Magic Convert settings (for network)', // Text in browser title bar
            'Magic Convert', // Text to be displayed in the menu.
            'manage_network_options', // Capability
            'magic_convert_settings_page', // slug
            array('\MagicConvert\OptionsPage', 'display') // Callback function which displays the page
        );

        add_submenu_page(
            'settings.php', // Parent element
            'Magic Convert File Manager', //Page Title
            'Magic Convert File Manager', //Menu Title
            'manage_network_options', //capability
            'magic_convert_conversion_page', // slug
            array('\MagicConvert\WCFMPage', 'display') //The function to be called to output the content for this page.
        );

    }

    public static function adminMenuHookMultisite()
    {
        // Add Media page
        /*
        not ready - it should not display images for the other blogs!

        add_submenu_page(
          'upload.php', // Parent element
          'Magic Convert', //Page Title
          'Magic Convert', //Menu Title
          'manage_network_options', //capability
          'magic_convert_conversion_page', // slug
          array('\MagicConvert\WCFMPage', 'display') //The function to be called to output the content for this page.
        );
        */

    }

    public static function adminMenuHook()
    {
        //Add Settings Page
        add_options_page(
            'Magic Convert Settings', //Page Title
            'Magic Convert', //Menu Title
            'manage_options', //capability
            'magic_convert_settings_page', // slug
            array('\MagicConvert\OptionsPage', 'display') //The function to be called to output the content for this page.
        );

        // Add Media page
        add_media_page(
          'Magic Convert', //Page Title
          'Magic Convert', //Menu Title
          'manage_options', //capability
          'magic_convert_conversion_page', // slug
          array('\MagicConvert\WCFMPage', 'display') //The function to be called to output the content for this page.
        );

    }
}
