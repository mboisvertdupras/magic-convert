<?php

namespace MagicConvert;

class NginxDownload
{
    const NONCE_ACTION = 'magicconvert-nginx-download';

    public static function handle()
    {
        if (!current_user_can('manage_options')) {
            self::fail(403, 'You do not have sufficient permissions.');
            return;
        }

        $nonce = isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            self::fail(403, 'Security check failed. Please reload the settings page and try again.');
            return;
        }

        $requested = isset($_REQUEST['artifact']) ? $_REQUEST['artifact'] : '';
        $canonical = NginxPanel::resolveArtifactKey($requested);
        if ($canonical === false) {
            self::fail(400, 'Unknown artifact.');
            return;
        }

        $config = Config::loadConfigAndFix(false);
        try {
            $body = NginxPanel::generateArtifactFromPaths($canonical, $config);
        } catch (\Throwable $e) {
            self::fail(500, 'Could not generate the nginx rules.');
            return;
        }

        $filename = NginxPanel::downloadFilename($canonical);

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($body));
            header('X-Content-Type-Options: nosniff');
        }
        echo $body;
        if (function_exists('wp_die')) {
            wp_die();
        } else {
            exit;
        }
    }

    /**
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
