<?php

namespace MagicConvert;

class OutputFormat
{
    /** @var string */
    private $id;

    /** @var string */
    private $extension;

    /** @var string */
    private $mimeType;

    /** @var string */
    private $cacheDirName;

    const DEFAULT_ID = 'webp';

    /** @var array<string,OutputFormat>|null */
    private static $registry = null;

    private function __construct($id, $extension, $mimeType, $cacheDirName)
    {
        $this->id = $id;
        $this->extension = $extension;
        $this->mimeType = $mimeType;
        $this->cacheDirName = $cacheDirName;
    }

    /**
     * @return array<string,OutputFormat>
     */
    private static function registry()
    {
        if (self::$registry === null) {
            $formats = [
                new self('webp', 'webp', 'image/webp', 'webp-images'),
                new self('avif', 'avif', 'image/avif', 'avif-images'),
            ];
            self::$registry = [];
            foreach ($formats as $format) {
                self::$registry[$format->id] = $format;
            }
        }
        return self::$registry;
    }

    /**
     * @param  string  $id
     * @return OutputFormat
     * @throws \InvalidArgumentException
     */
    public static function byId($id)
    {
        $registry = self::registry();
        if (!isset($registry[$id])) {
            throw new \InvalidArgumentException(
                'Unknown output format id: ' . (is_string($id) ? $id : gettype($id)) .
                '. Known formats: ' . implode(', ', array_keys($registry)) . '.'
            );
        }
        return $registry[$id];
    }

    /**
     * @return OutputFormat
     */
    public static function webp()
    {
        return self::byId(self::DEFAULT_ID);
    }

    /**
     * @param  OutputFormat|string|null  $format
     * @return OutputFormat
     * @throws \InvalidArgumentException
     */
    public static function coerce($format = null)
    {
        if ($format === null) {
            return self::byId(self::DEFAULT_ID);
        }
        if ($format instanceof self) {
            return $format;
        }
        return self::byId((string) $format);
    }

    /**
     * @return OutputFormat[]
     */
    public static function all()
    {
        return array_values(self::registry());
    }

    /**
     * @return string[]
     */
    public static function ids()
    {
        return array_keys(self::registry());
    }

    /** @return string */
    public function id()
    {
        return $this->id;
    }

    /** @return string */
    public function extension()
    {
        return $this->extension;
    }

    /** @return string */
    public function dotExtension()
    {
        return '.' . $this->extension;
    }

    /** @return string */
    public function mimeType()
    {
        return $this->mimeType;
    }

    /** @return string */
    public function cacheDirName()
    {
        return $this->cacheDirName;
    }

    /** @return bool */
    public function isDefault()
    {
        return $this->id === self::DEFAULT_ID;
    }
}
