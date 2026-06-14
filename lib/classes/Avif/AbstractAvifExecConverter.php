<?php

namespace MagicConvert\Avif;

use ExecWithFallback\ExecWithFallback;
use LocateBinaries\LocateBinaries;

/**
 * Base for the exec()-based AVIF converters (avifenc, cavif, magick/convert).
 *
 * It reuses the SAME exec machinery the plugin already ships for cwebp:
 *   - rosell-dk/exec-with-fallback (ExecWithFallback) for exec()/proc_open() with
 *     graceful fallback and an "is any exec method available?" check (mirrors the
 *     pattern in vendor webp-convert Cwebp.php / image-convert ExecTrait, MIT), and
 *   - rosell-dk/locate-binaries (LocateBinaries) for finding the binary in common
 *     system paths and via the OS "which" command — exactly what Cwebp.php uses to
 *     discover cwebp.
 * Both are vendored transitive deps of webp-convert, so no new dependency is introduced.
 *
 * Being vendored on disk is NOT the same as being autoloadable: the plugin loads
 * Composer's vendor autoloader lazily (front-end requests stay lean), so these classes
 * resolve only after a code path that needs them has pulled vendor/autoload.php in.
 * AvifStack (the only thing that builds these converters) does exactly that in its
 * constructor. As a second line of defence, isOperational() below verifies the classes
 * are actually loadable and degrades to a clear "not operational" reason rather than
 * fataling if they somehow are not.
 *
 * Binary discovery order (first hit wins):
 *   1. an explicit override: constant MAGIC_CONVERT_<UPPER>_PATH or env var of the
 *      same name (mirrors image-convert's IMAGECONVERT_*_PATH override convention),
 *   2. LocateBinaries::locateInstalledBinaries()  ("which -a <bin>"),
 *   3. LocateBinaries::locateInCommonSystemPaths() (/usr/bin, /usr/local/bin, ...),
 *   4. the bare binary name (let the shell's PATH resolve it).
 *
 * Discovery is memoized per converter id within the request.
 */
abstract class AbstractAvifExecConverter extends AbstractAvifConverter
{
    /** @var array<string,string|null>  id => resolved binary path (memoized). */
    private static $resolvedBinary = [];

    /**
     * The base binary name to discover (e.g. 'avifenc', 'cavif', 'magick').
     *
     * @return string
     */
    abstract protected function binaryName();

    /**
     * Is exec available AND the binary findable AND (if applicable) AVIF-write capable?
     * Concrete converters add their own format-capability probe on top of this.
     *
     * @return array{operational:bool,reason:string}
     */
    public function isOperational()
    {
        // Defence in depth: the exec helpers are loaded by AvifStack's constructor, but if
        // this converter is ever exercised without that autoloader having been registered,
        // report a clear reason instead of fataling on the static calls below. Use the
        // default autoload=true so a registered-but-not-yet-referenced class resolves
        // normally; only a genuinely unresolvable class trips the graceful degradation.
        if (!class_exists('\ExecWithFallback\ExecWithFallback')
            || !class_exists('\LocateBinaries\LocateBinaries')
        ) {
            return [
                'operational' => false,
                'reason' => 'The exec helper libraries (exec-with-fallback / locate-binaries) '
                    . 'are not loaded, so binary-based AVIF conversion is unavailable.',
            ];
        }
        if (!ExecWithFallback::anyAvailable()) {
            return [
                'operational' => false,
                'reason' => 'PHP cannot execute external binaries on this host '
                    . '(exec(), proc_open() and friends are all disabled, e.g. by disable_functions).',
            ];
        }
        $binary = $this->resolveBinary();
        if ($binary === null) {
            return [
                'operational' => false,
                'reason' => 'The "' . $this->binaryName() . '" binary was not found on PATH or in common system paths.',
            ];
        }
        return $this->probeCapability($binary);
    }

    /**
     * Per-binary capability probe (e.g. "does magick -list format show AVIF write?").
     * Default: assume a located binary is usable.
     *
     * @param  string  $binary  resolved path
     * @return array{operational:bool,reason:string}
     */
    protected function probeCapability($binary)
    {
        return ['operational' => true, 'reason' => ''];
    }

    /**
     * Resolve (and memoize) the absolute path / command for this converter's binary.
     *
     * @return string|null  null when nothing was found.
     */
    protected function resolveBinary()
    {
        $id = $this->id();
        if (array_key_exists($id, self::$resolvedBinary)) {
            return self::$resolvedBinary[$id];
        }
        self::$resolvedBinary[$id] = $this->discoverBinary();
        return self::$resolvedBinary[$id];
    }

    /**
     * Do the actual discovery (uncached).
     *
     * @return string|null
     */
    private function discoverBinary()
    {
        $name = $this->binaryName();

        // 1. Explicit override (constant or env), mirroring IMAGECONVERT_*_PATH.
        $override = $this->overrideName();
        if (defined($override) && is_string(constant($override)) && constant($override) !== '') {
            return constant($override);
        }
        $envVal = getenv($override);
        if (is_string($envVal) && $envVal !== '') {
            return $envVal;
        }

        // 2. "which -a <bin>"
        try {
            $installed = LocateBinaries::locateInstalledBinaries($name);
            if (!empty($installed)) {
                return $installed[0];
            }
        } catch (\Throwable $e) {
            // fall through to next strategy
        }

        // 3. common system paths
        try {
            $common = LocateBinaries::locateInCommonSystemPaths($name);
            if (!empty($common)) {
                return $common[0];
            }
        } catch (\Throwable $e) {
            // fall through
        }

        // 4. Last resort: try a bare invocation; if it runs at all, use the bare name.
        ExecWithFallback::exec(escapeshellarg($name) . ' --version 2>&1', $out, $code);
        if ($code === 0) {
            return $name;
        }

        return null;
    }

    /**
     * The constant/env name used to override this converter's binary path.
     *
     * @return string  e.g. 'MAGIC_CONVERT_AVIFENC_PATH'
     */
    protected function overrideName()
    {
        $upper = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $this->binaryName()));
        return 'MAGIC_CONVERT_' . $upper . '_PATH';
    }

    /**
     * Run a command, returning [returnCode, outputLines].
     *
     * @param  string  $command  fully-built, already-escaped command (without trailing redirect).
     * @return array{0:int,1:array<int,string>}
     */
    protected function run($command)
    {
        $output = [];
        $returnCode = null;
        ExecWithFallback::exec($command . ' 2>&1', $output, $returnCode);
        return [(int) $returnCode, is_array($output) ? $output : []];
    }
}
