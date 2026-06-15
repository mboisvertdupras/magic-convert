<?php

namespace MagicConvert;

use \MagicConvert\Config;
use \MagicConvert\HTAccess;
use \MagicConvert\Messenger;
use \MagicConvert\Multisite;
use \MagicConvert\Paths;
use \MagicConvert\PlatformInfo;
use \MagicConvert\State;

class PluginActivate
{
    // callback for 'register_activation_hook' (registred in AdminInit)
    public static function activate($network_active) {

        Multisite::overrideIsNetworkActivated($network_active);

        // Test if plugin is activated for the first time or reactivated
        if (State::getState('configured', false)) {
            self::reactivate();
        } else {
            self::activateFirstTime();
        }

    }

    private static function reactivate()
    {
        $config = Config::loadConfigAndFix(false);  // false, because we do not need to test if quality detection is working

        if ($config === false) {
            Messenger::addMessage(
                'error',
                'The config file seems to have gone missing. You will need to reconfigure Magic Convert ' .
                    '<a href="' . Paths::getSettingsUrl() . '">(here)</a>.'
            );
        } else {
            $rulesResult = HTAccess::saveRules($config, false);

            $rulesSaveSuccess = $rulesResult[0];
            if ($rulesSaveSuccess) {
                if (PlatformInfo::isNginx()) {
                    Messenger::addMessage(
                        'success',
                        'Magic Convert re-activated successfully.<br>' .
                            'Just a quick reminder: this server is nginx, so if you moved WordPress or changed the ' .
                            'upload directory, re-check the <strong>NGINX</strong> section on the settings page and ' .
                            're-install the rules if the path changed ' .
                            '<a href="' . Paths::getSettingsUrl() . '">(here)</a>.'
                    );
                } else {
                    Messenger::addMessage(
                        'success',
                        'Magic Convert re-activated successfully.<br>' .
                            'The image redirections are in effect again.<br><br>' .
                            'Just a quick reminder: If you at some point change the upload directory or move Wordpress, ' .
                            'the <i>.htaccess</i> files will need to be regenerated.<br>' .
                            'You do that by re-saving the settings ' .
                            '<a href="' . Paths::getSettingsUrl() . '">(here)</a>'
                    );
                }
            } else {
                Messenger::addMessage(
                    'warning',
                    'Magic Convert could not regenerate the rewrite rules<br>' .
                        'You need to change some permissions. Head to the ' .
                        '<a href="' . Paths::getSettingsUrl() . '">settings page</a> ' .
                        'and try to save the settings there (it will provide more information about the problem)'
                );
            }

            HTAccess::showSaveRulesMessages($rulesResult);
        }
    }

    private static function activateFirstTime()
    {
        if (PlatformInfo::isMicrosoftIis()) {
            Messenger::addMessage(
                'warning',
                'You are on Microsoft IIS server. Magic Convert has not been tested on IIS, so it may not work correctly.'
            );
        }

        if (Multisite::isNetworkActivated()) {
            Messenger::addMessage(
                'info',
                'Magic Convert was installed successfully. To start using it, you must ' .
                    '<a href="' . Paths::getSettingsUrl() . '">configure it here</a>.'
            );
            return;
        }

        try {
            $isNginx = PlatformInfo::isNginx();

            $config = Config::loadConfigAndFix(true);
            if (!is_array($config)) {
                self::addConfigureItHereMessage();
                return;
            }

            $config = Config::applyFirstActivationPlatformDefaults($config, $isNginx);

            $result = Config::saveConfigurationAndHTAccess($config, true);

            $savedOk = is_array($result) && !empty($result['saved-both-config']);
            if (!$savedOk) {
                self::addConfigureItHereMessage();
                return;
            }

            if ($isNginx) {
                Messenger::addMessage(
                    'success',
                    'Magic Convert is ready. Image serving works immediately via HTML alteration — ' .
                        'no server configuration required. For the fastest serving (and on-demand conversion), ' .
                        'install the generated nginx rules from the <strong>NGINX</strong> section on the ' .
                        '<a href="' . Paths::getSettingsUrl() . '">settings page</a>, where you can also bulk-convert ' .
                        'your existing images.'
                );
            } else {
                $htaccessResult = (isset($result['htaccess-result']) && is_array($result['htaccess-result']))
                    ? $result['htaccess-result']
                    : null;
                $rulesWritten = is_array($htaccessResult) && !empty($htaccessResult[0]);

                if ($rulesWritten) {
                    Messenger::addMessage(
                        'success',
                        'Magic Convert is ready. Your images are now served as WebP automatically. ' .
                            'Head to the <a href="' . Paths::getSettingsUrl() . '">settings page</a> to bulk-convert ' .
                            'your existing images, enable AVIF and tweak options.'
                    );
                } else {
                    Messenger::addMessage(
                        'warning',
                        'Magic Convert was installed, but it could not write the rewrite rules that serve your ' .
                            'images as WebP (likely a file-permission issue), so automatic serving is not ' .
                            'active yet. Fix the permissions noted below, then re-save the settings ' .
                            '<a href="' . Paths::getSettingsUrl() . '">here</a>.'
                    );
                }

                if (is_array($htaccessResult)) {
                    HTAccess::showSaveRulesMessages($htaccessResult);
                }
            }
        } catch (\Throwable $e) {
            self::addConfigureItHereMessage();
        }
    }

    private static function addConfigureItHereMessage()
    {
        Messenger::addMessage(
            'info',
            'Magic Convert was installed successfully. To start using it, you must ' .
                '<a href="' . Paths::getSettingsUrl() . '">configure it here</a>.'
        );
    }
}
