<?php

namespace MagicConvert;

class NginxDriftNudge
{
    const THROTTLE_KEY = 'magic_convert_nginx_drift_check';

    const ACKED_DRIFT_KEY = 'nginx-drift-nudged-installed-fingerprint';

    const THROTTLE_SECONDS = 86400;

    const REQUEST_TIMEOUT = 3;

    public static function maybeCheck()
    {
        if (!PlatformInfo::isNginx()) {
            return;
        }
        $state = State::getState('nginx-rules', null);
        if (!is_array($state) || empty($state['fingerprint'])) {
            return;
        }

        if (self::throttleActive()) {
            return;
        }
        self::markChecked();

        try {
            list($success, $body) = self::fetchInstalledFingerprint();
            $drift = SelfTestNginx::classifyDrift($success, $body, (string) $state['fingerprint']);
            $installed = ($body === null) ? null : trim((string) $body);
            $alreadyNudged = (string) State::getState(self::ACKED_DRIFT_KEY, '');

            switch (self::decideAction($drift, $installed, $alreadyNudged)) {
                case 'arm':
                    DismissableGlobalMessages::addDismissableMessage(NginxRulesNotice::MESSAGE_ID);
                    State::setState(self::ACKED_DRIFT_KEY, (string) $installed);
                    break;
                case 'resync':
                    State::setState(self::ACKED_DRIFT_KEY, '');
                    NginxRulesNotice::clear();
                    break;
                case 'noop':
                default:
                    break;
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param  string       $drift
     * @param  string|null  $installed
     * @param  string       $alreadyNudged
     * @return string
     */
    public static function decideAction($drift, $installed, $alreadyNudged)
    {
        if ($drift === SelfTestNginx::DRIFT_STALE) {
            if ((string) $installed !== (string) $alreadyNudged) {
                return 'arm';
            }
            return 'noop';
        }
        if ($drift === SelfTestNginx::DRIFT_UP_TO_DATE) {
            if ((string) $alreadyNudged !== '') {
                return 'resync';
            }
            return 'noop';
        }
        return 'noop';
    }

    private static function throttleActive()
    {
        if (function_exists('get_transient')) {
            return (get_transient(self::THROTTLE_KEY) !== false);
        }
        return false;
    }

    private static function markChecked()
    {
        if (function_exists('set_transient')) {
            set_transient(self::THROTTLE_KEY, time(), self::THROTTLE_SECONDS);
        }
    }

    /**
     * @return array{0:bool,1:string|null}
     */
    private static function fetchInstalledFingerprint()
    {
        $home = home_url('/');
        $parts = parse_url($home);
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? (':' . $parts['port']) : '';
        $url = $scheme . '://' . $host . $port . '/magic-convert-rules-version';

        $args = ['timeout' => self::REQUEST_TIMEOUT, 'redirection' => 0];
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $args['sslverify'] = false;
        }

        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) {
            return [false, null];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return [false, null];
        }
        $body = wp_remote_retrieve_body($resp);
        return [true, is_string($body) ? $body : null];
    }
}
