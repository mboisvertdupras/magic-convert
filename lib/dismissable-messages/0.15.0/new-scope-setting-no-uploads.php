<?php

namespace MagicConvert;

DismissableMessages::printDismissableMessage(
    'info',
    'Magic Convert 0.15 introduced a new "scope" setting which determines which folders that Magic Convert ' .
        'operates in. The migration script did not set it to include "uploads" because it seemed that it ' .
        'would not be possible to write the .htaccess rules there.',
    '0.15.0/new-scope-setting-no-uploads',
    'Got it!'
);
