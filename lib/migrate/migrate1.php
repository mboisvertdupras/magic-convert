<?php

namespace MagicConvert;

use \MagicConvert\Config;
use \MagicConvert\HTAccess;
use \MagicConvert\Messenger;
use \MagicConvert\Option;
use \MagicConvert\Paths;

// On successful migration:
// Option::updateOption('magic-convert-migration-version', '1', true);

function magic_convert_migrate1_createFolders()
{
    if (!Paths::createContentDirIfMissing()) {
        Messenger::printMessage(
            'error',
            'For migration to 0.5.0, Magic Convert needs to create a directory "magic-convert" under your wp-content folder, but does not have permission to do so.<br>' .
                'Please create the folder manually, or change the file permissions of your wp-content folder.'
        );
        return false;
    } else {
        if (!Paths::createConfigDirIfMissing()) {
            Messenger::printMessage(
                'error',
                'For migration to 0.5.0, Magic Convert needs to create a directory "magic-convert/config" under your wp-content folder, but does not have permission to do so.<br>' .
                    'Please create the folder manually, or change the file permissions.'
            );
            return false;
        }


        if (!Paths::createCacheDirIfMissing()) {
            Messenger::printMessage(
                'error',
                'For migration to 0.5.0, Magic Convert needs to create a directory "magic-convert/webp-images" under your wp-content folder, but does not have permission to do so.<br>' .
                    'Please create the folder manually, or change the file permissions.'
            );
            return false;
        }
    }
    return true;
}

function magic_convert_migrate1_createDummyConfigFiles()
{
    // TODO...
    return true;
}

function magicconvert_migrate1_migrateOptions()
{
    $converters = json_decode(Option::getOption('webp_express_converters', '[]'), true);
    foreach ($converters as &$converter) {
        unset ($converter['id']);
    }

    $options = [
        'image-types' => intval(Option::getOption('webp_express_image_types_to_convert', 1)),
        'max-quality' => intval(Option::getOption('webp_express_max_quality', 80)),
        'fail' => Option::getOption('webp_express_failure_response', 'original'),
        'converters' => $converters,
        'forward-query-string' => true
    ];
    if ($options['max-quality'] == 0) {
        $options['max-quality'] = 80;
        if ($options['image-types'] == 0) {
            $options['image-types'] = 1;
        }
    }
    if ($options['converters'] == null) {
        $options['converters'] = [];
    }

    // TODO: Save
    //Messenger::addMessage('info', 'Options: <pre>' .  print_r($options, true) . '</pre>');
//    $htaccessExists = Config::doesHTAccessExists();

    $config = $options;

    //$htaccessExists = Config::doesHTAccessExists();
    //$rules = HTAccess::generateHTAccessRulesFromConfigObj($config);

    if (Config::saveConfigurationFile($config)) {
        $options = Config::generateWodOptionsFromConfigObj($config);
        if (Config::saveWodOptionsFile($options)) {

            Messenger::addMessage(
                'success',
                'Magic Convert has successfully migrated its configuration to 0.5.0'
            );

            //Config::saveConfigurationAndHTAccessFilesWithMessages($config, 'migrate');
            //$rulesResult = HTAccess::saveRules($config);  // Commented out because rules are going to be saved in migrate12
            /*
            'mainResult'        // 'index', 'wp-content' or 'failed'
            'minRequired'       // 'index' or 'wp-content'
            'pluginToo'         // 'yes', 'no' or 'depends'
            'pluginFailed'      // true if failed to write to plugin folder (it only tries that, if pluginToo == 'yes')
            'pluginFailedBadly' // true if plugin failed AND it seems we have rewrite rules there
            'overidingRulesInWpContentWarning'  // true if main result is 'index' but we cannot remove those in wp-content
            'rules'             // the rules that were generated
            */
            /*
            $mainResult = $rulesResult['mainResult'];
            $rules = $rulesResult['rules'];

            if ($mainResult != 'failed') {
                Messenger::addMessage(
                    'success',
                    'Magic Convert has successfully migrated its configuration and updated the rewrite rules to 0.5.0'
                );
            } else {
                Messenger::addMessage(
                    'warning',
                    'Magic Convert has successfully migrated its configuration.' .
                    'However, Magic Convert could not update the rewrite rules<br>' .
                        'You need to change some permissions. Head to the ' .
                        '<a href="' . Paths::getSettingsUrl() . '">settings page</a> ' .
                        'and try to save the settings there (it will provide more information about the problem)'
                );
            }
            */
        } else {
            Messenger::addMessage(
                'error',
                'For migration to 0.5.0, Magic Convert failed saving options file. ' .
                    'You must grant us write access to your wp-config folder.<br>' .
                    'Tried to save to: "' . Paths::getWodOptionsFileName() . '"' .
                    'Fix the file permissions and reload<br>'
            );
            return false;
        }
    } else {
        Messenger::addMessage(
            'error',
            'For migration to 0.5.0, Magic Convert failed saving configuration file.<br>' .
                'You must grant us write access to your wp-config folder.<br>' .
                'Tried to save to: "' . Paths::getConfigFileName() . '"' .
                'Fix the file permissions and reload<br>'
        );
        return false;
    }

    //saveConfigurationFile
    //return $options;
    return true;
}

function magicconvert_migrate1_deleteOldOptions() {
    // These are legacy options persisted by WebP Express (the upstream plugin this fork
    // migrates from). They are read above and removed here once migration is complete, so
    // they must reference the upstream key names rather than the rebranded fork names.
    $optionsToDelete = [
        'webp_express_max_quality',
        'webp_express_image_types_to_convert',
        'webp_express_failure_response',
        'webp_express_converters',
        'webp-express-inserted-rules-ok',
        'webp-express-configured',
        'webp-express-pending-messages',
        'webp-express-just-activated',
        'webp-express-message-pending',
        'webp-express-failed-inserting-rules',
        'webp-express-deactivate',
        'webp_express_fail_action',
        'webp_express_method',
        'webp_express_quality'

    ];
    foreach ($optionsToDelete as $i => $optionName) {
        Option::deleteOption($optionName);
    }
}

/* helper. Remove dir recursively. No warnings - fails silently */
function magicconvert_migrate1_rrmdir($dir) {
   if (@is_dir($dir)) {
     $objects = @scandir($dir);
     foreach ($objects as $object) {
       if ($object != "." && $object != "..") {
         if (@is_dir($dir."/".$object))
           magicconvert_migrate1_rrmdir($dir."/".$object);
         else
           @unlink($dir."/".$object);
       }
     }
     @rmdir($dir);
   }
}

function magicconvert_migrate1_deleteOldWebPImages() {
    $upload_dir = wp_upload_dir();
    $destinationRoot = trailingslashit($upload_dir['basedir']) . 'magic-convert';
    magicconvert_migrate1_rrmdir($destinationRoot);
}

if (magic_convert_migrate1_createFolders()) {
    if (magic_convert_migrate1_createDummyConfigFiles()) {
        if (magicconvert_migrate1_migrateOptions()) {
            magicconvert_migrate1_deleteOldOptions();
            magicconvert_migrate1_deleteOldWebPImages();
            Option::updateOption('magic-convert-migration-version', '1');
        }
    }
}
