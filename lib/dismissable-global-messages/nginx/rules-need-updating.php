<?php

namespace MagicConvert;

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
