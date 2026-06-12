<?php

namespace MagicConvert;

/**
 *
 */

class AdminInit
{
    public static function init() {

        // uncomment next line to debug an error during activation
        //include __DIR__ . "/../debug.php";

        if (Option::getOption('magic-convert-actions-pending')) {
            \MagicConvert\Actions::processQueuedActions();
        }

        self::addHooks();


    }

    public static function runMigrationIfNeeded()
    {
        // When an update requires a migration, the number should be increased
        define('MAGIC_CONVERT_MIGRATION_VERSION', '17');

        if (MAGIC_CONVERT_MIGRATION_VERSION != Option::getOption('magic-convert-migration-version', 0)) {
            // run migration logic
            include MAGIC_CONVERT_PLUGIN_DIR . '/lib/migrate/migrate.php';
        }

        // uncomment next line to test-run a migration
        // include MAGIC_CONVERT_PLUGIN_DIR . '/lib/migrate/migrate15.php';
    }

    public static function pageNowIs($pageId)
    {
        global $pagenow;

        if ((!isset($pagenow)) || (empty($pagenow))) {
            return false;
        }
        return ($pageId == $pagenow);
    }


    public static function addHooksAfterAdminInit()
    {

        if (current_user_can('manage_options')) {

            // Hooks related to conversion page (in media)
            //if (self::pageNowIs('upload.php')) {
                if (isset($_GET['page']) && ('magic_convert_conversion_page' === $_GET['page'])) {
                    //add_action('admin_enqueue_scripts', array('\MagicConvert\WCFMPage', 'enqueueScripts'));
                    add_action('admin_head', array('\MagicConvert\WCFMPage', 'addToHead'));
                }
            //}

            // Hooks related to options page
            if (self::pageNowIs('options-general.php') || self::pageNowIs('settings.php')) {
                if (isset($_GET['page']) && ('magic_convert_settings_page' === $_GET['page'])) {
                    add_action('admin_enqueue_scripts', array('\MagicConvert\OptionsPage', 'enqueueScripts'));
                }
            }

            // Hooks related to plugins page
            if (self::pageNowIs('plugins.php')) {
                add_action('admin_enqueue_scripts', array('\MagicConvert\PluginPageScript', 'enqueueScripts'));
            }

            add_action("admin_post_magicconvert_settings_submit", array('\MagicConvert\OptionsPageHooks', 'submitHandler'));


            // Ajax actions
            add_action('wp_ajax_list_unconverted_files', array('\MagicConvert\BulkConvert', 'processAjaxListUnconvertedFiles'));
            add_action('wp_ajax_convert_file', array('\MagicConvert\Convert', 'processAjaxConvertFile'));
            add_action('wp_ajax_magicconvert_view_log', array('\MagicConvert\ConvertLog', 'processAjaxViewLog'));
            add_action('wp_ajax_magicconvert_purge_cache', array('\MagicConvert\CachePurge', 'processAjaxPurgeCache'));
            add_action('wp_ajax_magicconvert_purge_log', array('\MagicConvert\LogPurge', 'processAjaxPurgeLog'));
            add_action('wp_ajax_magicconvert_dismiss_message', array('\MagicConvert\DismissableMessages', 'processAjaxDismissMessage'));
            add_action('wp_ajax_magicconvert_dismiss_global_message', array('\MagicConvert\DismissableGlobalMessages', 'processAjaxDismissGlobalMessage'));
            add_action('wp_ajax_magicconvert_self_test', array('\MagicConvert\SelfTest', 'processAjax'));
            add_action('wp_ajax_magicconvert-wcfm-api', array('\MagicConvert\WCFMApi', 'processRequest'));


            // Add settings link on the plugins list page
            add_filter('plugin_action_links_' . plugin_basename(MAGIC_CONVERT_PLUGIN), array('\MagicConvert\AdminUi', 'pluginActionLinksFilter'), 10, 2);

            // Add settings link in multisite
            add_filter('network_admin_plugin_action_links_' . plugin_basename(MAGIC_CONVERT_PLUGIN), array('\MagicConvert\AdminUi', 'networkPluginActionLinksFilter'), 10, 2);
        }

    }

    public static function addHooks()
    {

        // Plugin activation, deactivation and uninstall
        register_activation_hook(MAGIC_CONVERT_PLUGIN, array('\MagicConvert\PluginActivate', 'activate'));
        register_deactivation_hook(MAGIC_CONVERT_PLUGIN, array('\MagicConvert\PluginDeactivate', 'deactivate'));
        register_uninstall_hook(MAGIC_CONVERT_PLUGIN, array('\MagicConvert\PluginUninstall', 'uninstall'));

                /*$start = microtime(true);
                BiggerThanSourceDummyFilesBulk::updateStatus(Config::loadConfig());
                echo microtime(true) - $start;*/


        // Some hooks must be registered AFTER admin_init...
        add_action("admin_init", array('\MagicConvert\AdminInit', 'addHooksAfterAdminInit'));

        // Run migration AFTER admin_init hook (important, as insert_with_markers injection otherwise fails, see #394)
        // PS: "plugins_loaded" is to early, as insert_with_markers fails.
        // PS: Unfortunately Message::addMessage doesnt print until next load now, we should look into that.
        // PPS: It does run. It must be the Option that does not react
        //add_action("admin_init", array('\MagicConvert\AdminInit', 'runMigrationIfNeeded'));

        add_action("admin_init", array('\MagicConvert\AdminInit', 'runMigrationIfNeeded'));

        add_action("admin_notices", array('\MagicConvert\DismissableGlobalMessages', 'printMessages'));

        if (Multisite::isNetworkActivated()) {
            if (is_network_admin()) {
                add_action("network_admin_menu", array('\MagicConvert\AdminUi', 'networAdminMenuHook'));
            } else {
                add_action("admin_menu", array('\MagicConvert\AdminUi', 'adminMenuHookMultisite'));
            }

        } else {
            add_action("admin_menu", array('\MagicConvert\AdminUi', 'adminMenuHook'));
        }

        // Print pending messages, if any
        if (Option::getOption('magic-convert-messages-pending')) {
            add_action(Multisite::isNetworkActivated() ? 'network_admin_notices' : 'admin_notices', array('\MagicConvert\Messenger', 'printPendingMessages'));
        }


        // PS:
        // Filters for processing upload hooks in order to convert images upon upload (wp_handle_upload / image_make_intermediate_size)
        // are located in magic-convert.php

    }
}
