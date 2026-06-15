<?php

namespace MagicConvert\Avif;

class ImagickAvif extends AbstractAvifConverter
{
    public function id()
    {
        return 'imagick';
    }

    public function label()
    {
        return 'Imagick extension (heic:speed)';
    }

    public function isOperational()
    {
        if (!extension_loaded('imagick')) {
            return ['operational' => false, 'reason' => 'The Imagick PHP extension is not loaded.'];
        }
        if (!class_exists('\\Imagick')) {
            return [
                'operational' => false,
                'reason' => 'The imagick extension is loaded but the \\Imagick class is not available (broken install).',
            ];
        }

        try {
            $im = new \Imagick();
            $formats = $im->queryFormats('AVIF');
        } catch (\Throwable $e) {
            return [
                'operational' => false,
                'reason' => 'Imagick threw while querying formats: ' . $e->getMessage(),
            ];
        }
        if (empty($formats)) {
            return [
                'operational' => false,
                'reason' => 'Imagick is installed but was compiled without AVIF/HEIC support '
                    . '(queryFormats("AVIF") returned nothing — the libheif delegate is missing).',
            ];
        }

        return ['operational' => true, 'reason' => ''];
    }

    public function convert($source, $destination, array $options)
    {
        $check = $this->isOperational();
        if (!$check['operational']) {
            throw new \Exception($check['reason']);
        }

        $quality = $this->quality($options);
        $speed = $this->speed($options);

        $im = new \Imagick($source);

        $im->setImageFormat('AVIF');
        $im->setImageCompressionQuality($quality);

        $im->setOption('heic:speed', (string) $speed);

        if ($this->stripMetadata($options)) {
            $profiles = $im->getImageProfiles('icc', true);
            $im->stripImage();
            if (!empty($profiles) && isset($profiles['icc'])) {
                $im->profileImage('icc', $profiles['icc']);
            }
        }

        $blob = $im->getImageBlob();
        $im->clear();

        if ($blob === false || $blob === '') {
            throw new \Exception('Imagick produced an empty AVIF blob.');
        }

        if (@file_put_contents($destination, $blob) === false) {
            throw new \Exception('Imagick: failed writing AVIF to destination (check permissions).');
        }
    }
}
