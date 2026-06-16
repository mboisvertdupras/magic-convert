<?php

namespace MagicConvert\Avif;

use MagicConvert\PhpCliLocator;

class AvifSubprocessRunner
{
    const TIMEOUT_SECONDS = 120;

    const EXIT_ENCODE_FAILED = 1;

    const EXIT_UNKNOWN_ID = 2;

    const EXIT_NOT_OPERATIONAL = 3;

    /** @var bool|null */
    private $available = null;

    /** @var string|null */
    private $php = null;

    /**
     * @return string
     */
    public function workerScriptPath()
    {
        if (defined('MAGIC_CONVERT_PLUGIN_DIR')) {
            return constant('MAGIC_CONVERT_PLUGIN_DIR') . '/wod/avif-encode-worker.php';
        }
        return dirname(__DIR__, 3) . '/wod/avif-encode-worker.php';
    }

    /**
     * @return bool
     */
    public function isAvailable()
    {
        if ($this->available !== null) {
            return $this->available;
        }
        if (!PhpCliLocator::canSpawn() || !@is_file($this->workerScriptPath())) {
            return $this->available = false;
        }
        $this->php = PhpCliLocator::locate();
        return $this->available = ($this->php !== null);
    }

    /**
     * @param  string    $php
     * @param  string    $script
     * @param  string    $source
     * @param  string    $destination
     * @param  string    $converterId
     * @param  array     $options
     * @return string[]
     */
    public static function buildCommandArgv($php, $script, $source, $destination, $converterId, array $options)
    {
        return [
            $php,
            $script,
            $source,
            $destination,
            $converterId,
            base64_encode((string) json_encode($options)),
        ];
    }

    /**
     * @param  AbstractAvifConverter  $converter
     * @param  string                 $source
     * @param  string                 $destination
     * @param  array                  $options
     * @return bool
     * @throws \Exception
     */
    public function run(AbstractAvifConverter $converter, $source, $destination, array $options)
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $argv = self::buildCommandArgv(
            $this->php,
            $this->workerScriptPath(),
            $source,
            $destination,
            $converter->id(),
            $options
        );

        list($code, $stderr) = $this->execProcess($argv, self::TIMEOUT_SECONDS);

        if ($code === 0) {
            return true;
        }
        if ($code === null || $code === self::EXIT_NOT_OPERATIONAL || $code === self::EXIT_UNKNOWN_ID) {
            return false;
        }

        throw new \Exception(
            'isolated encode failed (' . $converter->id() . '): ' . trim($stderr)
        );
    }

    /**
     * @param  string[]  $argv
     * @param  int       $timeoutSeconds
     * @return array{0:int|null,1:string}
     */
    private function execProcess(array $argv, $timeoutSeconds)
    {
        $cmd = implode(' ', array_map('escapeshellarg', $argv));
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [null, ''];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderr = '';
        $deadline = time() + $timeoutSeconds;
        $exitCode = null;
        while (true) {
            $status = proc_get_status($proc);
            $stderr .= (string) stream_get_contents($pipes[2]);
            stream_get_contents($pipes[1]);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (time() >= $deadline) {
                @proc_terminate($proc, 9);
                $exitCode = 124;
                break;
            }
            usleep(20000);
        }

        $stderr .= (string) stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($proc);

        return [is_int($exitCode) ? $exitCode : null, $stderr];
    }
}
