<?php

namespace MagicConvert;

/**
 * Resource-aware concurrency advisor — the single source of truth for "how many
 * things should we do at once" across Magic Convert.
 *
 * The design principle for the whole parallel-bulk feature is that ordinary
 * WordPress users should never have to think about parallelism: the system
 * detects the server's resources and adapts automatically. This class is where
 * that detection and the recommendation policy live. It is consumed by:
 *
 *   - the REST layer (Phase 1.2), which tells the browser pool how many parallel
 *     /convert requests to run and signals "server busy" so clients back off, and
 *   - WP-CLI (Phase 1.3), which decides how many child processes to fan out.
 *
 * ## Testability
 *
 * Detection (CPU cores, load average) talks to the OS, which a unit test cannot
 * control. So the readings are injectable: pass explicit `$cores` / `$load` into
 * the constructor and the instance becomes fully deterministic, letting PHPUnit
 * exercise the clamp / busy / recommendation policy across a matrix of core
 * counts and load values without depending on the test machine. Passing `null`
 * (the default) means "detect from the OS at call time".
 *
 * This class is intentionally WordPress-independent.
 */
class ConcurrencyAdvisor
{
    /**
     * Load-per-core threshold above which the server is considered "busy".
     * A 1-minute load average equal to the core count means the machine is fully
     * subscribed; 1.5× that is a sustained backlog where we should pull back.
     */
    const BUSY_LOAD_PER_CORE = 1.5;

    /** Fallback core count when the OS cannot be probed. */
    const FALLBACK_CORES = 2;

    /** Hard ceiling on browser-driven parallel conversions. */
    const WEB_MAX = 6;

    /** Hard ceiling on CLI child processes. */
    const CLI_MAX = 8;

    /** Injected/overridden core count, or null to detect lazily. */
    private $coresOverride;

    /** Injected/overridden 1-min load average, or null to detect lazily. */
    private $loadOverride;

    /** Memoized detected core count (so we only probe the OS once). */
    private $detectedCores = null;

    /**
     * @param int|null   $cores  Inject a fixed core count (for tests / overrides),
     *                           or null to auto-detect.
     * @param float|null $load   Inject a fixed 1-minute load average, or null to
     *                           read sys_getloadavg() lazily.
     */
    public function __construct($cores = null, $load = null)
    {
        $this->coresOverride = $cores;
        $this->loadOverride = $load;
    }

    /**
     * Number of CPU cores available to this host.
     *
     * Detection order (first that yields a positive integer wins):
     *   1. `nproc` (Linux/containers — honours cgroup CPU limits on modern coreutils),
     *   2. `sysctl -n hw.ncpu` (macOS / BSD),
     *   3. counting `processor` lines in /proc/cpuinfo (Linux fallback).
     * Result is cached for the lifetime of the instance. Falls back to
     * FALLBACK_CORES (2) when nothing is detectable (e.g. exec disabled).
     *
     * @return int  Always >= 1.
     */
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

    /**
     * Probe the OS for a CPU core count. Static + side-effect-free (beyond the
     * exec/file reads) so it can be reused and so cpuCoreCount() can memoize it.
     *
     * @return int  Detected core count, or FALLBACK_CORES when undetectable.
     */
    public static function detectCpuCoreCount()
    {
        // 1. nproc
        $n = self::intFromExec('nproc 2>/dev/null');
        if ($n > 0) {
            return $n;
        }

        // 2. sysctl (macOS/BSD)
        $n = self::intFromExec('sysctl -n hw.ncpu 2>/dev/null');
        if ($n > 0) {
            return $n;
        }

        // 3. /proc/cpuinfo (Linux)
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
     * 1-minute load average divided by the core count, or null when the load
     * average is unavailable (Windows, or sys_getloadavg() disabled). A value of
     * 1.0 means "one runnable task per core on average".
     *
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
     * The 1-minute load average reading (injected override, or sys_getloadavg()).
     *
     * @return float|null  Null where unavailable.
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

    /**
     * Is the server under sustained load?
     *
     * True when load-per-core exceeds BUSY_LOAD_PER_CORE. When the load average
     * cannot be read (null), we deliberately treat the server as NOT busy: absent
     * evidence of trouble we prefer to keep converting rather than crawl.
     *
     * @return bool
     */
    public function isBusy()
    {
        $perCore = $this->loadPerCore();
        if ($perCore === null) {
            return false;
        }
        return $perCore > self::BUSY_LOAD_PER_CORE;
    }

    /**
     * Recommended number of parallel browser-driven (/convert) conversions.
     *
     * Policy: half the cores, clamped to [1, WEB_MAX]. Browser/FPM conversions
     * compete with normal site traffic on the same web tier, so we are
     * deliberately conservative (half, not all). Under load we collapse to a
     * single worker so a struggling site is never pushed over the edge.
     *
     * @return int  In [1, WEB_MAX].
     */
    public function recommendedWebConcurrency()
    {
        if ($this->isBusy()) {
            return 1;
        }
        $cores = $this->cpuCoreCount();
        return self::clamp((int) floor($cores / 2), 1, self::WEB_MAX);
    }

    /**
     * Recommended number of CLI child processes.
     *
     * Policy: cores - 1 (leave a core for the OS / other work), clamped to
     * [1, CLI_MAX]. CLI runs can be more aggressive than the web tier because the
     * operator chose to run them. Under load the recommendation is halved
     * (floor 1) rather than collapsed to 1, since a CLI bulk run is usually the
     * thing the operator most wants to finish.
     *
     * @return int  In [1, CLI_MAX].
     */
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

    /**
     * Clamp an integer into [$min, $max].
     */
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

    /**
     * Run a shell command and parse the first integer out of its output.
     *
     * Defensive about disabled exec (returns 0, which all callers treat as
     * "undetectable, try the next method").
     *
     * @return int  The parsed integer, or 0 on any failure.
     */
    private static function intFromExec($command)
    {
        if (!function_exists('exec')) {
            return 0;
        }
        // Guard against environments where exec exists but is disabled via
        // disable_functions — @ suppresses the warning, $output stays empty.
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
