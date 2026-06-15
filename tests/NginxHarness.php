<?php

namespace MagicConvert\Tests;

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
        foreach (['logs', 'client_body_temp', 'proxy_temp', 'fastcgi_temp', 'uwsgi_temp', 'scgi_temp'] as $d) {
            @mkdir($this->prefix . '/' . $d, 0777, true);
        }
        $this->confPath = $this->prefix . '/nginx.conf';
    }

    /**
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
     * @param  string  $mapsBody
     * @param  string  $serverBody
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
        for ($i = 0; $i < 50; $i++) {
            if ($this->isUp()) {
                return true;
            }
            usleep(100000);
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

    public function stop()
    {
        if ($this->binary !== null && is_file($this->confPath)) {
            $cmd = escapeshellarg($this->binary) .
                ' -s stop -p ' . escapeshellarg($this->prefix . '/') .
                ' -c ' . escapeshellarg($this->confPath) . ' 2>/dev/null';
            @exec($cmd);
        }
        $pidFile = $this->prefix . '/nginx.pid';
        if (is_file($pidFile)) {
            $pid = (int) trim(@file_get_contents($pidFile));
            if ($pid > 0) {
                @exec('kill ' . $pid . ' 2>/dev/null');
            }
        }
    }

    public function cleanup()
    {
        $this->stop();
        usleep(150000);
        self::rrmdir($this->prefix);
    }

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
