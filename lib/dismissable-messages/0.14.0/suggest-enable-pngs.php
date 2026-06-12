<?php
use \MagicConvert\DismissableMessages;

// introduced in 0.14.0 (migrate 9)
DismissableMessages::printDismissableMessage(
    'info',
    'Magic Convert 0.14 handles PNG to WebP conversions quite well. Perhaps it is time to enable PNGs? ',
    '0.14.0/suggest-enable-pngs',
    'Got it!'
);
