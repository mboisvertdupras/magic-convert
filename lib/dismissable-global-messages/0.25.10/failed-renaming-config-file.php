<?php

namespace MagicConvert;

$msgId = '0.25.10/failed-renaming-config-file';

DismissableGlobalMessages::printDismissableMessage(
    'warning',
    'Magic Convert failed renaming the configuration files to something secret. ' .
        'This was attempted in order to protect its contents from prying eyes on servers that does not respect .htaccess files (such as Nginx). ' .
        'If you have entered sensitive information in Magic Convert settings (I can only think of Ewww API key), you should remove it. You can do simply by changing the Magic Convert settings.' .
        (PlatformInfo::isNginx() ? 'Especially, as it appears you are in fact on Nginx. ' : '') .
        (PlatformInfo::isNginx() ? '' : 'You are by the way not on Nginx ') .
        (PlatformInfo::isLiteSpeed() ? '(you are on LiteSpeed). ' : '') .
        (PlatformInfo::isApache() ? '(you are on Apache). ' : '') .
        (PlatformInfo::isMicrosoftIis() ? '(you are on Microsoft IIS). ' : '') .
        'The files in question are config.json and wod-options.json in wp-content/magic-convert/config'
        ,

    $msgId,
    [
        ['text' => 'Dismiss'],
    ]
);
