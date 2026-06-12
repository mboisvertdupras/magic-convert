<?php

namespace MagicConvert\Avif;

/**
 * AVIF via the libvips PHP extension (heifsave with compression "av1").
 *
 * Adapted from rosell-dk/image-convert (MIT) — its Vips converter:
 *   - operationality via extension_loaded('vips') + the presence of the
 *     vips_call() / vips_image_new_from_file() / vips_error_buffer() functions,
 *   - AVIF is saved through libvips's HEIF saver ("heifsave") with
 *     compression => 'av1' (that is what makes the HEIF container an AVIF),
 *   - Q => quality, lossless => false.
 *
 * SPEED → EFFORT MAPPING (the piece the donor hard-coded; we make it follow our
 * speed option): libvips heifsave's "effort" is INVERTED vs. our speed — high
 * effort = more CPU = smaller file = LOW speed. We use effort = 9 - speed,
 * clamped to vips's 0..9 (see AbstractAvifConverter::speedToVipsEffort()). We do
 * NOT also pass the legacy "speed" key the donor set: on modern libvips that key
 * was removed/renamed to "effort", and passing an unknown property makes vips
 * error. If a given libvips build rejects "effort", we retry without it (mirroring
 * the donor's "drop unsupported property and retry" strategy) so the encode still
 * succeeds at the library default effort.
 *
 * NOTE: this uses the low-level procedural vips extension API (vips_call), which is
 * what the donor targets and what is most commonly installed on managed WordPress
 * hosts. The FFI php-vips binding (Jcupitt\Vips) is a separate animal and is not
 * handled here.
 */
class VipsAvif extends AbstractAvifConverter
{
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
        if (!extension_loaded('vips')) {
            return ['operational' => false, 'reason' => 'The libvips (vips) PHP extension is not loaded.'];
        }
        foreach (['vips_image_new_from_file', 'vips_call', 'vips_error_buffer'] as $fn) {
            if (!function_exists($fn)) {
                return [
                    'operational' => false,
                    'reason' => 'The vips extension is loaded but ' . $fn . '() is missing (broken/partial install).',
                ];
            }
        }

        // Probe that heifsave exists in this libvips build. Calling it with null args
        // returns -1; we inspect the error buffer to distinguish "class not found"
        // (no AVIF/HEIF support) from an ordinary argument error (support present).
        // (Probe technique adapted from rosell-dk/image-convert (MIT).)
        vips_error_buffer(); // clear
        $result = @vips_call('heifsave', null);
        if ($result === -1) {
            $message = vips_error_buffer();
            if (strpos($message, 'class "heifsave" not found') !== false) {
                return [
                    'operational' => false,
                    'reason' => 'libvips was compiled without HEIF/AVIF support (the "heifsave" operation is missing).',
                ];
            }
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
        $effort = self::speedToVipsEffort($this->speed($options));
        $strip = $this->stripMetadata($options);

        vips_error_buffer(); // clear
        $loaded = vips_image_new_from_file($source, []);
        if ($loaded === -1 || !is_array($loaded) || count($loaded) !== 1) {
            throw new \Exception('libvips failed to load source image: ' . vips_error_buffer());
        }
        $im = array_shift($loaded);

        $params = [
            'compression' => 'av1',   // av1 inside HEIF == AVIF
            'Q' => $quality,
            'lossless' => false,
            'effort' => $effort,      // 0 = slowest/best .. 9 = fastest (see mapping note)
            'strip' => $strip,
        ];

        // "drop unsupported property and retry" — older libvips builds may not know
        // 'effort' (or 'strip'); rather than fail the whole encode, unset the offending
        // property and try again. Adapted from rosell-dk/image-convert (MIT).
        $this->saveWithRetry($im, $destination, $params);
    }

    /**
     * Save via heifsave, recursively dropping any property libvips reports as unknown.
     *
     * @param  mixed   $im
     * @param  string  $destination
     * @param  array   $params
     * @return void
     * @throws \Exception
     */
    private function saveWithRetry($im, $destination, array $params)
    {
        vips_error_buffer(); // clear
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
