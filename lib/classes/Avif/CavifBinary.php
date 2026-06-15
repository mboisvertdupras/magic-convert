<?php

namespace MagicConvert\Avif;

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
        $speed = self::speedToCavifSpeed($this->speed($options));

        $args = [];
        $args[] = '--quality ' . escapeshellarg((string) $quality);
        $args[] = '--speed ' . escapeshellarg((string) $speed);
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
