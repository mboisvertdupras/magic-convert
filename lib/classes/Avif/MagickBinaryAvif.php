<?php

namespace MagicConvert\Avif;

use ExecWithFallback\ExecWithFallback;
use LocateBinaries\LocateBinaries;

class MagickBinaryAvif extends AbstractAvifExecConverter
{
    /** @var string|null */
    private $resolved = null;

    /** @var bool */
    private $resolvedDone = false;

    public function id()
    {
        return 'magick-binary';
    }

    public function label()
    {
        return 'ImageMagick binary (magick/convert, heic:speed)';
    }

    protected function binaryName()
    {
        return 'magick';
    }

    /**
     * @return string|null
     */
    protected function resolveBinary()
    {
        if ($this->resolvedDone) {
            return $this->resolved;
        }
        $this->resolvedDone = true;

        $override = 'MAGIC_CONVERT_MAGICK_PATH';
        if (defined($override) && is_string(constant($override)) && constant($override) !== '') {
            return $this->resolved = constant($override);
        }
        $envVal = getenv($override);
        if (is_string($envVal) && $envVal !== '') {
            return $this->resolved = $envVal;
        }

        foreach (['magick', 'convert'] as $name) {
            try {
                $installed = LocateBinaries::locateInstalledBinaries($name);
                if (!empty($installed)) {
                    return $this->resolved = $installed[0];
                }
            } catch (\Throwable $e) {
            }
            try {
                $common = LocateBinaries::locateInCommonSystemPaths($name);
                if (!empty($common)) {
                    return $this->resolved = $common[0];
                }
            } catch (\Throwable $e) {
            }
        }

        foreach (['magick', 'convert'] as $name) {
            ExecWithFallback::exec(escapeshellarg($name) . ' -version 2>&1', $out, $code);
            if ($code === 0) {
                return $this->resolved = $name;
            }
        }

        return $this->resolved = null;
    }

    /**
     * @param  string  $binary
     * @return array{operational:bool,reason:string}
     */
    protected function probeCapability($binary)
    {
        list($code, $output) = $this->run(escapeshellarg($binary) . ' -list format');
        if ($code !== 0) {
            return [
                'operational' => false,
                'reason' => 'ImageMagick binary found but "-list format" failed (return code ' . $code . ').',
            ];
        }
        foreach ($output as $line) {
            if (preg_match('#\bAVIF\b#i', $line) && preg_match('#[r-]w[+-]?\s#i', $line)) {
                return ['operational' => true, 'reason' => ''];
            }
        }
        return [
            'operational' => false,
            'reason' => 'The ImageMagick binary has no AVIF WRITE support ("-list format" shows no writable AVIF row; '
                . 'the libheif/libaom delegate is missing).',
        ];
    }

    public function convert($source, $destination, array $options)
    {
        $check = $this->isOperational();
        if (!$check['operational']) {
            throw new \Exception($check['reason']);
        }

        $binary = $this->resolveBinary();
        $quality = $this->quality($options);
        $speed = $this->speed($options);

        $args = [];
        $args[] = '-quality ' . escapeshellarg((string) $quality);
        $args[] = '-define heic:speed=' . escapeshellarg((string) $speed);
        $args[] = escapeshellarg($source);
        if ($this->stripMetadata($options)) {
            $args[] = '-strip';
        }
        $args[] = escapeshellarg('avif:' . $destination);

        $command = escapeshellarg($binary) . ' ' . implode(' ', $args);
        list($code, $output) = $this->run($command);

        if ($code !== 0) {
            throw new \Exception(
                'ImageMagick binary failed (return code ' . $code . '): ' . implode(' / ', array_slice($output, 0, 5))
            );
        }
        if (!@file_exists($destination) || @filesize($destination) === 0) {
            throw new \Exception('ImageMagick binary reported success but produced no output file.');
        }
    }
}
