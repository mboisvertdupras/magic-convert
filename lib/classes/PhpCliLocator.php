<?php

namespace MagicConvert;

class PhpCliLocator
{
    const OVERRIDE = 'MAGIC_CONVERT_PHP_CLI';

    /** @var bool */
    private static $cached = false;

    /** @var string|null */
    private static $path = null;

    /**
     * @return string|null
     */
    public static function locate()
    {
        if (self::$cached) {
            return self::$path;
        }
        self::$cached = true;
        self::$path = self::discover();
        return self::$path;
    }

    public static function reset()
    {
        self::$cached = false;
        self::$path = null;
    }

    /**
     * @return string|null
     */
    private static function discover()
    {
        $override = self::overridePath();
        if ($override !== null && self::verify($override)) {
            return $override;
        }

        if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '' && self::verify(PHP_BINARY)) {
            return PHP_BINARY;
        }

        foreach (self::candidatePaths() as $candidate) {
            if (@is_file($candidate) && self::verify($candidate)) {
                return $candidate;
            }
        }

        foreach (self::candidateNames() as $name) {
            if (self::verify($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return string|null
     */
    public static function overridePath()
    {
        if (defined(self::OVERRIDE) && is_string(constant(self::OVERRIDE)) && constant(self::OVERRIDE) !== '') {
            return constant(self::OVERRIDE);
        }
        $env = getenv(self::OVERRIDE);
        return (is_string($env) && $env !== '') ? $env : null;
    }

    /**
     * @return string[]
     */
    public static function candidateNames()
    {
        $names = [];
        if (defined('PHP_MAJOR_VERSION') && defined('PHP_MINOR_VERSION')) {
            $names[] = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $names[] = 'php' . PHP_MAJOR_VERSION;
        }
        $names[] = 'php';
        return $names;
    }

    /**
     * @return string[]
     */
    public static function standardBinDirs()
    {
        return ['/usr/local/bin', '/usr/bin', '/bin', '/usr/local/sbin', '/opt/bin', '/snap/bin'];
    }

    /**
     * @return string[]
     */
    public static function candidatePaths()
    {
        $dirs = [];
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $dirs[] = PHP_BINDIR;
        }
        foreach (self::standardBinDirs() as $dir) {
            $dirs[] = $dir;
        }

        $paths = [];
        $seen = [];
        foreach ($dirs as $dir) {
            foreach (self::candidateNames() as $name) {
                $candidate = rtrim($dir, '/') . '/' . $name;
                if (!isset($seen[$candidate])) {
                    $seen[$candidate] = true;
                    $paths[] = $candidate;
                }
            }
        }
        return $paths;
    }

    /**
     * @return bool
     */
    public static function canSpawn()
    {
        return function_exists('proc_open') && !self::isDisabled('proc_open');
    }

    /**
     * @param  string  $candidate
     * @return bool
     */
    private static function verify($candidate)
    {
        if (!self::canSpawn()) {
            return false;
        }
        $cmd = implode(' ', array_map('escapeshellarg', [$candidate, '-r', 'echo PHP_SAPI;']));
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        $stdout = stream_get_contents($pipes[1]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $code = proc_close($proc);
        return $code === 0 && trim((string) $stdout) === 'cli';
    }

    /**
     * @param  string  $fn
     * @return bool
     */
    private static function isDisabled($fn)
    {
        $disabled = @ini_get('disable_functions');
        if (!is_string($disabled) || $disabled === '') {
            return false;
        }
        return in_array($fn, array_map('trim', explode(',', $disabled)), true);
    }
}
