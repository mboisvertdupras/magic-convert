<?php

namespace MagicConvert\Avif;

abstract class AbstractAvifConverter
{
    const DEFAULT_QUALITY = 30;

    const DEFAULT_SPEED = 6;

    abstract public function id();

    abstract public function label();

    /**
     * @return array{operational:bool,reason:string}
     */
    abstract public function isOperational();

    /**
     * @param  string  $source
     * @param  string  $destination
     * @param  array   $options
     * @return void
     * @throws \Exception
     */
    abstract public function convert($source, $destination, array $options);

    /**
     * @return bool
     */
    public function reclaimsMemoryOnExit()
    {
        return false;
    }

    /**
     * @param  array  $options
     * @return int
     */
    protected function quality(array $options)
    {
        $q = isset($options['quality']) ? (int) $options['quality'] : self::DEFAULT_QUALITY;
        return self::clamp($q, 0, 100);
    }

    /**
     * @param  array  $options
     * @return int
     */
    protected function speed(array $options)
    {
        $s = isset($options['speed']) ? (int) $options['speed'] : self::DEFAULT_SPEED;
        return self::clamp($s, 0, 10);
    }

    /**
     * @param  array  $options
     * @return bool
     */
    protected function stripMetadata(array $options)
    {
        return isset($options['metadata']) && ($options['metadata'] === 'none');
    }

    /**
     * @param  int  $speed
     * @return int
     */
    public static function speedToVipsEffort($speed)
    {
        $speed = self::clamp((int) $speed, 0, 10);
        return self::clamp(9 - $speed, 0, 9);
    }

    /**
     * @param  int  $speed
     * @return int
     */
    public static function speedToCavifSpeed($speed)
    {
        return self::clamp((int) $speed, 1, 10);
    }

    /**
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
