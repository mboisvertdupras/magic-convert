<?php

namespace MagicConvert\Avif;

use MagicConvert\ConcurrencyAdvisor;

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
        $speed = $this->speed($options);
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
     * @return int
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
