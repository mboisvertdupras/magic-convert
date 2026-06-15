<?php

namespace MagicConvert;

class ConcurrencyAdvisor
{
    const BUSY_LOAD_PER_CORE = 1.5;

    const FALLBACK_CORES = 2;

    const WEB_MAX = 8;

    const CLI_MAX = 8;

    private $coresOverride;

    private $loadOverride;

    private $detectedCores = null;

    /**
     * @param int|null   $cores
     * @param float|null $load
     */
    public function __construct($cores = null, $load = null)
    {
        $this->coresOverride = $cores;
        $this->loadOverride = $load;
    }

    public function cpuCoreCount()
    {
        if ($this->coresOverride !== null) {
            return max(1, (int) $this->coresOverride);
        }
        if ($this->detectedCores !== null) {
            return $this->detectedCores;
        }

        $cores = self::detectCpuCoreCount();
        $this->detectedCores = max(1, (int) $cores);
        return $this->detectedCores;
    }

    public static function detectCpuCoreCount()
    {
        $n = self::intFromExec('nproc 2>/dev/null');
        if ($n > 0) {
            return $n;
        }

        $n = self::intFromExec('sysctl -n hw.ncpu 2>/dev/null');
        if ($n > 0) {
            return $n;
        }

        if (@is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if (is_string($cpuinfo) && $cpuinfo !== '') {
                $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        return self::FALLBACK_CORES;
    }

    /**
     * @return float|null
     */
    public function loadPerCore()
    {
        $load = $this->oneMinuteLoad();
        if ($load === null) {
            return null;
        }
        $cores = $this->cpuCoreCount();
        if ($cores < 1) {
            $cores = 1;
        }
        return $load / $cores;
    }

    /**
     * @return float|null
     */
    private function oneMinuteLoad()
    {
        if ($this->loadOverride !== null) {
            return (float) $this->loadOverride;
        }
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $avg = @sys_getloadavg();
        if (!is_array($avg) || !isset($avg[0]) || !is_numeric($avg[0])) {
            return null;
        }
        return (float) $avg[0];
    }

    public function isBusy()
    {
        $perCore = $this->loadPerCore();
        if ($perCore === null) {
            return false;
        }
        return $perCore > self::BUSY_LOAD_PER_CORE;
    }

    public function recommendedWebConcurrency()
    {
        return $this->recommendedWebConcurrencyForFormat('avif');
    }

    public function recommendedWebConcurrencyForFormat($formatId)
    {
        $weight = ($formatId === 'avif') ? 2 : 1;
        $cores = $this->cpuCoreCount();
        return self::clamp((int) floor($cores / $weight), 1, self::WEB_MAX);
    }

    /**
     * @param  string[]  $formatIds
     * @return array<string,int>
     */
    public function webTargets(array $formatIds)
    {
        $targets = [];
        foreach ($formatIds as $formatId) {
            $targets[(string) $formatId] = $this->recommendedWebConcurrencyForFormat((string) $formatId);
        }
        return $targets;
    }

    public function recommendedCliProcs()
    {
        $cores = $this->cpuCoreCount();
        $procs = self::clamp($cores - 1, 1, self::CLI_MAX);

        if ($this->isBusy()) {
            $procs = (int) floor($procs / 2);
            $procs = max(1, $procs);
        }
        return $procs;
    }

    private static function clamp($value, $min, $max)
    {
        $value = (int) $value;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private static function intFromExec($command)
    {
        if (!function_exists('exec')) {
            return 0;
        }
        $output = [];
        $status = null;
        @exec($command, $output, $status);
        if (!is_array($output) || count($output) === 0) {
            return 0;
        }
        $first = trim((string) $output[0]);
        if ($first === '' || !ctype_digit($first)) {
            return 0;
        }
        return (int) $first;
    }
}
