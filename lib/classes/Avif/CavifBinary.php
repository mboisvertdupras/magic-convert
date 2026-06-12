<?php

namespace MagicConvert\Avif;

/**
 * AVIF via 'cavif' (the Rust libavif/rav1e-based encoder by Kornel Lesiński).
 *
 * New converter (no donor counterpart). Reuses the shared exec base.
 *
 * Flag mapping:
 *   - quality: '--quality <1..100>' (higher = better). We pass our clamped 0..100;
 *     cavif treats 0 the same as a low quality, but to be safe we forward the
 *     clamped value as-is (0 is accepted as "lowest").
 *   - speed:   '--speed <1..10>' (1 = slowest/best .. 10 = fastest), SAME direction
 *     as ours but with a floor of 1 — cavif rejects 0. We map via
 *     AbstractAvifConverter::speedToCavifSpeed() which clamps our 0 up to 1.
 *   - cavif always writes alongside or to an explicit '-o <dest>'.
 *   - cavif has no metadata toggle of note for our purposes; it does not embed
 *     EXIF/XMP, so 'metadata none' is effectively the default behavior.
 */
class CavifBinary extends AbstractAvifExecConverter
{
    public function id()
    {
        return 'cavif';
    }

    public function label()
    {
        return 'cavif (rav1e)';
    }

    protected function binaryName()
    {
        return 'cavif';
    }

    public function convert($source, $destination, array $options)
    {
        $check = $this->isOperational();
        if (!$check['operational']) {
            throw new \Exception($check['reason']);
        }

        $binary = $this->resolveBinary();
        $quality = $this->quality($options);
        $speed = self::speedToCavifSpeed($this->speed($options));  // cavif 1..10

        $args = [];
        $args[] = '--quality ' . escapeshellarg((string) $quality);
        $args[] = '--speed ' . escapeshellarg((string) $speed);
        // Overwrite without prompting and write to our exact destination.
        $args[] = '--overwrite';
        $args[] = '-o ' . escapeshellarg($destination);
        $args[] = escapeshellarg($source);

        $command = escapeshellarg($binary) . ' ' . implode(' ', $args);
        list($code, $output) = $this->run($command);

        if ($code !== 0) {
            throw new \Exception(
                'cavif failed (return code ' . $code . '): ' . implode(' / ', array_slice($output, 0, 5))
            );
        }
        if (!@file_exists($destination) || @filesize($destination) === 0) {
            throw new \Exception('cavif reported success but produced no output file.');
        }
    }
}
