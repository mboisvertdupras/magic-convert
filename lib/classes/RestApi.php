<?php

namespace MagicConvert;

use \MagicConvert\Convert;
use \MagicConvert\ConcurrencyAdvisor;
use \MagicConvert\Config;
use \MagicConvert\BulkConvert;
use \MagicConvert\FileHelper;
use \MagicConvert\Paths;
use \MagicConvert\SanityException;

/**
 * REST controller for the parallel bulk-conversion feature (namespace
 * `magic-convert/v1`).
 *
 * Two routes:
 *
 *   POST /magic-convert/v1/convert
 *       Convert ONE file. Body: { root, path, reconvert? }. Reuses the exact
 *       shared validation/conversion core (Convert::resolveImageSourcePath +
 *       Convert::runConversion) that admin-ajax uses, so the CVE-2019-15330
 *       path-containment guard is identical for both transports. The response
 *       carries a `server_busy` signal (so the browser pool backs off without
 *       guessing) and a fresh `wp_rest` nonce (so long sessions survive nonce
 *       rotation).
 *
 *   GET /magic-convert/v1/unconverted?list_id=&page=&per_page=
 *       Paged replacement for the single 50k-entry admin-ajax JSON blob. The
 *       FIRST call runs the (expensive) filesystem scan ONCE, persists the flat
 *       list to wp-content/magic-convert/bulk-lists/<id>.json via the atomic
 *       write helper, and returns page 1 plus the advisor's concurrency
 *       recommendation. Subsequent calls slice that file.
 *
 * Both routes require `manage_options` and standard cookie + X-WP-Nonce
 * ('wp_rest') auth.
 *
 * ## Thin glue, fat (testable) helpers
 *
 * The WP-facing methods (register_routes / the *_callback handlers) cannot be
 * unit-tested without bootstrapping WordPress, so they are deliberately thin.
 * The real logic — pagination math, list-id validation, list-file path
 * derivation, stale-list cleanup decisions — lives in the pure static helpers at
 * the bottom (paginate(), isValidListId(), listFilePath(), expiredListFiles())
 * which ARE unit-tested in tests/RestApiPaginationTest.php.
 */
class RestApi
{
    const NAMESPACE = 'magic-convert/v1';

    /** Default page size for the unconverted listing. */
    const DEFAULT_PER_PAGE = 500;

    /** Hard ceiling on page size (protects against huge slices). */
    const MAX_PER_PAGE = 1000;

    /** Absolute hard cap on browser parallelism, mirrored from the advisor. */
    const WEB_MAX = ConcurrencyAdvisor::WEB_MAX;

    /** Lists older than this (seconds) are deleted opportunistically. 24h. */
    const LIST_TTL_SECONDS = 86400;

    /**
     * Register both routes. Wired from the plugin bootstrap on the
     * 'rest_api_init' hook — which fires on EVERY request (REST runs outside
     * wp-admin), not only is_admin().
     */
    public static function registerRoutes()
    {
        register_rest_route(self::NAMESPACE, '/convert', [
            'methods' => 'POST',
            'callback' => ['\MagicConvert\RestApi', 'convertCallback'],
            'permission_callback' => ['\MagicConvert\RestApi', 'permissionCheck'],
        ]);

        register_rest_route(self::NAMESPACE, '/unconverted', [
            'methods' => 'GET',
            'callback' => ['\MagicConvert\RestApi', 'unconvertedCallback'],
            'permission_callback' => ['\MagicConvert\RestApi', 'permissionCheck'],
        ]);
    }

    /**
     * Permission gate for BOTH routes. Cookie + X-WP-Nonce ('wp_rest') auth is
     * applied by core on top of this capability check.
     *
     * @return bool
     */
    public static function permissionCheck()
    {
        return current_user_can('manage_options');
    }

    /**
     * POST /convert — convert a single file.
     *
     * @param  \WP_REST_Request  $request
     * @return \WP_REST_Response
     */
    public static function convertCallback($request)
    {
        $root = (string) $request->get_param('root');
        $path = (string) $request->get_param('path');
        $reconvert = self::truthy($request->get_param('reconvert'));

        $advisor = new ConcurrencyAdvisor();

        try {
            // Resolve {root, path} -> sanitized absolute source (same containment
            // guard the AJAX path uses). Throws SanityException on anything unsafe.
            $source = Convert::resolveImageSourcePath($root, $path);
        } catch (SanityException $e) {
            return self::respond([
                'success' => false,
                'msg' => 'Invalid source: ' . $e->getMessage(),
                'log' => '',
            ], $advisor, 400);
        } catch (\Exception $e) {
            return self::respond([
                'success' => false,
                'msg' => 'Invalid source',
                'log' => '',
            ], $advisor, 400);
        }

        // skip-if-fresh is the inverse of reconvert: a normal bulk pass skips
        // already-fresh destinations; an explicit reconvert forces a re-encode.
        $skipIfFresh = !$reconvert;

        $result = Convert::runConversion($source, null, null, $skipIfFresh);

        return self::respond($result, $advisor, 200);
    }

    /**
     * GET /unconverted — paged listing.
     *
     * @param  \WP_REST_Request  $request
     * @return \WP_REST_Response
     */
    public static function unconvertedCallback($request)
    {
        $advisor = new ConcurrencyAdvisor();
        $perPage = self::clampPerPage($request->get_param('per_page'));
        $page = max(1, (int) $request->get_param('page'));
        $listId = $request->get_param('list_id');

        $dir = self::listsDir();

        // ---- First call: no list_id => scan once, persist, return page 1 -------
        if ($listId === null || $listId === '') {
            // Opportunistic cleanup of stale lists from previous runs.
            self::cleanupExpiredLists($dir);

            $config = Config::loadConfigAndFix();
            $flat = self::flattenList(BulkConvert::getList($config));

            $newId = self::generateListId();
            $file = self::listFilePath($dir, $newId);

            if (!self::ensureDir($dir)
                || !FileHelper::atomicPutContents($file, wp_json_encode($flat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ) {
                return new \WP_REST_Response([
                    'success' => false,
                    'msg' => 'Could not persist the conversion list',
                ], 500);
            }

            $slice = self::paginate(count($flat), 1, $perPage);

            return new \WP_REST_Response([
                'success' => true,
                'list_id' => $newId,
                'total' => count($flat),
                'page' => 1,
                'per_page' => $perPage,
                'files' => array_slice($flat, $slice['offset'], $slice['length']),
                'concurrency' => [
                    'recommended' => $advisor->recommendedWebConcurrency(),
                    'max' => self::WEB_MAX,
                ],
                'server_busy' => $advisor->isBusy(),
                'nonce' => self::freshNonce(),
            ], 200);
        }

        // ---- Subsequent call: serve a slice of the persisted list --------------
        if (!self::isValidListId($listId)) {
            return new \WP_REST_Response([
                'success' => false,
                'msg' => 'Invalid list id',
            ], 400);
        }

        $file = self::listFilePath($dir, $listId);
        $flat = self::loadList($file);
        if ($flat === null) {
            return new \WP_REST_Response([
                'success' => false,
                'msg' => 'List not found or expired',
            ], 404);
        }

        $slice = self::paginate(count($flat), $page, $perPage);

        return new \WP_REST_Response([
            'success' => true,
            'list_id' => $listId,
            'total' => count($flat),
            'page' => $page,
            'per_page' => $perPage,
            'files' => array_slice($flat, $slice['offset'], $slice['length']),
            'concurrency' => [
                'recommended' => $advisor->recommendedWebConcurrency(),
                'max' => self::WEB_MAX,
            ],
            'server_busy' => $advisor->isBusy(),
            'nonce' => self::freshNonce(),
        ], 200);
    }

    // =====================================================================
    //  WP-coupled glue (kept thin; not unit-tested)
    // =====================================================================

    /**
     * Wrap a per-file conversion result as a WP_REST_Response, decorating it with
     * the cross-cutting fields every /convert response carries: a fresh 'wp_rest'
     * nonce (so the client can swap its X-WP-Nonce mid-session) and the advisor's
     * busy signal (so it backs off without guessing).
     *
     * @param  array              $result   Result array from the conversion core.
     * @param  ConcurrencyAdvisor $advisor
     * @param  int                $status   HTTP status.
     * @return \WP_REST_Response
     */
    private static function respond($result, $advisor, $status)
    {
        if (!is_array($result)) {
            $result = ['success' => false, 'msg' => 'Unexpected conversion result'];
        }
        $result['server_busy'] = $advisor->isBusy();
        $result['nonce'] = self::freshNonce();
        return new \WP_REST_Response($result, $status);
    }

    /**
     * A fresh 'wp_rest' nonce for the client to adopt. Guarded with
     * function_exists so the class stays loadable in non-WP unit tests (the WP
     * glue methods are simply never exercised there).
     *
     * @return string
     */
    private static function freshNonce()
    {
        if (function_exists('wp_create_nonce')) {
            return wp_create_nonce('wp_rest');
        }
        return '';
    }

    /**
     * Random, non-guessable list id (hex). Prefers wp_generate_password
     * (alnum-restricted via our own hex normalization) but falls back to
     * random_bytes so the helper also works in a non-WP context.
     *
     * @return string  32 hex chars.
     */
    private static function generateListId()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            // Extremely unlikely; fall back to WP's CSPRNG-backed generator.
        } catch (\Error $e) {
        }
        if (function_exists('wp_generate_password')) {
            // Restrict to hex so it always passes isValidListId().
            return substr(hash('sha256', wp_generate_password(64, true, true) . microtime()), 0, 32);
        }
        return substr(hash('sha256', uniqid('', true) . mt_rand()), 0, 32);
    }

    /**
     * Absolute path to the bulk-lists directory under the plugin content dir.
     *
     * @return string
     */
    private static function listsDir()
    {
        return Paths::getMagicConvertContentDirAbs() . '/bulk-lists';
    }

    /**
     * Create $dir (tolerating the concurrent-creation race), returning whether it
     * exists afterwards.
     *
     * @return bool
     */
    private static function ensureDir($dir)
    {
        if (@is_dir($dir)) {
            return true;
        }
        @mkdir($dir, 0775, true);
        return @is_dir($dir);
    }

    /**
     * Load + decode a persisted list file. Returns the flat array, or null when
     * the file is missing / unreadable / not a JSON array.
     *
     * @return array|null
     */
    private static function loadList($file)
    {
        if (!@is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Delete every list file older than LIST_TTL_SECONDS. Best-effort; never
     * throws. Called opportunistically when a new list is created.
     */
    private static function cleanupExpiredLists($dir)
    {
        foreach (self::expiredListFiles($dir, time(), self::LIST_TTL_SECONDS) as $stale) {
            @unlink($stale);
        }
    }

    // =====================================================================
    //  Pure helpers (WordPress-independent; unit-tested)
    // =====================================================================

    /**
     * Compute the [offset, length] window for a 1-based page over $total items.
     *
     * Pages past the end yield a zero-length window at the clamped offset (a
     * valid empty slice, not an error), so a client that over-pages just gets an
     * empty 'files' array and stops.
     *
     * @param  int  $total    Total number of items (>= 0).
     * @param  int  $page     1-based page number (values < 1 are treated as 1).
     * @param  int  $perPage  Items per page (>= 1).
     *
     * @return array{offset:int,length:int}
     */
    public static function paginate($total, $page, $perPage)
    {
        $total = max(0, (int) $total);
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);

        $offset = ($page - 1) * $perPage;
        if ($offset >= $total) {
            // Page entirely beyond the data: empty window at the boundary.
            return ['offset' => $total, 'length' => 0];
        }
        $length = min($perPage, $total - $offset);
        return ['offset' => $offset, 'length' => $length];
    }

    /**
     * Clamp a requested per_page value into [1, MAX_PER_PAGE], defaulting to
     * DEFAULT_PER_PAGE when absent / non-numeric / <= 0.
     *
     * @param  mixed  $raw
     * @return int
     */
    public static function clampPerPage($raw)
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return self::DEFAULT_PER_PAGE;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return self::DEFAULT_PER_PAGE;
        }
        return min($n, self::MAX_PER_PAGE);
    }

    /**
     * Validate a list id as a pure hex token of a sane length. This is the
     * traversal guard for the list-file path: only [0-9a-f] of length 8..64 is
     * accepted, so the id can never contain a slash, dot or NUL and cannot escape
     * the bulk-lists directory.
     *
     * @param  mixed  $listId
     * @return bool
     */
    public static function isValidListId($listId)
    {
        if (!is_string($listId)) {
            return false;
        }
        return (bool) preg_match('/^[0-9a-f]{8,64}$/', $listId);
    }

    /**
     * Derive the absolute path of a list file from its id. Returns null for an
     * invalid id (defense in depth — callers should validate first, but this
     * guarantees a bad id can never produce a path).
     *
     * @param  string  $dir     The bulk-lists directory.
     * @param  string  $listId  A validated hex id.
     * @return string|null
     */
    public static function listFilePath($dir, $listId)
    {
        if (!self::isValidListId($listId)) {
            return null;
        }
        return rtrim($dir, '/') . '/' . $listId . '.json';
    }

    /**
     * Enumerate list files in $dir whose mtime is older than $ttl seconds
     * relative to $now. Pure (takes $now/$ttl as params) so it can be unit-tested
     * deterministically. Only considers files matching the *.json id pattern, so
     * it never touches anything but its own list files.
     *
     * @param  string  $dir  Directory to scan.
     * @param  int     $now  Reference timestamp.
     * @param  int     $ttl  Max age in seconds.
     * @return string[]  Absolute paths of expired list files.
     */
    public static function expiredListFiles($dir, $now, $ttl)
    {
        $expired = [];
        if (!@is_dir($dir)) {
            return $expired;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return $expired;
        }
        foreach ($entries as $entry) {
            if (!preg_match('/^[0-9a-f]{8,64}\.json$/', $entry)) {
                continue;
            }
            $path = rtrim($dir, '/') . '/' . $entry;
            $mtime = @filemtime($path);
            if ($mtime === false) {
                continue;
            }
            if (($now - $mtime) > $ttl) {
                $expired[] = $path;
            }
        }
        return $expired;
    }

    /**
     * Flatten the grouped {groupName, root, files[]} structure returned by
     * BulkConvert::getList into a single flat list of {root, path} pairs the
     * REST/JS pool consumes directly. Pure transform; unit-tested.
     *
     * @param  array  $groups
     * @return array  List of ['root' => <imageRootId>, 'path' => <relPath>].
     */
    public static function flattenList($groups)
    {
        $flat = [];
        if (!is_array($groups)) {
            return $flat;
        }
        foreach ($groups as $group) {
            if (!isset($group['files']) || !is_array($group['files'])) {
                continue;
            }
            $rootId = isset($group['groupName']) ? $group['groupName'] : '';
            foreach ($group['files'] as $relPath) {
                $flat[] = [
                    'root' => $rootId,
                    'path' => $relPath,
                ];
            }
        }
        return $flat;
    }

    /**
     * Interpret a REST param as a boolean. Accepts real bools, the strings
     * "1"/"true"/"yes"/"on" (case-insensitive) and numeric truthiness.
     *
     * @param  mixed  $value
     * @return bool
     */
    public static function truthy($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((float) $value) != 0.0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }
}
