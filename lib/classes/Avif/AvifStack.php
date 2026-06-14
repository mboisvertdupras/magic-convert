<?php

namespace MagicConvert\Avif;

/**
 * The AVIF converter stack.
 *
 * Tries each converter in priority order until one encodes successfully. If none
 * works, it throws an AvifStackException whose message lists EVERY converter and
 * why it failed — this aggregate becomes the user-facing conversion log (the same
 * "tried X, tried Y, here's why each failed" surface the webp path produces).
 *
 * Priority order (best in-process / fastest-to-fail first):
 *   1. Imagick        — in-process, honours metadata precisely, no exec needed.
 *   2. libvips        — in-process, very fast heifsave.
 *   3. GD imageavif() — in-process, ubiquitous on PHP 8.1+ (when AVIF compiled in).
 *   4. ImageMagick binary (magick/convert) — exec; common on shared hosts.
 *   5. avifenc        — exec; the libavif reference encoder, best quality control.
 *   6. cavif          — exec; Rust encoder, last resort.
 *
 * The order is fixed for predictability but each converter self-reports why it is
 * not operational, so the log is actionable regardless of which ones are present.
 */
class AvifStack
{
    /** @var AbstractAvifConverter[] */
    private $converters;

    /**
     * @param ?AbstractAvifConverter[] $converters  Inject converters (for tests).
     *        When null, the default production stack is built in priority order.
     */
    public function __construct(?array $converters = null)
    {
        self::ensureVendorAutoloader();
        $this->converters = ($converters === null) ? self::defaultConverters() : $converters;
    }

    /**
     * Make sure Composer's vendor autoloader is registered before any AVIF converter runs.
     *
     * The plugin does NOT register the vendor autoloader globally — to keep front-end
     * requests lean (serving a cached image must not pay for loading webp-convert), it is
     * pulled in lazily only at the code paths that need vendor classes (e.g. TestRun,
     * WebPOnDemand, ConvertHelperIndependent). The AVIF stack is one of those paths: the
     * exec-based converters (avifenc/cavif/magick) reference \ExecWithFallback\ExecWithFallback
     * and \LocateBinaries\LocateBinaries. AvifStack is the single chokepoint every AVIF
     * operation goes through (self-test, the AVIF admin notice, and conversion), so loading
     * the autoloader here guarantees those classes resolve. Without this, isOperational()
     * fatals with "Class ExecWithFallback\ExecWithFallback not found" the first time the AVIF
     * self-test runs (the WebP path happened to load the autoloader; the AVIF path did not).
     *
     * Public + idempotent so any other AVIF/exec entry point (e.g. the self-test's
     * "can PHP exec external binaries?" probe, which runs before the stack is built) can
     * call it to guarantee the exec-helper classes resolve.
     */
    public static function ensureVendorAutoloader()
    {
        // Already resolvable (e.g. a WebP code path already pulled vendor/autoload.php in
        // this request, registering Composer's autoloader) — nothing to do. Use the default
        // autoload=true so a registered-but-not-yet-referenced class counts as available.
        if (class_exists('\ExecWithFallback\ExecWithFallback')) {
            return;
        }
        if (defined('MAGIC_CONVERT_PLUGIN_DIR')) {
            $autoload = MAGIC_CONVERT_PLUGIN_DIR . '/vendor/autoload.php';
            if (is_file($autoload)) {
                include_once $autoload;
            }
        }
    }

    /**
     * Build the default production stack in priority order.
     *
     * @return AbstractAvifConverter[]
     */
    public static function defaultConverters()
    {
        return [
            new ImagickAvif(),
            new VipsAvif(),
            new GdAvif(),
            new MagickBinaryAvif(),
            new AvifEncBinary(),
            new CavifBinary(),
        ];
    }

    /**
     * The ids of the default production stack, in priority order — WITHOUT instantiating
     * any converter object.
     *
     * This is the SINGLE SOURCE OF TRUTH for "which AVIF converters exist and in what default
     * order". Config::getDefaultFormats() seeds formats.avif.converters from it, and submit.php
     * whitelists posted ids against it. It deliberately returns plain strings so callers (Config,
     * the sanitizer) can reference the AVIF stack WITHOUT loading the heavy converter classes /
     * exec helpers — preserving the plugin's lazy-vendor-autoloader contract.
     *
     * Keep this in lock-step with defaultConverters() (same ids, same order).
     *
     * @return string[]  e.g. ['imagick','vips','gd','magick-binary','avifenc','cavif'].
     */
    public static function defaultConverterIds()
    {
        return ['imagick', 'vips', 'gd', 'magick-binary', 'avifenc', 'cavif'];
    }

    /**
     * Instantiate the concrete converter for a given id, or null when the id is unknown.
     *
     * The mapping mirrors defaultConverters() one-to-one. Unknown ids return null so callers
     * can skip them (a hand-edited config could carry a stale/typo id).
     *
     * @param  string  $id
     * @return AbstractAvifConverter|null
     */
    private static function makeById($id)
    {
        switch ($id) {
            case 'imagick':       return new ImagickAvif();
            case 'vips':          return new VipsAvif();
            case 'gd':            return new GdAvif();
            case 'magick-binary': return new MagickBinaryAvif();
            case 'avifenc':       return new AvifEncBinary();
            case 'cavif':         return new CavifBinary();
            default:              return null;
        }
    }

    /**
     * Build a stack from a config-driven converter list (formats.avif.converters).
     *
     * This is what makes the AVIF stack honour the user's settings — the reorderable,
     * per-converter activate/deactivate list configured in the AVIF section. It mirrors how the
     * WebP path honours config['converters'] (order = try order; 'deactivated' = skip).
     *
     * Each list item is an associative array shaped like {converter:<id>, deactivated?:bool},
     * exactly as Config seeds it and submit.php sanitizes it.
     *
     * Resolution rules (chosen to honour deliberate user intent while staying defensive against a
     * missing/corrupt config — never crashing, never producing a confusing partial stack):
     *
     *   - $converterList is not a non-empty array (missing/pre-migration/malformed)
     *       -> fall back to the FULL default stack. With AVIF freshly enabled but the per-format
     *          'converters' key somehow absent, conversion still works out of the box.
     *   - The list references NO known converter id at all (e.g. every entry is a stale/typo id)
     *       -> treat as malformed -> FULL default stack.
     *   - The list references known ids but the user deactivated EVERY one
     *       -> honour it: return an EMPTY stack. convert() then throws a clear "No AVIF converters
     *          are configured" message — the same outcome the WebP path gives when all its
     *          converters are deactivated (parity, and it respects an explicit user choice).
     *   - Otherwise -> the operational converters, in the configured order, deactivated ones
     *          skipped, unknown ids skipped, duplicate ids collapsed to first occurrence.
     *
     * @param  mixed  $converterList  Typically array<int,array{converter:string,deactivated?:bool}>.
     * @return self
     */
    public static function fromConverterList($converterList)
    {
        if (!is_array($converterList) || count($converterList) === 0) {
            // Missing / malformed / pre-migration: full default stack (never empty).
            return new self();
        }

        $known = self::defaultConverterIds();
        $converters = [];
        $seen = [];
        $sawKnownId = false;

        foreach ($converterList as $item) {
            $id = (is_array($item) && isset($item['converter'])) ? $item['converter'] : null;
            if ($id === null || !in_array($id, $known, true)) {
                // Missing or unknown converter id — ignore it.
                continue;
            }
            $sawKnownId = true;
            if (isset($seen[$id])) {
                // Duplicate id — first occurrence (and its position) wins.
                continue;
            }
            $seen[$id] = true;
            if (!empty($item['deactivated'])) {
                // User turned this converter off for AVIF — skip it.
                continue;
            }
            $converter = self::makeById($id);
            if ($converter === null) {
                // Defence in depth: $id passed the defaultConverterIds() whitelist above, so
                // makeById() should always resolve it. Guard anyway so a future edit that adds an
                // id to defaultConverterIds() but forgets the makeById() case can never push a null
                // into the stack (which would fatal later at $converter->label()).
                continue;
            }
            $converters[] = $converter;
        }

        if (!$sawKnownId) {
            // The list named no recognisable converter at all — treat as malformed and fall back
            // to the full default stack rather than failing every conversion.
            return new self();
        }

        // Known ids were present; honour the selection even if it is empty (all deactivated).
        return new self($converters);
    }

    /**
     * Convert $source to AVIF at $destination using the first operational converter.
     *
     * @param  string  $source       absolute path to an existing source image.
     * @param  string  $destination  absolute path to write the AVIF to.
     * @param  array   $options      quality/speed/metadata/jobs (see AbstractAvifConverter).
     * @return array{converter:string,log:string}  id of the converter that succeeded + a
     *         markdown log of what was tried.
     * @throws AvifStackException  when no converter could encode (message lists all reasons).
     */
    public function convert($source, $destination, array $options)
    {
        $log = [];
        $reasons = [];

        foreach ($this->converters as $converter) {
            $label = $converter->label();

            $op = $converter->isOperational();
            if (empty($op['operational'])) {
                $reason = isset($op['reason']) ? $op['reason'] : 'not operational';
                $log[] = '- **' . $label . '**: skipped — ' . $reason;
                $reasons[$converter->id()] = $reason;
                continue;
            }

            try {
                $converter->convert($source, $destination, $options);
                $log[] = '- **' . $label . '**: SUCCESS';
                return [
                    'converter' => $converter->id(),
                    'log' => implode("\n", $log),
                ];
            } catch (\Throwable $e) {
                $reason = $e->getMessage();
                $log[] = '- **' . $label . '**: failed — ' . $reason;
                $reasons[$converter->id()] = $reason;
                // Make sure a partial file from a failed attempt never lingers for the
                // next converter (each converter writes to the same destination path).
                if (@file_exists($destination)) {
                    @unlink($destination);
                }
            }
        }

        throw new AvifStackException(self::composeFailureMessage($reasons), $reasons);
    }

    /**
     * Compose the aggregate "no converter worked" message from per-converter reasons.
     *
     * Made static + public so it can be unit-tested directly and so the self-test
     * page can reuse the exact same wording.
     *
     * @param  array<string,string>  $reasons  converter-id => reason
     * @return string
     */
    public static function composeFailureMessage(array $reasons)
    {
        if (empty($reasons)) {
            return 'No AVIF converters are configured.';
        }
        $lines = ['No AVIF converter could encode this image. Tried:'];
        foreach ($reasons as $id => $reason) {
            $lines[] = '  - ' . $id . ': ' . $reason;
        }
        return implode("\n", $lines);
    }

    /**
     * Operationality report for the self-test page: every converter and whether it
     * is usable right now (with the precise reason when not).
     *
     * @return array<int,array{id:string,label:string,operational:bool,reason:string}>
     */
    public function selfTest()
    {
        $rows = [];
        foreach ($this->converters as $converter) {
            $op = $converter->isOperational();
            $rows[] = [
                'id' => $converter->id(),
                'label' => $converter->label(),
                'operational' => !empty($op['operational']),
                'reason' => isset($op['reason']) ? $op['reason'] : '',
            ];
        }
        return $rows;
    }

    /**
     * Is at least one converter in the stack operational?
     *
     * @return bool
     */
    public function isOperational()
    {
        foreach ($this->converters as $converter) {
            $op = $converter->isOperational();
            if (!empty($op['operational'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The converters in the stack, in order (mostly for tests / introspection).
     *
     * @return AbstractAvifConverter[]
     */
    public function converters()
    {
        return $this->converters;
    }
}
