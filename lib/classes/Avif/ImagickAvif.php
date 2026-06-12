<?php

namespace MagicConvert\Avif;

/**
 * AVIF via the Imagick PHP extension.
 *
 * First choice in the stack: when present it is fast, in-process (no exec()
 * needed), and honours metadata stripping precisely.
 *
 * Adapted from rosell-dk/image-convert (MIT) — specifically its Imagick converter:
 *   - operationality via extension_loaded('imagick') + class_exists('\Imagick'),
 *   - AVIF support detected at RUNTIME with (new \Imagick())->queryFormats('AVIF')
 *     (version sniffing is unreliable; the donor and our research both insist on
 *     runtime detection),
 *   - setImageFormat('AVIF') + setImageCompressionQuality($q),
 *   - the metadata/color-profile dance (stripImage() also drops ICC profiles, so we
 *     grab and restore the icc profile around it — credited in the donor to Max Eremin).
 *
 * The 'heic:speed' option (ImageMagick exposes AVIF/HEIF encode speed under the
 * "heic:" define namespace) is the speed control the donor left unimplemented for
 * AVIF; we add it here. ImageMagick's heic:speed is on a 0..10 scale in the same
 * direction as ours, so it maps 1:1.
 */
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

        // Runtime AVIF detection — the ONLY reliable check (version numbers lie).
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

        // May throw ImagickException — let it propagate; the caller catches Throwable.
        $im = new \Imagick($source);

        $im->setImageFormat('AVIF');
        $im->setImageCompressionQuality($quality);

        // ImageMagick's AVIF/HEIF speed knob lives under the "heic:" define namespace.
        // Same 0..10 direction as our scale, so pass straight through.
        $im->setOption('heic:speed', (string) $speed);

        if ($this->stripMetadata($options)) {
            // stripImage() also removes the ICC color profile. Grab it first so we can
            // restore it — we only want to drop EXIF/XMP, not color management.
            // (Technique adapted from rosell-dk/image-convert (MIT); credited there to Max Eremin.)
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
