<?php

namespace MagicConvert\Avif;

class GdAvif extends AbstractAvifConverter
{
    public function id()
    {
        return 'gd';
    }

    public function label()
    {
        return 'GD imageavif()';
    }

    public function isOperational()
    {
        if (!extension_loaded('gd')) {
            return ['operational' => false, 'reason' => 'The GD PHP extension is not loaded.'];
        }
        if (!function_exists('imageavif')) {
            return [
                'operational' => false,
                'reason' => 'GD has no imageavif() function (needs PHP 8.1+ with a GD built against an AVIF encoder).',
            ];
        }
        $info = function_exists('gd_info') ? gd_info() : [];
        if (empty($info['AVIF Support'])) {
            return [
                'operational' => false,
                'reason' => "GD's imageavif() exists but gd_info()['AVIF Support'] is false — "
                    . 'GD was compiled without an AVIF encoder (libavif/libheif delegate missing).',
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

        $image = $this->createImageResource($source);

        if (!$this->tryToMakeTrueColorIfNot($image)) {
            @imagedestroy($image);
            throw new \Exception(
                'GD cannot convert this palette image to truecolor (imagepalettetotruecolor() unavailable), '
                . 'which AVIF encoding requires.'
            );
        }

        $mime = $this->detectMime($source);
        if ($mime === 'image/png') {
            $this->trySettingAlphaBlending($image);
        }

        $success = @imageavif($image, $destination, $quality, $speed);

        @imagedestroy($image);

        if ($success === false) {
            @unlink($destination);
            throw new \Exception('GD imageavif() failed to encode/write the AVIF file.');
        }

        if (!@file_exists($destination) || @filesize($destination) === 0) {
            @unlink($destination);
            throw new \Exception('GD imageavif() reported success but produced no output file.');
        }
    }

    /**
     * @param  string  $source
     * @return string|null
     */
    private function detectMime($source)
    {
        $info = @getimagesize($source);
        return (is_array($info) && isset($info['mime'])) ? $info['mime'] : null;
    }

    /**
     * @param  string  $source
     * @return \GdImage|resource
     * @throws \Exception
     */
    private function createImageResource($source)
    {
        $info = @getimagesize($source);
        $type = is_array($info) ? ($info[2] ?? null) : null;

        switch ($type) {
            case IMAGETYPE_JPEG:
                $image = @imagecreatefromjpeg($source);
                break;
            case IMAGETYPE_PNG:
                $image = @imagecreatefrompng($source);
                break;
            case IMAGETYPE_GIF:
                $image = @imagecreatefromgif($source);
                break;
            case IMAGETYPE_WEBP:
                $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false;
                break;
            default:
                throw new \Exception('GD AVIF: unsupported source image type for AVIF conversion.');
        }

        if ($image === false) {
            throw new \Exception('GD failed to load the source image (imagecreatefrom*() returned false).');
        }
        return $image;
    }

    /**
     * @param  \GdImage|resource  $image
     * @return bool
     */
    private function tryToMakeTrueColorIfNot(&$image)
    {
        if (function_exists('imageistruecolor') && imageistruecolor($image)) {
            return true;
        }
        if (function_exists('imagepalettetotruecolor')) {
            return imagepalettetotruecolor($image) !== false;
        }
        return false;
    }

    /**
     * @param  \GdImage|resource  $image
     * @return void
     */
    private function trySettingAlphaBlending($image)
    {
        if (function_exists('imagealphablending')) {
            @imagealphablending($image, true);
        }
        if (function_exists('imagesavealpha')) {
            @imagesavealpha($image, true);
        }
    }
}
