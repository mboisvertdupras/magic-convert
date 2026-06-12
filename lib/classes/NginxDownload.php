<?php

namespace MagicConvert;

/**
 * NginxDownload — the authenticated admin-post endpoint that streams a generated nginx artifact
 * as a .conf attachment.
 *
 * SECURITY (this is the whole point of the class):
 *   - capability gate: current_user_can('manage_options').
 *   - nonce: a dedicated 'magicconvert-nginx-download' nonce, verified before anything else.
 *   - the artifact is chosen by a WHITELISTED key only (NginxPanel::resolveArtifactKey) — never
 *     free-form input; an unknown key is rejected with 400.
 *   - the content is generated ON THE FLY (NginxRules has no disk-writing method) so the
 *     hash-bearing rule body never touches a web-accessible path.
 *
 * Registered as `admin_post_magicconvert_nginx_download` (see AdminInit). admin-post.php has
 * already authenticated the WP user; we additionally assert the capability + nonce here so the
 * endpoint is safe even if the registration ever changes.
 */
class NginxDownload
{
    const NONCE_ACTION = 'magicconvert-nginx-download';

    /**
     * admin_post handler. Streams the requested artifact or dies with an error status.
     */
    public static function handle()
    {
        // 1) Capability.
        if (!current_user_can('manage_options')) {
            self::fail(403, 'You do not have sufficient permissions.');
            return;
        }

        // 2) Nonce. check_admin_referer dies on failure; we keep it explicit for clarity.
        $nonce = isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            self::fail(403, 'Security check failed. Please reload the settings page and try again.');
            return;
        }

        // 3) Whitelisted artifact key only.
        $requested = isset($_REQUEST['artifact']) ? $_REQUEST['artifact'] : '';
        $canonical = NginxPanel::resolveArtifactKey($requested);
        if ($canonical === false) {
            self::fail(400, 'Unknown artifact.');
            return;
        }

        // 4) Generate on the fly (never from disk).
        $config = Config::loadConfigAndFix(false);
        try {
            $body = NginxPanel::generateArtifactFromPaths($canonical, $config);
        } catch (\Throwable $e) {
            self::fail(500, 'Could not generate the nginx rules.');
            return;
        }

        $filename = NginxPanel::downloadFilename($canonical);

        // 5) Stream as an attachment. The body embeds the config hash, so forbid caching by
        // intermediaries and the browser disk cache.
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($body));
            header('X-Content-Type-Options: nosniff');
        }
        echo $body;
        // admin-post.php expects us to terminate the request ourselves.
        if (function_exists('wp_die')) {
            wp_die();
        } else {
            exit;
        }
    }

    /**
     * Emit a plain-text error with the given HTTP status and terminate.
     *
     * @param  int     $status
     * @param  string  $message
     */
    private static function fail($status, $message)
    {
        if (function_exists('status_header')) {
            status_header($status);
        }
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message;
        if (function_exists('wp_die')) {
            wp_die('', '', ['response' => $status]);
        } else {
            exit;
        }
    }
}
