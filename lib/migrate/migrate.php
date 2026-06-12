<?php

use \MagicConvert\Config;
use \MagicConvert\Messenger;
use \MagicConvert\Option;
use \MagicConvert\State;

/*
In WebP Express 0.4.0, there was a 'webp-express-configured' option.
We read the upstream key here so that pre-existing WebP Express installs (0.4 or below)
are detected and migrated onto this fork instead of being treated as unconfigured.
*/
if (Option::getOption('webp-express-configured', false)) {
    State::setState('configured', true);
}

/*
In WebP Express 0.1, there was no 'webp-express-configured' option.
To determine if WebP Express was configured in 0.1, we can test the (now obsolete) webp_express_converters option.
We read the upstream key here so that pre-existing WebP Express 0.1 installs are detected and migrated.
*/
if (!Option::getOption('webp-express-configured', false)) {
    if (!is_null(Option::getOption('webp_express_converters', null))) {
        State::setState('configured', true);
    }
}

if (!(State::getState('configured', false))) {
    // Options has never has been saved, so no migration is needed.
    // We can set migrate-version to current
    Option::updateOption('magic-convert-migration-version', MAGIC_CONVERT_MIGRATION_VERSION);
} else {

    for ($x = intval(Option::getOption('magic-convert-migration-version', 0)); $x < MAGIC_CONVERT_MIGRATION_VERSION; $x++) {
        if (intval(Option::getOption('magic-convert-migration-version', 0)) == $x) {
            // run migration X+1, which upgrades from X to X+1
            // It must take care of updating the "magic-convert-migration-version" option to X+1, - if successful.
            // If unsuccessful, it must leaves the option unaltered, which will prevent
            // newer migrations to run, until the problem with that migration is fixed.
            include __DIR__ . '/migrate' . ($x + 1) . '.php';
        }
    }
}

//KeepEwwwSubscriptionAlive::keepAliveIfItIsTime($config);
