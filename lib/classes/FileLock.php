<?php

namespace MagicConvert;

class FileLock
{
    const DEFAULT_STALE_SECONDS = 600;

    public static function acquire($lockPath, $staleSeconds = self::DEFAULT_STALE_SECONDS)
    {
        if (!self::ensureParentDir($lockPath)) {
            return false;
        }

        $token = self::newToken();

        if (self::tryCreate($lockPath, $token)) {
            return $token;
        }

        if (self::isStale($lockPath, $staleSeconds)) {
            @unlink($lockPath);
            if (self::tryCreate($lockPath, $token)) {
                return $token;
            }
        }

        return false;
    }

    public static function release($lockPath, $token)
    {
        if (!is_string($token) || $token === '') {
            return;
        }
        if (!@is_file($lockPath)) {
            return;
        }
        if (self::tokenOf($lockPath) !== $token) {
            return;
        }
        @unlink($lockPath);
    }

    private static function tryCreate($lockPath, $token)
    {
        $handle = @fopen($lockPath, 'x');
        if ($handle === false) {
            return false;
        }

        @fwrite($handle, self::ownerPayload($token));
        @fclose($handle);
        return true;
    }

    private static function newToken()
    {
        $pid = function_exists('getmypid') ? (int) getmypid() : 0;

        $rand = '';
        if (function_exists('random_bytes')) {
            try {
                $rand = bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                $rand = '';
            } catch (\Error $e) {
                $rand = '';
            }
        }
        if ($rand === '') {
            $rand = uniqid('', true) . '.' . mt_rand();
        }

        return $pid . '-' . $rand;
    }

    private static function tokenOf($lockPath)
    {
        $raw = @file_get_contents($lockPath);
        if ($raw === false || $raw === '') {
            return '';
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['token']) || !is_string($payload['token'])) {
            return '';
        }
        return $payload['token'];
    }

    private static function ownerPayload($token)
    {
        $pid = function_exists('getmypid') ? getmypid() : 0;
        return json_encode([
            'token' => $token,
            'pid' => $pid,
            'time' => time(),
            'created' => date('c'),
        ]);
    }

    private static function isStale($lockPath, $staleSeconds)
    {
        if (!@is_file($lockPath)) {
            return false;
        }
        $mtime = @filemtime($lockPath);
        if ($mtime === false) {
            return false;
        }
        return (time() - $mtime) > $staleSeconds;
    }

    private static function ensureParentDir($lockPath)
    {
        $dir = self::dirName($lockPath);
        if ($dir === '' || @is_dir($dir)) {
            return true;
        }
        @mkdir($dir, 0775, true);
        return @is_dir($dir);
    }

    private static function dirName($path)
    {
        return preg_replace('/[\/\\\\][^\/\\\\]*$/', '', $path);
    }
}
