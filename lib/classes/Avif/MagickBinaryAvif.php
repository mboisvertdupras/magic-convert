<?php

namespace MagicConvert\Avif;

use ExecWithFallback\ExecWithFallback;
use LocateBinaries\LocateBinaries;

/**
 * AVIF via the ImageMagick command-line binary ('magick' or 'convert').
 *
 * Adapted from rosell-dk/image-convert (MIT) — its ImageMagick (binary) converter:
 *   - locate the binary, then verify AVIF support by parsing the output of
 *     '<bin> -list format' and looking for an AVIF row with write capability,
 *   - encode with '-quality <q>' and the AVIF/HEIF speed under the "heic:" define
 *     namespace ('-define heic:speed=<s>'),
 *   - all user-controlled arguments are escapeshellarg()'d (matches Cwebp.php).
 *
 * ImageMagick 7 ships the 'magick' driver; ImageMagick 6 uses 'convert'. We try
 * 'magick' first and fall back to 'convert'. Discovery is overridden here (rather
 * than using the shared base) precisely because of the two-name search.
 */
class MagickBinaryAvif extends AbstractAvifExecConverter
{
    /** @var string|null  memoized resolved binary path (false-y = not yet computed). */
    private $resolved = null;

    /** @var bool  whether discovery has run. */
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
     * Resolve 'magick', then 'convert'. Honours the MAGIC_CONVERT_MAGICK_PATH
     * constant/env override first.
     *
     * @return string|null
     */
    protected function resolveBinary()
    {
        if ($this->resolvedDone) {
            return $this->resolved;
        }
        $this->resolvedDone = true;

        // 1. explicit override
        $override = 'MAGIC_CONVERT_MAGICK_PATH';
        if (defined($override) && is_string(constant($override)) && constant($override) !== '') {
            return $this->resolved = constant($override);
        }
        $envVal = getenv($override);
        if (is_string($envVal) && $envVal !== '') {
            return $this->resolved = $envVal;
        }

        // 2/3. locate by name, trying the v7 then v6 driver names
        foreach (['magick', 'convert'] as $name) {
            try {
                $installed = LocateBinaries::locateInstalledBinaries($name);
                if (!empty($installed)) {
                    return $this->resolved = $installed[0];
                }
            } catch (\Throwable $e) {
                // continue
            }
            try {
                $common = LocateBinaries::locateInCommonSystemPaths($name);
                if (!empty($common)) {
                    return $this->resolved = $common[0];
                }
            } catch (\Throwable $e) {
                // continue
            }
        }

        // 4. bare-name probe
        foreach (['magick', 'convert'] as $name) {
            ExecWithFallback::exec(escapeshellarg($name) . ' -version 2>&1', $out, $code);
            if ($code === 0) {
                return $this->resolved = $name;
            }
        }

        return $this->resolved = null;
    }

    /**
     * Verify the located binary can WRITE AVIF, by parsing '-list format'.
     * (Adapted from rosell-dk/image-convert ImageMagick::checkConvertability(), MIT.)
     *
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
        // An AVIF row looks like:  "      AVIF* HEIC      rw+   ..."  — a "w" flag means write.
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
        $speed = $this->speed($options);   // heic:speed shares our 0..10 direction

        // ImageMagick CLI argument ordering matters: SETTINGS (-quality, -define)
        // are read before the input image, but OPERATORS (-strip) must come AFTER
        // the input is loaded, otherwise IM errors "no images found for operation".
        $args = [];
        $args[] = '-quality ' . escapeshellarg((string) $quality);
        $args[] = '-define heic:speed=' . escapeshellarg((string) $speed);
        $args[] = escapeshellarg($source);
        if ($this->stripMetadata($options)) {
            $args[] = '-strip';
        }
        // Force the output coder to avif regardless of destination extension.
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
