<?php

namespace MagicConvert;

use \MagicConvert\Avif\AvifStack;

/**
 * Persistent admin notice for the #1 AVIF support generator: "AVIF is turned on but this
 * server cannot encode it."
 *
 * When AVIF output is enabled in config yet NO converter in the stack is operational, the user
 * would otherwise see silent conversion failures and open a support ticket. This surfaces the
 * problem proactively, in the admin, with a link to the (single-source-of-truth) AVIF
 * capabilities self-test where the precise per-converter reasons live.
 *
 * Behaviour:
 *   - Dismissable (the X stores a flag so it stays hidden), BUT
 *   - it REAPPEARS on the next config save: Config::saveConfigurationFile() clears the flag, so
 *     a user who changes settings (e.g. re-enables AVIF, or just re-saves) is reminded again if
 *     the server still cannot encode AVIF.
 *
 * The operability decision uses AvifStack (the same detection the conversion path and the
 * self-test use) — there is no separate detection here.
 */
class AvifNotice
{
    /** Option flag: truthy => the user dismissed the notice for the current config state. */
    const DISMISSED_OPTION = 'magic-convert-avif-detect-notice-dismissed';

    /**
     * Should the "AVIF enabled but inoperable" notice be shown right now?
     *
     * Pure-ish: takes the config array + an AvifStack (injectable for tests) and the current
     * dismissed flag, and returns the decision. True only when AVIF is enabled AND the stack has
     * no operational converter AND the user has not dismissed it for this config state.
     *
     * @param  array          $config     Config array.
     * @param  AvifStack|null $stack      Injected for tests; built fresh when null.
     * @param  bool           $dismissed  Whether the user has dismissed it.
     * @return bool
     */
    public static function shouldShow($config, $stack = null, $dismissed = false)
    {
        if ($dismissed) {
            return false;
        }
        $avifEnabled = (
            is_array($config) &&
            isset($config['formats']['avif']['enabled']) &&
            ($config['formats']['avif']['enabled'] === true)
        );
        if (!$avifEnabled) {
            return false;
        }
        if ($stack === null) {
            $stack = new AvifStack();
        }
        // Show the notice precisely when NOTHING can encode AVIF.
        return !$stack->isOperational();
    }

    /**
     * admin_notices hook: render the notice when shouldShow() says so.
     */
    public static function maybePrint()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }

        $config = Config::loadConfigAndFix(false);
        $dismissed = (bool) Option::getOption(self::DISMISSED_OPTION, false);

        if (!self::shouldShow($config, null, $dismissed)) {
            return;
        }

        $settingsUrl = Paths::getSettingsUrl();

        // A dismissable WP admin notice. The X posts to our ajax action to persist the
        // dismissal; the inline JS also slides it up immediately for snappy feedback.
        echo '<div class="notice notice-warning is-dismissible" data-magicconvert-avif-notice="1">';
        echo '<p><strong>Magic Convert:</strong> AVIF output is enabled, but no AVIF-capable '
            . 'converter was found on this server, so AVIF files cannot be generated. ';
        echo '<a href="' . esc_url($settingsUrl) . '">Open the settings and run '
            . '&ldquo;System AVIF capabilities&rdquo;</a> to see why.</p>';
        echo '</div>';

        // Persist the dismissal when WordPress fires the core dismiss button.
        echo '<script>(function(){'
            . 'document.addEventListener("click",function(e){'
            . 'var n=e.target.closest(".notice[data-magicconvert-avif-notice] .notice-dismiss");'
            . 'if(!n){return;}'
            . 'if(window.jQuery){jQuery.post(ajaxurl,{action:"magicconvert_dismiss_avif_notice"});}'
            . '});'
            . '})();</script>';
    }

    /**
     * ajax handler: remember that the user dismissed the notice. No nonce needed —
     * dismissing a purely informational notice is harmless and carries no parameters.
     */
    public static function processAjaxDismiss()
    {
        Option::updateOption(self::DISMISSED_OPTION, true, false);
        if (function_exists('wp_die')) {
            wp_die();
        }
    }

    /**
     * Clear the dismissal so the notice REAPPEARS on the next page load if the server still
     * cannot encode AVIF. Called from Config::saveConfigurationFile() after a successful save.
     */
    public static function resetDismissal()
    {
        Option::deleteOption(self::DISMISSED_OPTION);
    }
}
