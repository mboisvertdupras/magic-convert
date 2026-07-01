<?php

namespace MagicConvert\Avif;

class VipsAvif extends AbstractAvifConverter
{
    private static $operational;

    public function id()
    {
        return 'vips';
    }

    public function label()
    {
        return 'libvips extension (heifsave av1)';
    }

    public function isOperational()
    {
        if (self::$operational !== null) {
            return self::$operational;
        }

        if (!extension_loaded('vips')) {
            return self::$operational = ['operational' => false, 'reason' => 'The libvips (vips) PHP extension is not loaded.'];
        }
        foreach (['vips_image_new_from_file', 'vips_call', 'vips_error_buffer'] as $fn) {
            if (!function_exists($fn)) {
                return self::$operational = [
                    'operational' => false,
                    'reason' => 'The vips extension is loaded but ' . $fn . '() is missing (broken/partial install).',
                ];
            }
        }

        vips_error_buffer();
        $probe = @vips_call('black', null, 1, 1);
        if (!is_array($probe) || !isset($probe['out'])) {
            return self::$operational = [
                'operational' => false,
                'reason' => 'libvips could not create a probe image: ' . vips_error_buffer(),
            ];
        }

        $probeFile = tempnam(sys_get_temp_dir(), 'mc-vips-avif-');
        if ($probeFile === false) {
            return self::$operational = [
                'operational' => false,
                'reason' => 'Could not create a temporary file to probe libvips AVIF support.',
            ];
        }

        vips_error_buffer();
        $result = @vips_call('heifsave', $probe['out'], $probeFile, ['compression' => 'av1']);
        $message = vips_error_buffer();
        @unlink($probeFile);

        if ($result === -1) {
            if (strpos($message, 'class "heifsave" not found') !== false) {
                return self::$operational = [
                    'operational' => false,
                    'reason' => 'libvips was compiled without HEIF/AVIF support (the "heifsave" operation is missing).',
                ];
            }
            return self::$operational = [
                'operational' => false,
                'reason' => 'libvips heifsave probe failed: ' . $message,
            ];
        }

        return self::$operational = ['operational' => true, 'reason' => ''];
    }

    public function convert($source, $destination, array $options)
    {
        $check = $this->isOperational();
        if (!$check['operational']) {
            throw new \Exception($check['reason']);
        }

        $quality = $this->quality($options);
        $effort = self::speedToVipsEffort($this->speed($options));
        $strip = $this->stripMetadata($options);

        vips_error_buffer();
        $loaded = vips_image_new_from_file($source, []);
        if ($loaded === -1 || !is_array($loaded) || count($loaded) !== 1) {
            throw new \Exception('libvips failed to load source image: ' . vips_error_buffer());
        }
        $im = array_shift($loaded);

        $params = [
            'compression' => 'av1',
            'Q' => $quality,
            'lossless' => false,
            'effort' => $effort,
            'strip' => $strip,
        ];

        $this->saveWithRetry($im, $destination, $params);
    }

    /**
     * @param  string  $destination
     * @throws \Exception
     */
    private function saveWithRetry($im, $destination, array $params)
    {
        vips_error_buffer();
        $result = vips_call('heifsave', $im, $destination, $params);
        if ($result === -1) {
            $message = vips_error_buffer();
            if (preg_match('#no property named .([^.\s]+).#', $message, $m) && isset($params[$m[1]])) {
                unset($params[$m[1]]);
                $this->saveWithRetry($im, $destination, $params);
                return;
            }
            throw new \Exception('libvips heifsave failed: ' . $message);
        }
    }
}
