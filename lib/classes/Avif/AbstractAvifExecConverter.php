<?php

namespace MagicConvert\Avif;

use ExecWithFallback\ExecWithFallback;
use LocateBinaries\LocateBinaries;

abstract class AbstractAvifExecConverter extends AbstractAvifConverter
{
    /** @var array<string,string|null> */
    private static $resolvedBinary = [];

    abstract protected function binaryName();

    /**
     * @return array{operational:bool,reason:string}
     */
    public function isOperational()
    {
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
     * @param  string  $binary
     * @return array{operational:bool,reason:string}
     */
    protected function probeCapability($binary)
    {
        return ['operational' => true, 'reason' => ''];
    }

    /**
     * @return string|null
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
     * @return string|null
     */
    private function discoverBinary()
    {
        $name = $this->binaryName();

        $override = $this->overrideName();
        if (defined($override) && is_string(constant($override)) && constant($override) !== '') {
            return constant($override);
        }
        $envVal = getenv($override);
        if (is_string($envVal) && $envVal !== '') {
            return $envVal;
        }

        try {
            $installed = LocateBinaries::locateInstalledBinaries($name);
            if (!empty($installed)) {
                return $installed[0];
            }
        } catch (\Throwable $e) {
        }

        try {
            $common = LocateBinaries::locateInCommonSystemPaths($name);
            if (!empty($common)) {
                return $common[0];
            }
        } catch (\Throwable $e) {
        }

        ExecWithFallback::exec(escapeshellarg($name) . ' --version 2>&1', $out, $code);
        if ($code === 0) {
            return $name;
        }

        return null;
    }

    /**
     * @return string
     */
    protected function overrideName()
    {
        $upper = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $this->binaryName()));
        return 'MAGIC_CONVERT_' . $upper . '_PATH';
    }

    /**
     * @param  string  $command
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
