<?php

namespace MagicConvert;

use \MagicConvert\Config;
use \MagicConvert\Messenger;
use \MagicConvert\Option;

function magicconvert_migrate12() {

    $config = Config::loadConfigAndFix(false);  // false, because we do not need to test if quality detection is working

/*
    if (($config['destination-extension'] == 'set') && ($config['destination-folder'] == 'mingled')) {
        DismissableMessages::addDismissableMessage('0.15.1/problems-with-mingled-set');

        Messenger::addMessage(
            'error',
            'Magic Convert is experiencing technical problems with your particular setup. ' .
                'Please <a href="' . Paths::getSettingsUrl() . '">go to the settings page</a> to fix.'
        );

    }*/

    $forceHtaccessRegeneration = true;
    $result = Config::saveConfigurationAndHTAccess($config, $forceHtaccessRegeneration);

    if ($result['saved-both-config']) {
        Option::updateOption('magic-convert-migration-version', '12');

    } else {
        Messenger::addMessage(
            'error',
            'Failed migrating Magic Convert options to 0.15.1. Probably you need to grant write permissions in your wp-content folder.'
        );
    }

}

magicconvert_migrate12();
