<?php

namespace MagicConvert;

/*
 * Global dismissable message: the rule-affecting settings changed on an nginx host, so the
 * operator-installed nginx include is now stale and must be regenerated. Armed by
 * NginxRulesNotice::arm() from Config::saveConfigurationAndHTAccess() whenever the settings
 * fingerprint changes (re-armed on every change, so it reappears after a previous dismissal).
 *
 * The button takes the user straight to the settings page, where the nginx panel holds the
 * freshly generated artifacts (Copy / Download).
 */

DismissableGlobalMessages::printDismissableMessage(
    'warning',
    '<strong>Magic Convert:</strong> your nginx rules need updating. ' .
        'You changed a setting that affects the generated nginx configuration, but nginx does ' .
        'not read <i>.htaccess</i>, so the change will not take effect until you re-install the ' .
        'updated rules. Open the nginx panel on the settings page to copy or download the new ' .
        'configuration, then reload nginx.',
    NginxRulesNotice::MESSAGE_ID,
    [
        ['text' => 'Open the nginx panel', 'redirect-to-settings' => true],
        ['text' => 'Dismiss'],
    ]
);
