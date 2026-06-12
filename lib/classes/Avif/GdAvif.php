<?php

namespace MagicConvert\Avif;

/**
 * AVIF via the GD extension's imageavif() (PHP 8.1+).
 *
 * Adapted from rosell-dk/image-convert (MIT) — its Gd converter calls
 * imageavif($im, $dest, $quality, $speed). The palette→truecolor + alpha handling
 * below mirrors the proven "dance" in rosell-dk/webp-convert's Gd.php (MIT):
 * imagecreatefrom*, then make-true-color-if-not, then for PNG sources set
 * alphablending(true)+savealpha(true) so transparency survives.
 *
 * DETECTION (critical): GD has a known core false-positive where IMG_AVIF /
 * gd_info()['AVIF Support'] alone, or imageavif() existing alone, can wrongly
 * imply support. Our research mandate: require BOTH
 *   function_exists('imageavif') AND !empty(gd_info()['AVIF Support']).
 * That combination is what actually predicts a working encode.
 *
 * imageavif() signature: imageavif($image, $file, $quality = -1, $speed = -1).
 *   - quality -1 => GD default 30 (we always pass an explicit clamped value).
 *   - speed   -1 => GD default 6. GD's speed is the SAME 0..10 direction as ours,
 *     so we pass it through. (The donor left speed hard-coded at -1; we wire ours in.)
 *   - 4:2:0 chroma subsampling is automatic in GD below quality 90.
 */
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
        // The required second half: imageavif() can exist while GD was compiled
        // WITHOUT a working AVIF encoder. gd_info()['AVIF Support'] is the runtime
        // truth. (Guards against the IMG_AVIF false-positive.)
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
        $speed = $this->speed($options);  // GD speed: same 0..10 direction as ours

        $image = $this->createImageResource($source);

        // Palette → truecolor (AVIF needs RGB). Mirrors webp-convert Gd.php.
        if (!$this->tryToMakeTrueColorIfNot($image)) {
            @imagedestroy($image);
            throw new \Exception(
                'GD cannot convert this palette image to truecolor (imagepalettetotruecolor() unavailable), '
                . 'which AVIF encoding requires.'
            );
        }

        // For PNG sources, preserve the alpha channel through the encode.
        $mime = $this->detectMime($source);
        if ($mime === 'image/png') {
            $this->trySettingAlphaBlending($image);
        }

        // imageavif($image, $file, $quality, $speed)
        $success = @imageavif($image, $destination, $quality, $speed);

        @imagedestroy($image);

        if ($success === false) {
            @unlink($destination);
            throw new \Exception('GD imageavif() failed to encode/write the AVIF file.');
        }

        // Defensive: GD has historically reported success while writing nothing.
        if (!@file_exists($destination) || @filesize($destination) === 0) {
            @unlink($destination);
            throw new \Exception('GD imageavif() reported success but produced no output file.');
        }
    }

    /**
     * Detect the source mime cheaply (getimagesize) for the alpha-handling branch.
     *
     * @param  string  $source
     * @return string|null
     */
    private function detectMime($source)
    {
        $info = @getimagesize($source);
        return (is_array($info) && isset($info['mime'])) ? $info['mime'] : null;
    }

    /**
     * Create a GD image resource from the source, dispatching on detected type.
     *
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
     * Make the image truecolor if it is not already.
     * Mirrors webp-convert Gd.php::tryToMakeTrueColorIfNot() (MIT).
     *
     * @param  \GdImage|resource  $image  (by reference — imagepalettetotruecolor mutates in place)
     * @return bool  true if it is (now) truecolor.
     */
    private function tryToMakeTrueColorIfNot(&$image)
    {
        if (function_exists('imageistruecolor') && imageistruecolor($image)) {
            return true;
        }
        if (function_exists('imagepalettetotruecolor')) {
            return imagepalettetotruecolor($image) !== false;
        }
        // No way to convert; report failure so the caller aborts cleanly.
        return false;
    }

    /**
     * Turn on alpha blending + save-alpha so PNG transparency survives the encode.
     * Mirrors webp-convert Gd.php::trySettingAlphaBlending() (MIT).
     *
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
