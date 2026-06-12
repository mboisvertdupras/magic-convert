<?php

namespace MagicConvert\Avif;

use MagicConvert\ConcurrencyAdvisor;

/**
 * AVIF via the libavif reference encoder 'avifenc'.
 *
 * This is a NEW converter (the donor image-convert has no dedicated avifenc
 * converter). It reuses the shared exec base (AbstractAvifExecConverter) for
 * discovery + exec, matching how cwebp is invoked.
 *
 * Flag mapping (libavif 1.x):
 *   - quality:  '-q <0..100>' / '--qcolor <0..100>' — libavif 1.x accepts -q on a
 *     0..100 quality scale (higher = better). We pass our clamped 0..100 directly.
 *   - speed:    '-s <0..10>'  — avifenc speed 0 (slowest/best) .. 10 (fastest),
 *     SAME direction as ours, so passed straight through (default 6).
 *   - jobs:     '--jobs <n>'  — encoder thread count. We take it from the
 *     ConcurrencyAdvisor's detected core count, capped at 4 so a parallel bulk run
 *     (which already spawns multiple PHP workers/procs) doesn't oversubscribe the CPU.
 *   - metadata: avifenc strips by default and only copies EXIF/XMP when asked. To
 *     KEEP metadata we pass nothing special (libavif carries ICC by default); to
 *     STRIP we explicitly drop exif/xmp via '--ignore-exif --ignore-xmp'.
 */
class AvifEncBinary extends AbstractAvifExecConverter
{
    public function id()
    {
        return 'avifenc';
    }

    public function label()
    {
        return 'avifenc (libavif)';
    }

    protected function binaryName()
    {
        return 'avifenc';
    }

    public function convert($source, $destination, array $options)
    {
        $check = $this->isOperational();
        if (!$check['operational']) {
            throw new \Exception($check['reason']);
        }

        $binary = $this->resolveBinary();
        $quality = $this->quality($options);
        $speed = $this->speed($options);  // avifenc -s shares our 0..10 direction
        $jobs = $this->jobs($options);

        $args = [];
        $args[] = '-q ' . escapeshellarg((string) $quality);
        $args[] = '-s ' . escapeshellarg((string) $speed);
        $args[] = '--jobs ' . escapeshellarg((string) $jobs);
        if ($this->stripMetadata($options)) {
            $args[] = '--ignore-exif';
            $args[] = '--ignore-xmp';
        }
        $args[] = escapeshellarg($source);
        $args[] = escapeshellarg($destination);

        $command = escapeshellarg($binary) . ' ' . implode(' ', $args);
        list($code, $output) = $this->run($command);

        if ($code !== 0) {
            throw new \Exception(
                'avifenc failed (return code ' . $code . '): ' . implode(' / ', array_slice($output, 0, 5))
            );
        }
        if (!@file_exists($destination) || @filesize($destination) === 0) {
            throw new \Exception('avifenc reported success but produced no output file.');
        }
    }

    /**
     * Determine the --jobs thread count.
     *
     * Priority: an explicit options['jobs'] (so a parallel orchestrator can pin it),
     * else the ConcurrencyAdvisor's detected cores, capped at 4.
     *
     * @param  array  $options
     * @return int  >= 1
     */
    private function jobs(array $options)
    {
        if (isset($options['jobs']) && (int) $options['jobs'] > 0) {
            return min(4, max(1, (int) $options['jobs']));
        }
        $cores = 2;
        if (class_exists('\\MagicConvert\\ConcurrencyAdvisor')) {
            try {
                $advisor = new ConcurrencyAdvisor();
                $cores = $advisor->cpuCoreCount();
            } catch (\Throwable $e) {
                $cores = 2;
            }
        }
        return min(4, max(1, (int) $cores));
    }
}
