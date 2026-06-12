<?php

namespace MagicConvert;

/**
 * NginxDriftNudge — the lightweight scheduled drift check (roadmap Phase 3.3 item 3).
 *
 * On admin page loads (admin_init), at most ~once per day (throttled via a transient), AND only when
 * the platform is nginx AND a rules-version fingerprint is persisted, this does ONE cheap loopback
 * GET of /magic-convert-rules-version with a short timeout and compares the body to the persisted
 * fingerprint. On a positive "version differs" it arms the existing 3.2 dismissable notice
 * (NginxRulesNotice / DismissableGlobalMessages). It NEVER arms on errors — a loopback-blocked host
 * (timeout, connection refused, 404, empty body) is treated as "inconclusive" and stays silent, so
 * the notice can never be spammed by an unreachable endpoint.
 *
 * The drift comparison reuses SelfTestNginx::classifyDrift (the same pure logic the live self-test
 * uses), so the nudge and the manual test always agree.
 *
 * THROTTLE: a transient named THROTTLE_KEY is set for THROTTLE_SECONDS at the START of a check, so
 * concurrent/repeated admin loads do not each fire a loopback request. The transient is set even
 * when the check is inconclusive — we do not retry-storm a blocked host within the window.
 *
 * NO RE-NAG AFTER DISMISSAL: unlike the 3.2 NginxRulesNotice (which re-arms on a fingerprint-CHANGE
 * event — a config save), this nudge fires on a STANDING drift condition that persists until the
 * user reinstalls the rules. If we re-armed the notice every time drift still STANDS, a user who
 * dismissed the notice but has not yet reinstalled would be re-nagged on the next daily check with
 * nothing having changed. To avoid that, we remember the installed fingerprint we last nudged about
 * (persisted in state as ACKED_DRIFT_KEY). We only (re)arm the notice when the CURRENTLY-installed
 * fingerprint differs from the one we already nudged about — i.e. a genuinely NEW drift state
 * (they reinstalled different-but-still-stale rules, or settings changed again). A re-check that
 * sees the SAME standing drift the user already saw stays silent (dismiss + same-fingerprint
 * re-check stays dismissed).
 */
class NginxDriftNudge
{
    /** Transient key gating how often the loopback check runs. */
    const THROTTLE_KEY = 'magic_convert_nginx_drift_check';

    /** State key remembering the installed fingerprint we last armed/nudged the notice for. */
    const ACKED_DRIFT_KEY = 'nginx-drift-nudged-installed-fingerprint';

    /** ~daily. */
    const THROTTLE_SECONDS = 86400;

    /** Short loopback timeout (seconds) — this must never slow an admin page load noticeably. */
    const REQUEST_TIMEOUT = 3;

    /**
     * The admin_init entry point. Guarded, throttled, and failure-silent.
     *
     * @return void
     */
    public static function maybeCheck()
    {
        // Cheap guards first (no I/O): nginx only, and only when we have a fingerprint to compare to.
        if (!PlatformInfo::isNginx()) {
            return;
        }
        $state = State::getState('nginx-rules', null);
        if (!is_array($state) || empty($state['fingerprint'])) {
            return;
        }

        // Throttle: bail if we already checked within the window. Set the transient up-front so a
        // blocked host is not retried on every admin load.
        if (self::throttleActive()) {
            return;
        }
        self::markChecked();

        // Do the cheap loopback probe; swallow everything.
        try {
            list($success, $body) = self::fetchInstalledFingerprint();
            $drift = SelfTestNginx::classifyDrift($success, $body, (string) $state['fingerprint']);
            $installed = ($body === null) ? null : trim((string) $body);
            $alreadyNudged = (string) State::getState(self::ACKED_DRIFT_KEY, '');

            // Pure decision: what should we do given the drift state and what we already nudged for?
            switch (self::decideAction($drift, $installed, $alreadyNudged)) {
                case 'arm':
                    DismissableGlobalMessages::addDismissableMessage(NginxRulesNotice::MESSAGE_ID);
                    State::setState(self::ACKED_DRIFT_KEY, (string) $installed);
                    break;
                case 'resync':
                    // Back in sync (the user reinstalled). Forget the remembered fingerprint so a
                    // FUTURE drift re-arms cleanly, and clear the notice if it is still standing.
                    State::setState(self::ACKED_DRIFT_KEY, '');
                    NginxRulesNotice::clear();
                    break;
                case 'noop':
                default:
                    // Same standing drift we already nudged about (stays dismissed), or
                    // absent/inconclusive: stay silent and leave the remembered fingerprint untouched.
                    break;
            }
        } catch (\Throwable $e) {
            // never spam notices on failure
        }
    }

    /**
     * PURE decision core (no WP / no I/O — unit tested directly). Given the drift classification, the
     * currently-installed fingerprint, and the fingerprint we last nudged about, decide what to do.
     *
     * This is what keeps the nudge from re-nagging after a dismissal: a STALE drift only arms when
     * the installed fingerprint is NEW relative to the one we already nudged about. A re-check that
     * sees the same standing drift returns 'noop', so a dismissed notice stays dismissed.
     *
     * @param  string       $drift         a SelfTestNginx::DRIFT_* constant.
     * @param  string|null  $installed     the installed fingerprint just read (trimmed), or null.
     * @param  string       $alreadyNudged the installed fingerprint we last armed the notice for.
     * @return string  'arm' | 'resync' | 'noop'
     */
    public static function decideAction($drift, $installed, $alreadyNudged)
    {
        if ($drift === SelfTestNginx::DRIFT_STALE) {
            // Only (re)arm when this is a genuinely NEW drift state we have not nudged about yet.
            if ((string) $installed !== (string) $alreadyNudged) {
                return 'arm';
            }
            return 'noop';
        }
        if ($drift === SelfTestNginx::DRIFT_UP_TO_DATE) {
            // Reset our memory only if we were tracking a drift (so we do not write state needlessly).
            if ((string) $alreadyNudged !== '') {
                return 'resync';
            }
            return 'noop';
        }
        // DRIFT_ABSENT or anything else: inconclusive, leave everything as-is.
        return 'noop';
    }

    /**
     * Whether the throttle window is still active.
     *
     * @return bool
     */
    private static function throttleActive()
    {
        if (function_exists('get_transient')) {
            return (get_transient(self::THROTTLE_KEY) !== false);
        }
        return false;
    }

    /**
     * Mark that a check has just run (open the throttle window).
     *
     * @return void
     */
    private static function markChecked()
    {
        if (function_exists('set_transient')) {
            set_transient(self::THROTTLE_KEY, time(), self::THROTTLE_SECONDS);
        }
    }

    /**
     * One cheap loopback GET of the rules-version marker. Returns [success, body|null]. Any error
     * (wp_error, non-200) yields [false, null] so the caller treats it as inconclusive.
     *
     * @return array{0:bool,1:string|null}
     */
    private static function fetchInstalledFingerprint()
    {
        // The marker is a server-root exact-match location, so request the host root (not a WP
        // subdirectory path).
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
