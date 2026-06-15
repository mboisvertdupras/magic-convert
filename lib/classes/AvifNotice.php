<?php

namespace MagicConvert;

use \MagicConvert\Avif\AvifStack;

class AvifNotice
{
    const DISMISSED_OPTION = 'magic-convert-avif-detect-notice-dismissed';

    /**
     * @param  array          $config
     * @param  AvifStack|null $stack
     * @param  bool           $dismissed
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
        return !$stack->isOperational();
    }

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

        echo '<div class="notice notice-warning is-dismissible" data-magicconvert-avif-notice="1">';
        echo '<p><strong>Magic Convert:</strong> AVIF output is enabled, but no AVIF-capable '
            . 'converter was found on this server, so AVIF files cannot be generated. ';
        echo '<a href="' . esc_url($settingsUrl) . '">Open the settings and run '
            . '&ldquo;System AVIF capabilities&rdquo;</a> to see why.</p>';
        echo '</div>';

        echo '<script>(function(){'
            . 'document.addEventListener("click",function(e){'
            . 'var n=e.target.closest(".notice[data-magicconvert-avif-notice] .notice-dismiss");'
            . 'if(!n){return;}'
            . 'if(window.jQuery){jQuery.post(ajaxurl,{action:"magicconvert_dismiss_avif_notice"});}'
            . '});'
            . '})();</script>';
    }

    public static function processAjaxDismiss()
    {
        Option::updateOption(self::DISMISSED_OPTION, true, false);
        if (function_exists('wp_die')) {
            wp_die();
        }
    }

    public static function resetDismissal()
    {
        Option::deleteOption(self::DISMISSED_OPTION);
    }
}
