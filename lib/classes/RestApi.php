<?php

namespace MagicConvert;

use \MagicConvert\Convert;
use \MagicConvert\ConcurrencyAdvisor;
use \MagicConvert\Config;
use \MagicConvert\BulkConvert;
use \MagicConvert\FileHelper;
use \MagicConvert\OutputFormat;
use \MagicConvert\Paths;
use \MagicConvert\SanityException;

class RestApi
{
    const NAMESPACE = 'magic-convert/v1';

    const DEFAULT_PER_PAGE = 500;

    const MAX_PER_PAGE = 1000;

    const WEB_MAX = ConcurrencyAdvisor::WEB_MAX;

    const LIST_TTL_SECONDS = 86400;

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
     * @return bool
     */
    public static function permissionCheck()
    {
        return current_user_can('manage_options');
    }

    /**
     * @param  \WP_REST_Request  $request
     * @return \WP_REST_Response
     */
    public static function convertCallback($request)
    {
        $requestStart = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        $root = (string) $request->get_param('root');
        $path = (string) $request->get_param('path');
        $reconvert = self::truthy($request->get_param('reconvert'));

        $advisor = new ConcurrencyAdvisor();

        $config = Config::loadConfigAndFix();
        $formatId = self::resolveRequestedFormat(
            $request->get_param('format'),
            Config::enabledFormatIds($config)
        );
        if ($formatId === null) {
            return self::respond([
                'success' => false,
                'msg' => 'Invalid or disabled format',
                'log' => '',
            ], $advisor, 400, $requestStart);
        }

        try {
            $source = Convert::resolveImageSourcePath($root, $path);
        } catch (SanityException $e) {
            return self::respond([
                'success' => false,
                'msg' => 'Invalid source: ' . $e->getMessage(),
                'log' => '',
                'format' => $formatId,
            ], $advisor, 400, $requestStart);
        } catch (\Exception $e) {
            return self::respond([
                'success' => false,
                'msg' => 'Invalid source',
                'log' => '',
                'format' => $formatId,
            ], $advisor, 400, $requestStart);
        }

        $skipIfFresh = !$reconvert;

        $result = Convert::runConversion($source, null, null, $skipIfFresh, $formatId);
        if (is_array($result)) {
            $result['format'] = $formatId;
        }

        return self::respond($result, $advisor, 200, $requestStart);
    }

    /**
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

        if ($listId === null || $listId === '') {
            self::cleanupExpiredLists($dir);

            $config = Config::loadConfigAndFix();
            $enabledFormats = Config::enabledFormatIds($config);
            $flat = self::flattenList(BulkConvert::getList($config), $enabledFormats);

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
                'formats' => $enabledFormats,
                'format_totals' => self::formatTotals($flat, $enabledFormats),
                'page' => 1,
                'per_page' => $perPage,
                'files' => array_slice($flat, $slice['offset'], $slice['length']),
                'concurrency' => [
                    'recommended' => $advisor->recommendedWebConcurrency(),
                    'max' => self::WEB_MAX,
                    'cores' => $advisor->cpuCoreCount(),
                    'targets' => $advisor->webTargets($enabledFormats),
                ],
                'server_busy' => $advisor->isBusy(),
                'nonce' => self::freshNonce(),
            ], 200);
        }

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

        $listFormats = self::formatsInList($flat);

        return new \WP_REST_Response([
            'success' => true,
            'list_id' => $listId,
            'total' => count($flat),
            'formats' => $listFormats,
            'format_totals' => self::formatTotals($flat, $listFormats),
            'page' => $page,
            'per_page' => $perPage,
            'files' => array_slice($flat, $slice['offset'], $slice['length']),
            'concurrency' => [
                'recommended' => $advisor->recommendedWebConcurrency(),
                'max' => self::WEB_MAX,
                'cores' => $advisor->cpuCoreCount(),
                'targets' => $advisor->webTargets($listFormats),
            ],
            'server_busy' => $advisor->isBusy(),
            'nonce' => self::freshNonce(),
        ], 200);
    }

    /**
     * @param  array              $result
     * @param  ConcurrencyAdvisor $advisor
     * @param  int                $status
     * @param  float|null         $requestStart
     * @return \WP_REST_Response
     */
    private static function respond($result, $advisor, $status, $requestStart = null)
    {
        if (!is_array($result)) {
            $result = ['success' => false, 'msg' => 'Unexpected conversion result'];
        }
        $result['server_busy'] = $advisor->isBusy();
        if ($requestStart !== null) {
            $result['service_ms'] = round((microtime(true) - (float) $requestStart) * 1000, 1);
        }
        $result['nonce'] = self::freshNonce();
        return new \WP_REST_Response($result, $status);
    }

    /**
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
     * @return string
     */
    private static function generateListId()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $e) {
        } catch (\Error $e) {
        }
        if (function_exists('wp_generate_password')) {
            return substr(hash('sha256', wp_generate_password(64, true, true) . microtime()), 0, 32);
        }
        return substr(hash('sha256', uniqid('', true) . mt_rand()), 0, 32);
    }

    /**
     * @return string
     */
    private static function listsDir()
    {
        return Paths::getMagicConvertContentDirAbs() . '/bulk-lists';
    }

    /**
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

    private static function cleanupExpiredLists($dir)
    {
        foreach (self::expiredListFiles($dir, time(), self::LIST_TTL_SECONDS) as $stale) {
            @unlink($stale);
        }
    }

    /**
     * @param  int  $total
     * @param  int  $page
     * @param  int  $perPage
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
            return ['offset' => $total, 'length' => 0];
        }
        $length = min($perPage, $total - $offset);
        return ['offset' => $offset, 'length' => $length];
    }

    /**
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
     * @param  string  $dir
     * @param  string  $listId
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
     * @param  string  $dir
     * @param  int     $now
     * @param  int     $ttl
     * @return string[]
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
     * @param  array          $groups
     * @param  string[]|null  $enabledFormats
     * @return array
     */
    public static function flattenList($groups, $enabledFormats = null)
    {
        $flat = [];
        if (!is_array($groups)) {
            return $flat;
        }
        if (!is_array($enabledFormats) || empty($enabledFormats)) {
            $enabledFormats = [OutputFormat::DEFAULT_ID];
        }
        foreach ($groups as $group) {
            if (!isset($group['files']) || !is_array($group['files'])) {
                continue;
            }
            $rootId = isset($group['groupName']) ? $group['groupName'] : '';
            foreach ($group['files'] as $file) {
                if (is_array($file)) {
                    $relPath = isset($file['path']) ? $file['path'] : '';
                    $formats = (isset($file['formats']) && is_array($file['formats']) && !empty($file['formats']))
                        ? array_values($file['formats'])
                        : $enabledFormats;
                } else {
                    $relPath = $file;
                    $formats = $enabledFormats;
                }
                $flat[] = [
                    'root' => $rootId,
                    'path' => $relPath,
                    'formats' => $formats,
                ];
            }
        }
        return $flat;
    }

    /**
     * @param  array     $flat
     * @param  string[]  $enabledFormats
     * @return array<string,int>
     */
    public static function formatTotals($flat, $enabledFormats)
    {
        $totals = [];
        foreach ($enabledFormats as $formatId) {
            $totals[$formatId] = 0;
        }
        if (!is_array($flat)) {
            return $totals;
        }
        foreach ($flat as $item) {
            if (!isset($item['formats']) || !is_array($item['formats'])) {
                continue;
            }
            foreach ($item['formats'] as $formatId) {
                if (array_key_exists($formatId, $totals)) {
                    $totals[$formatId]++;
                }
            }
        }
        return $totals;
    }

    /**
     * @param  array  $flat
     * @return string[]
     */
    public static function formatsInList($flat)
    {
        $seen = [];
        if (is_array($flat)) {
            foreach ($flat as $item) {
                if (isset($item['formats']) && is_array($item['formats'])) {
                    foreach ($item['formats'] as $formatId) {
                        $seen[$formatId] = true;
                    }
                }
            }
        }
        $ordered = [];
        foreach (OutputFormat::ids() as $id) {
            if (isset($seen[$id])) {
                $ordered[] = $id;
            }
        }
        if (empty($ordered)) {
            $ordered[] = OutputFormat::DEFAULT_ID;
        }
        return $ordered;
    }

    /**
     * @param  mixed     $raw
     * @param  string[]  $enabledFormats
     * @return string|null
     */
    public static function resolveRequestedFormat($raw, $enabledFormats)
    {
        if ($raw === null || $raw === '') {
            return OutputFormat::DEFAULT_ID;
        }
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }
        $id = strtolower(trim((string) $raw));

        if (!in_array($id, OutputFormat::ids(), true)) {
            return null;
        }
        if (!in_array($id, $enabledFormats, true)) {
            return null;
        }
        return $id;
    }

    /**
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
