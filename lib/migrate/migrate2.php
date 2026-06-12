<?php

namespace MagicConvert;

use \MagicConvert\Messenger;
use \MagicConvert\Option;
use \MagicConvert\Paths;
use \MagicConvert\TestRun;

/* helper. Remove dir recursively. No warnings - fails silently
   Set $removeTheDirItself to false if you want to empty the dir
*/
function magicconvert_migrate2_rrmdir($dir, $removeTheDirItself = true) {
    if (@is_dir($dir)) {
        $objects = @scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                $file = $dir . "/" . $object;
                if (@is_dir($file)) {
                    magicconvert_migrate2_rrmdir($file);
                } else {
                    @unlink($file);
                }
            }
        }
        if ($removeTheDirItself) {
            @rmdir($dir);
        }
    }
}

$testResult = TestRun::getConverterStatus();
if ($testResult) {
    $workingConverters = $testResult['workingConverters'];
    if (in_array('imagick', $workingConverters)) {
       magicconvert_migrate2_rrmdir(Paths::getCacheDirAbs(), false);
       Messenger::addMessage(
           'info',
           'Magic Convert has emptied the image cache. In previous versions, the imagick converter ' .
              'was generating images in poor quality. This has been fixed. As your system meets the ' .
              'requirements of the imagick converter, it might be that you have been using that. So ' .
              'to be absolutely sure you do not have inferior conversions in the cache dir, it has been emptied.'
       );
    }
    if (in_array('gmagick', $workingConverters)) {
        Messenger::addMessage(
            'info',
            'Good news! Magic Convert is now able to use the gmagick extension for conversion - ' .
               'and your server meets the requirements!'
        );
    }
    if (in_array('cwebp', $workingConverters)) {
        Messenger::addMessage(
            'info',
            'Magic Convert added several options for the cwebp conversion method. ' .
                '<a href="' . Paths::getSettingsUrl() . '">Go to the settings page to check it out</a>.'
        );
    }
}
Messenger::addMessage(
    'info',
    'Magic Convert can now be configured to cache the webp images. You might want to ' .
        '<a href="' . Paths::getSettingsUrl() . '">do that</a>.'
);


Option::updateOption('magic-convert-migration-version', '2');
