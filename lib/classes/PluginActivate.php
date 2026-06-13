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
        // Issue platform warnings, if any.
        // -------------------------------

        if (PlatformInfo::isMicrosoftIis()) {
            Messenger::addMessage(
                'warning',
                'You are on Microsoft IIS server. Magic Convert has not been tested on IIS, so it may not work correctly.'
            );
        }

        // On network-wide (multisite) activation we cannot safely auto-configure each site here, so
        // keep the classic "configure it here" flow. Single-site (the normal case) gets zero-config
        // auto-setup below.
        // -------------------------------

        if (Multisite::isNetworkActivated()) {
            Messenger::addMessage(
                'info',
                'Magic Convert was installed successfully. To start using it, you must ' .
                    '<a href="' . Paths::getSettingsUrl() . '">configure it here</a>.'
            );
            return;
        }

        // Zero-config auto-setup (single site).
        // -------------------------------
        // An activation hook must NEVER fatal — a thrown error white-screens the activation — so the
        // whole attempt is wrapped in try/catch and any failure falls back to the classic
        // "configure it here" message.

        try {
            $isNginx = PlatformInfo::isNginx();

            $config = Config::loadConfigAndFix(true);
            if (!is_array($config)) {
                // Couldn't build a config (extremely unlikely). Fall back.
                self::addConfigureItHereMessage();
                return;
            }

            // Platform-aware overrides (e.g. enable Alter HTML on nginx so converted images are
            // served with zero server configuration). Pure helper, fully decided in Config.
            $config = Config::applyFirstActivationPlatformDefaults($config, $isNginx);

            // Persist config + wod options, write .htaccess where relevant, and set State 'configured'.
            $result = Config::saveConfigurationAndHTAccess($config, true);

            $savedOk = is_array($result) && !empty($result['saved-both-config']);
            if (!$savedOk) {
                // Saving failed (e.g. file permissions). Fall back to the classic flow so the user
                // can sort it out on the settings page.
                self::addConfigureItHereMessage();
                return;
            }

            // Success! Platform-aware upbeat notice.
            // Note: 'convert-on-upload' is OFF by default, so we do NOT promise automatic conversion
            // of newly uploaded images — we point users at Bulk Convert for existing images instead.
            if ($isNginx) {
                // nginx never serves via .htaccess, so the htaccess-result is irrelevant here — the
                // HTML-alteration path we enabled above serves converted images with zero server
                // config. (We deliberately do NOT call showSaveRulesMessages on nginx: it would add a
                // confusing ".htaccess was written but nginx ignores it" warning on top of this
                // message.) Serving works immediately; native rules are an optional speed-up.
                Messenger::addMessage(
                    'success',
                    'Magic Convert is ready. Image serving works immediately via HTML alteration — ' .
                        'no server configuration required. For the fastest serving (and on-demand conversion), ' .
                        'install the generated nginx rules from the <strong>NGINX</strong> section on the ' .
                        '<a href="' . Paths::getSettingsUrl() . '">settings page</a>, where you can also bulk-convert ' .
                        'your existing images.'
                );
            } else {
                // Apache / LiteSpeed: serving depends on the .htaccess rewrite rules actually being
                // written. saveConfigurationAndHTAccess() reports saved-both-config=true once the
                // config JSON files are written — REGARDLESS of whether HTAccess::saveRules() managed
                // to write the rewrite rules (a common failure on shared hosts where wp-content/root
                // .htaccess is not writable). So we must inspect the htaccess outcome ourselves and
                // never make the cheerful "served automatically" promise when rule writing failed.
                $htaccessResult = (isset($result['htaccess-result']) && is_array($result['htaccess-result']))
                    ? $result['htaccess-result']
                    : null;
                $rulesWritten = is_array($htaccessResult) && !empty($htaccessResult[0]);

                if ($rulesWritten) {
                    Messenger::addMessage(
                        'success',
                        'Magic Convert is ready. Your images are now served as WebP/AVIF automatically. ' .
                            'Head to the <a href="' . Paths::getSettingsUrl() . '">settings page</a> to bulk-convert ' .
                            'your existing images and tweak options.'
                    );
                } else {
                    // Config saved (State 'configured' is true), but the rewrite rules could not be
                    // written, so serving is NOT yet active. Tell the user honestly and let
                    // showSaveRulesMessages emit the specific permission error below.
                    Messenger::addMessage(
                        'warning',
                        'Magic Convert was installed, but it could not write the rewrite rules that serve your ' .
                            'images as WebP/AVIF (likely a file-permission issue), so automatic serving is not ' .
                            'active yet. Fix the permissions noted below, then re-save the settings ' .
                            '<a href="' . Paths::getSettingsUrl() . '">here</a>.'
                    );
                }

                // Emit the detailed per-file rewrite-rule outcome (which files were written / failed,
                // with the exact permission fix), exactly as the manual save flow in submit.php does.
                if (is_array($htaccessResult)) {
                    HTAccess::showSaveRulesMessages($htaccessResult);
                }
            }
        } catch (\Throwable $e) {
            // An activation hook must never fatal. On any failure, fall back to the classic flow.
            self::addConfigureItHereMessage();
        }
    }

    /**
     * The classic "installed — go configure it" fallback message, used when auto-config is skipped
     * (network activation) or fails for any reason.
     */
    private static function addConfigureItHereMessage()
    {
        Messenger::addMessage(
            'info',
            'Magic Convert was installed successfully. To start using it, you must ' .
                '<a href="' . Paths::getSettingsUrl() . '">configure it here</a>.'
        );
    }
}
