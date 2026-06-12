<?php

/*
This class is made to NOT be dependent on Wordpress functions and must be kept like that.
It is used by webp-on-demand.php (which does not register an autoloader) and the bulk path,
so it must be a plain, dependency-free value object.
*/
namespace MagicConvert;

/**
 * OutputFormat — immutable value object + registry describing a target image format.
 *
 * Phase 2.1 of the Magic Convert roadmap introduces multi-format output (WebP today,
 * AVIF next, more later). Rather than scatter the format-specific facts (file extension,
 * mime type, cache-dir name) across the conversion core, they are centralised here.
 *
 * DESIGN: adding a third format later means adding ONE registry entry in self::registry()
 * — nothing else in the conversion core needs to learn about it.
 *
 * DEFAULTS / COMPAT: 'webp' is the canonical default everywhere it is threaded through, so
 * a call site that does not (yet) care about format behaves byte-for-byte as before. The
 * webp cache dir name ('webp-images') and extension ('.webp') are deliberately unchanged.
 */
class OutputFormat
{
    /** @var string  Stable id, e.g. 'webp' or 'avif'. Used in config, log paths, etc. */
    private $id;

    /** @var string  File extension WITHOUT the leading dot, e.g. 'webp'. */
    private $extension;

    /** @var string  Mime type, e.g. 'image/webp'. */
    private $mimeType;

    /** @var string  Name of the per-format cache directory, e.g. 'webp-images'. */
    private $cacheDirName;

    /**
     * The canonical default format id. Threaded parameters default to this so existing
     * call sites keep producing identical paths/markers/logs.
     */
    const DEFAULT_ID = 'webp';

    /** @var array<string,OutputFormat>|null  Lazily-built id => instance registry. */
    private static $registry = null;

    /**
     * Private: instances are created exclusively by the registry. Treat the object as
     * immutable (no setters).
     */
    private function __construct($id, $extension, $mimeType, $cacheDirName)
    {
        $this->id = $id;
        $this->extension = $extension;
        $this->mimeType = $mimeType;
        $this->cacheDirName = $cacheDirName;
    }

    /**
     * Build (once) the id => OutputFormat registry.
     *
     * To add a format later, add ONE entry here. Order is meaningful only as a stable
     * iteration order for data-driven consumers (e.g. CachePurge / CacheMover); browser
     * Accept-preference ordering for serving is a separate concern handled at the HTML /
     * htaccess layer (Phase 2.4), not here.
     *
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
     * Look up a format by id.
     *
     * @param  string  $id  Format id ('webp', 'avif', ...).
     * @return OutputFormat
     * @throws \InvalidArgumentException  When the id is not a registered format.
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
     * Convenience accessor for the default (webp) format.
     *
     * @return OutputFormat
     */
    public static function webp()
    {
        return self::byId(self::DEFAULT_ID);
    }

    /**
     * Normalise a "format-ish" argument (an OutputFormat, a format-id string, or null)
     * into an OutputFormat instance. This is what the threaded methods call so callers
     * may pass either an OutputFormat OR a plain id string OR nothing (= webp default).
     *
     * @param  OutputFormat|string|null  $format
     * @return OutputFormat
     * @throws \InvalidArgumentException  When a string id is unknown.
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
     * All registered formats, in registry order.
     *
     * @return OutputFormat[]
     */
    public static function all()
    {
        return array_values(self::registry());
    }

    /**
     * All registered format ids, in registry order.
     *
     * @return string[]
     */
    public static function ids()
    {
        return array_keys(self::registry());
    }

    // --- getters -------------------------------------------------------------

    /** @return string  Stable id, e.g. 'webp'. */
    public function id()
    {
        return $this->id;
    }

    /** @return string  Extension without dot, e.g. 'webp'. */
    public function extension()
    {
        return $this->extension;
    }

    /** @return string  Extension WITH leading dot, e.g. '.webp'. */
    public function dotExtension()
    {
        return '.' . $this->extension;
    }

    /** @return string  Mime type, e.g. 'image/webp'. */
    public function mimeType()
    {
        return $this->mimeType;
    }

    /** @return string  Per-format cache dir name, e.g. 'webp-images'. */
    public function cacheDirName()
    {
        return $this->cacheDirName;
    }

    /** @return bool  True when this is the default (webp) format. */
    public function isDefault()
    {
        return $this->id === self::DEFAULT_ID;
    }
}
