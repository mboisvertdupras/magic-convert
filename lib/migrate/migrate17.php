<?php

namespace MagicConvert;

use \MagicConvert\Config;
use \MagicConvert\Messenger;
use \MagicConvert\Option;
use \MagicConvert\Paths;

function magicconvert_migrate17() {

    // Update migrate version right away to minimize risk of running the update twice in a multithreaded environment
    Option::updateOption('magic-convert-migration-version', '17');

    if (PlatformInfo::isNginx()) {
        $configMigrateSuccess = Config::checkAndMigrateConfigIfNeeded();
        if ($configMigrateSuccess) {
            $config = Config::loadConfigAndFix(false);    // false means we do not need the check if quality detection is supported
            if (($config['enable-redirection-to-webp-realizer']) || ($config['enable-redirection-to-converter'])) {
                DismissableGlobalMessages::addDismissableMessage('0.25.12/nginx-rewrites-needs-updating');
            }
        }
    }
}

magicconvert_migrate17();
