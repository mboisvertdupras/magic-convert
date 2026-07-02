<?php

namespace MagicConvert;

use MagicConvert\Format\ProviderRegistry;

class ConcurrencyAdvisor
{
    const BUSY_LOAD_PER_CORE = 1.5;

    const FALLBACK_CORES = 2;

    const WEB_MAX = 8;

    const CLI_MAX = 8;

    const MEMORY_USABLE_PERCENT = 75;

    private $coresOverride;

    private $loadOverride;

    private $memoryOverride;

    private $detectedCores = null;

    /**
     * @param int|null   $cores
     * @param float|null $load
     * @param int|null   $availableMemoryBytes
     */
    public function __construct($cores = null, $load = null, $availableMemoryBytes = null)
    {
        $this->coresOverride = $cores;
        $this->loadOverride = $load;
        $this->memoryOverride = $availableMemoryBytes;
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
     * @return int|null
     */
    public function availableMemoryBytes()
    {
        if ($this->memoryOverride !== null) {
            return max(0, (int) $this->memoryOverride);
        }
        return self::detectAvailableMemoryBytes();
    }

    /**
     * @return int|null
     */
    public static function detectAvailableMemoryBytes()
    {
        $candidates = [];

        if (@is_readable('/proc/meminfo')) {
            $host = self::parseMemAvailableBytes((string) @file_get_contents('/proc/meminfo'));
            if ($host !== null) {
                $candidates[] = $host;
            }
        }

        $cgroup = self::detectCgroupAvailableBytes();
        if ($cgroup !== null) {
            $candidates[] = $cgroup;
        }

        if (empty($candidates)) {
            return null;
        }
        return min($candidates);
    }

    /**
     * @param  string  $meminfo
     * @return int|null
     */
    public static function parseMemAvailableBytes($meminfo)
    {
        if (!is_string($meminfo) || $meminfo === '') {
            return null;
        }
        if (preg_match('/^MemAvailable:\s+(\d+)\s*kB/mi', $meminfo, $m)) {
            return (int) $m[1] * 1024;
        }
        return null;
    }

    /**
     * @return int|null
     */
    private static function detectCgroupAvailableBytes()
    {
        $limit = null;
        $usage = null;

        if (@is_readable('/sys/fs/cgroup/memory.max')) {
            $limit = self::parseCgroupLimitBytes((string) @file_get_contents('/sys/fs/cgroup/memory.max'));
            $usage = self::parseCgroupLimitBytes((string) @file_get_contents('/sys/fs/cgroup/memory.current'));
        } elseif (@is_readable('/sys/fs/cgroup/memory/memory.limit_in_bytes')) {
            $limit = self::parseCgroupLimitBytes((string) @file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes'));
            $usage = self::parseCgroupLimitBytes((string) @file_get_contents('/sys/fs/cgroup/memory/memory.usage_in_bytes'));
        }

        if ($limit === null) {
            return null;
        }
        if ($usage === null || $usage > $limit) {
            return $limit;
        }
        return $limit - $usage;
    }

    /**
     * @param  string  $raw
     * @return int|null
     */
    public static function parseCgroupLimitBytes($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strtolower($raw) === 'max') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }
        $value = (int) $raw;
        if ($value <= 0 || $value >= PHP_INT_MAX) {
            return null;
        }
        return $value;
    }

    /**
     * @param  string  $formatId
     * @return int
     */
    public static function reserveBytesForFormat($formatId)
    {
        return ProviderRegistry::byId((string) $formatId)->memoryReserveBytes();
    }

    /**
     * @return string
     */
    private static function heaviestFormatId()
    {
        $heaviestId = null;
        $heaviestBytes = -1;
        foreach (ProviderRegistry::all() as $id => $provider) {
            $bytes = $provider->memoryReserveBytes();
            if ($bytes > $heaviestBytes) {
                $heaviestBytes = $bytes;
                $heaviestId = (string) $id;
            }
        }
        return (string) $heaviestId;
    }

    /**
     * @param  int|null  $availableBytes
     * @param  int       $reserveBytes
     * @return int|null
     */
    public static function memoryBudget($availableBytes, $reserveBytes)
    {
        if ($availableBytes === null) {
            return null;
        }
        $reserveBytes = (int) $reserveBytes;
        if ($reserveBytes < 1) {
            $reserveBytes = 1;
        }
        $usable = intdiv((int) $availableBytes * self::MEMORY_USABLE_PERCENT, 100);
        return max(1, intdiv($usable, $reserveBytes));
    }

    /**
     * @param  string    $formatId
     * @param  int       $cores
     * @param  int|null  $availableBytes
     * @param  int       $max
     * @return int
     */
    public static function concurrencyForFormat($formatId, $cores, $availableBytes, $max)
    {
        $provider = ProviderRegistry::byId((string) $formatId);
        $weight = max(1, $provider->concurrencyWeight());
        $cpu = (int) floor(max(1, (int) $cores) / $weight);

        $budget = self::memoryBudget($availableBytes, $provider->memoryReserveBytes());
        $n = ($budget === null) ? $cpu : min($cpu, $budget);

        return self::clamp($n, 1, $max);
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
        return $this->recommendedWebConcurrencyForFormat(self::heaviestFormatId());
    }

    public function recommendedWebConcurrencyForFormat($formatId)
    {
        return self::concurrencyForFormat(
            (string) $formatId,
            $this->cpuCoreCount(),
            $this->availableMemoryBytes(),
            self::WEB_MAX
        );
    }

    /**
     * @param  string[]  $formatIds
     * @return int
     */
    public function webHardCeiling(array $formatIds)
    {
        $ceiling = 1;
        foreach ($formatIds as $formatId) {
            $ceiling = max($ceiling, $this->recommendedWebConcurrencyForFormat((string) $formatId));
        }
        return $ceiling;
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

        $budget = self::memoryBudget($this->availableMemoryBytes(), self::reserveBytesForFormat(self::heaviestFormatId()));
        if ($budget !== null) {
            $procs = min($procs, $budget);
        }

        return max(1, $procs);
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
