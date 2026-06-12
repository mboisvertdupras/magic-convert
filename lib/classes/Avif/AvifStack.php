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
        $this->converters = ($converters === null) ? self::defaultConverters() : $converters;
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
