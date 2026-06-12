<?php

namespace MagicConvert;

use \MagicConvert\Config;
use \MagicConvert\Messenger;
use \MagicConvert\Option;
use \MagicConvert\Paths;

function magicconvert_migrate16() {

    // Update migrate version right away to minimize risk of running the update twice in a multithreaded environment
    Option::updateOption('magic-convert-migration-version', '17'); // Skip the next migration! Originally, this was set to '16'. Users no longer need the next update (migrate17), as &hash is no longer required.

    $configMigrateSuccess = Config::checkAndMigrateConfigIfNeeded();
    if ($configMigrateSuccess) {
        $config = Config::loadConfigAndFix(false);    // false means we do not need the check if quality detection is supported
        if (($config['enable-redirection-to-webp-realizer']) || ($config['enable-redirection-to-converter'])) {

            // We need to regenerate .htaccess files if web-realizer or webp-on-demand is active,
            // so they get the new ConfigHash
            wp_schedule_single_event(time() + 1, 'magic_convert_task_regenerate_config_and_htaccess');
            DismissableGlobalMessages::addDismissableMessage('0.25.11/updated-htaccess');

        }
    }
}

magicconvert_migrate16();
