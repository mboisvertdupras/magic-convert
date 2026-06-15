<?php

namespace MagicConvert\Avif;

class AvifStack
{
    /** @var AbstractAvifConverter[] */
    private $converters;

    /**
     * @param ?AbstractAvifConverter[] $converters
     */
    public function __construct(?array $converters = null)
    {
        self::ensureVendorAutoloader();
        $this->converters = ($converters === null) ? self::defaultConverters() : $converters;
    }

    public static function ensureVendorAutoloader()
    {
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
     * @return string[]
     */
    public static function defaultConverterIds()
    {
        return ['imagick', 'vips', 'gd', 'magick-binary', 'avifenc', 'cavif'];
    }

    /**
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
     * @param  mixed  $converterList  array<int,array{converter:string,deactivated?:bool}>
     * @return self
     */
    public static function fromConverterList($converterList)
    {
        if (!is_array($converterList) || count($converterList) === 0) {
            return new self();
        }

        $known = self::defaultConverterIds();
        $converters = [];
        $seen = [];
        $sawKnownId = false;

        foreach ($converterList as $item) {
            $id = (is_array($item) && isset($item['converter'])) ? $item['converter'] : null;
            if ($id === null || !in_array($id, $known, true)) {
                continue;
            }
            $sawKnownId = true;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (!empty($item['deactivated'])) {
                continue;
            }
            $converter = self::makeById($id);
            if ($converter === null) {
                continue;
            }
            $converters[] = $converter;
        }

        if (!$sawKnownId) {
            return new self();
        }

        return new self($converters);
    }

    /**
     * @param  AbstractAvifConverter[]  $converters
     * @return AbstractAvifConverter[]
     */
    public static function orderPreferringOutOfProcess(array $converters)
    {
        $outOfProcess = [];
        $inProcess = [];
        foreach ($converters as $converter) {
            if ($converter instanceof AbstractAvifConverter && $converter->reclaimsMemoryOnExit()) {
                $outOfProcess[] = $converter;
            } else {
                $inProcess[] = $converter;
            }
        }
        return array_merge($outOfProcess, $inProcess);
    }

    /**
     * @param  string  $source
     * @param  string  $destination
     * @param  array   $options
     * @return array{converter:string,log:string}
     * @throws AvifStackException
     */
    public function convert($source, $destination, array $options)
    {
        $log = [];
        $reasons = [];

        foreach (self::orderPreferringOutOfProcess($this->converters) as $converter) {
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
                if (@file_exists($destination)) {
                    @unlink($destination);
                }
            }
        }

        throw new AvifStackException(self::composeFailureMessage($reasons), $reasons);
    }

    /**
     * @param  array<string,string>  $reasons
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
     * @return AbstractAvifConverter[]
     */
    public function converters()
    {
        return $this->converters;
    }
}
