<?php

namespace MagicConvert\Tests;

/**
 * Dev-only nginx test harness (NOT shipped behaviour).
 *
 * Wraps generated NginxRules artifacts in a minimal nginx.conf and runs
 * `nginx -t` (syntax validation) and, for the functional test, an actual
 * `nginx` boot against a temp prefix + docroot. Everything lives under a
 * unique temp prefix dir so multiple runs don't collide, and the prefix is
 * recursively removed on cleanup.
 *
 * Portability: locateBinary() returns null when no nginx is found, so PHPUnit
 * tests SKIP on CI rather than fail.
 */
class NginxHarness
{
    /** @var string */
    public $prefix;

    /** @var string */
    public $docroot;

    /** @var string */
    public $confPath;

    /** @var string|null */
    private $binary;

    /** @var int */
    public $port;

    public function __construct($port = 18999)
    {
        $this->port = $port;
        $this->binary = self::locateBinary();
        $this->prefix = self::makeTempDir('mc-nginx-prefix-');
        $this->docroot = $this->prefix . '/docroot';
        @mkdir($this->docroot, 0777, true);
        // nginx needs these dirs to exist for some directives / temp paths.
        foreach (['logs', 'client_body_temp', 'proxy_temp', 'fastcgi_temp', 'uwsgi_temp', 'scgi_temp'] as $d) {
            @mkdir($this->prefix . '/' . $d, 0777, true);
        }
        $this->confPath = $this->prefix . '/nginx.conf';
    }

    /**
     * Find an nginx binary. Tries the known homebrew path first, then PATH.
     *
     * @return string|null
     */
    public static function locateBinary()
    {
        $candidates = ['/opt/homebrew/bin/nginx', '/usr/local/bin/nginx', '/usr/sbin/nginx'];
        foreach ($candidates as $c) {
            if (is_executable($c)) {
                return $c;
            }
        }
        // PATH lookup
        $which = @shell_exec('command -v nginx 2>/dev/null');
        if (is_string($which)) {
            $which = trim($which);
            if ($which !== '' && is_executable($which)) {
                return $which;
            }
        }
        return null;
    }

    /** @return bool */
    public function available()
    {
        return $this->binary !== null;
    }

    /**
     * Write the maps file and server file into the prefix and build a minimal nginx.conf that
     * includes them.
     *
     * @param  string  $mapsBody    maps file body (http context) — or '' for single-file mode.
     * @param  string  $serverBody  server file body (server context).
     */
    public function writeConf($mapsBody, $serverBody)
    {
        $mapsPath = $this->prefix . '/mc-maps.conf';
        $serverPath = $this->prefix . '/mc-server.conf';
        file_put_contents($mapsPath, $mapsBody);
        file_put_contents($serverPath, $serverBody);

        $httpIncludes = ($mapsBody !== '') ? ("    include " . $mapsPath . ";\n") : '';

        $conf =
            "worker_processes 1;\n" .
            "pid " . $this->prefix . "/nginx.pid;\n" .
            "error_log " . $this->prefix . "/logs/error.log;\n" .
            "events { worker_connections 16; }\n" .
            "http {\n" .
            "    access_log off;\n" .
            "    client_body_temp_path " . $this->prefix . "/client_body_temp;\n" .
            "    proxy_temp_path " . $this->prefix . "/proxy_temp;\n" .
            "    fastcgi_temp_path " . $this->prefix . "/fastcgi_temp;\n" .
            "    uwsgi_temp_path " . $this->prefix . "/uwsgi_temp;\n" .
            "    scgi_temp_path " . $this->prefix . "/scgi_temp;\n" .
            $httpIncludes .
            "    server {\n" .
            "        listen 127.0.0.1:" . $this->port . ";\n" .
            "        server_name localhost;\n" .
            "        root " . $this->docroot . ";\n" .
            "        include " . $serverPath . ";\n" .
            "    }\n" .
            "}\n";
        file_put_contents($this->confPath, $conf);
    }

    /**
     * Run `nginx -t` against the written conf. Returns [exitCode, combinedOutput].
     *
     * @return array{0:int,1:string}
     */
    public function test()
    {
        $cmd = escapeshellarg($this->binary) .
            ' -t -p ' . escapeshellarg($this->prefix . '/') .
            ' -c ' . escapeshellarg($this->confPath) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        return [$code, implode("\n", $output)];
    }

    /**
     * Boot nginx in the background (daemon on). Returns true on success.
     *
     * @return bool
     */
    public function start()
    {
        $cmd = escapeshellarg($this->binary) .
            ' -p ' . escapeshellarg($this->prefix . '/') .
            ' -c ' . escapeshellarg($this->confPath) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            return false;
        }
        // Wait for the pidfile + port to come up.
        for ($i = 0; $i < 50; $i++) {
            if ($this->isUp()) {
                return true;
            }
            usleep(100000); // 100ms
        }
        return false;
    }

    /** @return bool */
    public function isUp()
    {
        $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /**
     * HTTP GET against the running server with a given Accept header. Returns
     * [statusLine, headers(assoc lowercased), body].
     *
     * @param  string  $path
     * @param  string  $accept
     * @return array{0:int,1:array,2:string}
     */
    public function get($path, $accept)
    {
        $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 2.0);
        if (!$fp) {
            return [0, [], ''];
        }
        $req =
            "GET " . $path . " HTTP/1.0\r\n" .
            "Host: localhost\r\n" .
            "Accept: " . $accept . "\r\n" .
            "Connection: close\r\n\r\n";
        fwrite($fp, $req);
        $raw = '';
        while (!feof($fp)) {
            $raw .= fread($fp, 8192);
        }
        fclose($fp);

        $split = explode("\r\n\r\n", $raw, 2);
        $headerBlock = $split[0];
        $body = isset($split[1]) ? $split[1] : '';

        $lines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($lines);
        $status = 0;
        if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $statusLine, $mm)) {
            $status = (int) $mm[1];
        }
        $headers = [];
        foreach ($lines as $line) {
            $kv = explode(':', $line, 2);
            if (count($kv) === 2) {
                $headers[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
        }
        return [$status, $headers, $body];
    }

    /** Stop nginx by pidfile (failure-safe). */
    public function stop()
    {
        if ($this->binary !== null && is_file($this->confPath)) {
            $cmd = escapeshellarg($this->binary) .
                ' -s stop -p ' . escapeshellarg($this->prefix . '/') .
                ' -c ' . escapeshellarg($this->confPath) . ' 2>/dev/null';
            @exec($cmd);
        }
        // Belt-and-braces: kill by pidfile.
        $pidFile = $this->prefix . '/nginx.pid';
        if (is_file($pidFile)) {
            $pid = (int) trim(@file_get_contents($pidFile));
            if ($pid > 0) {
                @exec('kill ' . $pid . ' 2>/dev/null');
            }
        }
    }

    /** Recursively remove the temp prefix (failure-safe). */
    public function cleanup()
    {
        $this->stop();
        // give nginx a moment to release the pid
        usleep(150000);
        self::rrmdir($this->prefix);
    }

    // --- helpers ---

    private static function makeTempDir($prefix)
    {
        $base = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        @mkdir($base, 0777, true);
        return $base;
    }

    private static function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
