<?php

namespace MagicConvert;

use \MagicConvert\FileHelper;
use \MagicConvert\Option;
use \MagicConvert\Paths;

/**
 *
 */

class PluginUninstall
{
    // The hook was registred in AdminInit
    public static function uninstall() {

        $optionsToDelete = [
            'magic-convert-messages-pending',
            'magic-convert-action-pending',
            'magic-convert-state',
            'magic-convert-version',
            'magic-convert-activation-error',
            'magic-convert-migration-version'
        ];
        foreach ($optionsToDelete as $i => $optionName) {
            Option::deleteOption($optionName);
        }

        // remove content dir (config plus images plus htaccess-tests)
        FileHelper::rrmdir(Paths::getMagicConvertContentDirAbs());
    }
}
