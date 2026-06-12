<?php

/*
 * This namespace (MagicConvert\Avif) is the AVIF converter stack (Phase 2.3).
 *
 * It is deliberately WordPress-INDEPENDENT — it is reachable both from the bulk
 * path (which has WordPress) and from ConvertHelperIndependent::convert()
 * (which is also used by the dependency-free wod scripts). Therefore: no WP
 * functions, no plugin Config access. Everything it needs (quality, speed,
 * metadata) is passed in via the $options array.
 *
 * Donor attribution: the encoding techniques (which extension function / which
 * CLI flag actually produces AVIF, how to detect support at runtime) are PORTED
 * from rosell-dk/image-convert (MIT) — see the per-converter docblocks. We do
 * NOT depend on that library (zero releases, marked not-production-ready); we
 * reimplement the techniques here against the plugin's own conventions.
 */

namespace MagicConvert\Avif;

/**
 * Base class for every AVIF converter in the stack.
 *
 * Each concrete converter answers two questions:
 *
 *   1. isOperational(): can this machine encode AVIF with this backend right now?
 *      It returns a {operational:bool, reason:string} array. The reason is the
 *      USER-FACING explanation that the self-test page and the aggregate failure
 *      log surface (e.g. "GD: imageavif() exists but gd_info()['AVIF Support'] is
 *      false — GD was compiled without an AVIF encoder"). Getting the reason right
 *      is the whole point: detection failures are the dominant support generator.
 *
 *   2. convert($source, $destination, $options): encode, or throw on failure.
 *
 * OPTIONS contract (the only keys a converter may read):
 *   - 'quality'  int 0..100   (default 30 — "AVIF Q30 ≈ JPEG Q75")
 *   - 'speed'    int 0..10    (default 6; higher = faster encode, larger file)
 *   - 'metadata' string       'all' | 'none' (mirrors the plugin's webp metadata
 *                             option; 'none' strips EXIF/XMP — color profiles kept
 *                             where the backend allows it).
 *   - 'jobs'     int|null     (advisory thread count for multi-threaded encoders;
 *                             null = let the encoder decide.)
 */
abstract class AbstractAvifConverter
{
    /** Default AVIF quality. Q30 ≈ JPEG Q75 (documented in the roadmap). */
    const DEFAULT_QUALITY = 30;

    /** Default speed/effort on our 0..10 scale (6 = good balance, matches GD/avifenc defaults). */
    const DEFAULT_SPEED = 6;

    /**
     * Short, stable id for logs / self-test rows (e.g. 'imagick', 'gd', 'avifenc').
     *
     * @return string
     */
    abstract public function id();

    /**
     * Human label for logs / UI (e.g. 'Imagick (heic:speed)', 'GD imageavif()').
     *
     * @return string
     */
    abstract public function label();

    /**
     * Is this converter able to encode AVIF on this machine right now?
     *
     * @return array{operational:bool,reason:string}  When operational is false,
     *         reason explains precisely why (missing extension, no AVIF support
     *         compiled in, exec disabled, binary not found, ...).
     */
    abstract public function isOperational();

    /**
     * Encode $source to AVIF at $destination.
     *
     * Implementations MUST write the finished file to exactly $destination (the
     * caller in ConvertHelperIndependent hands us a '<dest>.<pid>.tmp.avif' temp
     * path and renames it into place atomically — see Phase-1 hardening). On any
     * failure they MUST throw and MUST NOT leave a partial file behind at
     * $destination.
     *
     * @param  string  $source       Absolute path to an existing source image (jpeg/png/...).
     * @param  string  $destination  Absolute path to write the AVIF to.
     * @param  array   $options      See the OPTIONS contract above.
     * @return void
     * @throws \Exception  on any failure (operationality, encode error, write error).
     */
    abstract public function convert($source, $destination, array $options);

    // --- shared option helpers (pure logic; unit-tested directly) ------------

    /**
     * Normalise + clamp the quality option to 0..100, defaulting to DEFAULT_QUALITY.
     *
     * @param  array  $options
     * @return int
     */
    protected function quality(array $options)
    {
        $q = isset($options['quality']) ? (int) $options['quality'] : self::DEFAULT_QUALITY;
        return self::clamp($q, 0, 100);
    }

    /**
     * Normalise + clamp the speed option to 0..10, defaulting to DEFAULT_SPEED.
     *
     * Our canonical scale matches GD/avifenc: 0 = slowest/smallest, 10 = fastest/largest.
     *
     * @param  array  $options
     * @return int
     */
    protected function speed(array $options)
    {
        $s = isset($options['speed']) ? (int) $options['speed'] : self::DEFAULT_SPEED;
        return self::clamp($s, 0, 10);
    }

    /**
     * Should metadata be stripped? Mirrors the plugin's webp 'metadata' option:
     * the only value that strips is the exact string 'none'. Anything else (incl.
     * 'all' or absent) keeps metadata, matching webp-convert's semantics.
     *
     * @param  array  $options
     * @return bool   true => strip metadata.
     */
    protected function stripMetadata(array $options)
    {
        return isset($options['metadata']) && ($options['metadata'] === 'none');
    }

    /**
     * Map our 0..10 speed onto libvips heifsave 'effort' (0=slowest/best .. 9=fastest).
     *
     * libvips heifsave's "effort" is INVERTED relative to our speed scale: a HIGH
     * effort means more CPU and a smaller file, i.e. a LOW speed. So effort ≈ 9 - speed,
     * clamped to vips's documented 0..9 range. (Adapted from the speed/effort handling
     * in rosell-dk/image-convert's Vips converter, MIT.)
     *
     *   our speed 0  (slowest) -> effort 9 (max effort)
     *   our speed 6  (default) -> effort 3
     *   our speed 10 (fastest) -> effort 0 (least effort)
     *
     * @param  int  $speed  our 0..10 speed
     * @return int  vips effort 0..9
     */
    public static function speedToVipsEffort($speed)
    {
        $speed = self::clamp((int) $speed, 0, 10);
        return self::clamp(9 - $speed, 0, 9);
    }

    /**
     * Map our 0..10 speed onto cavif's 1..10 speed scale.
     *
     * cavif uses speed 1..10 (1 = slowest/best, 10 = fastest), the SAME direction
     * as ours but with a floor of 1 (cavif rejects 0). So we clamp our 0 up to 1.
     *
     * @param  int  $speed  our 0..10 speed
     * @return int  cavif speed 1..10
     */
    public static function speedToCavifSpeed($speed)
    {
        return self::clamp((int) $speed, 1, 10);
    }

    /**
     * Clamp an int into [min, max].
     *
     * @param  int  $v
     * @param  int  $min
     * @param  int  $max
     * @return int
     */
    protected static function clamp($v, $min, $max)
    {
        $v = (int) $v;
        if ($v < $min) {
            return $min;
        }
        if ($v > $max) {
            return $max;
        }
        return $v;
    }
}
