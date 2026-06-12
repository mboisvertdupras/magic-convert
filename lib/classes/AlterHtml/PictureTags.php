<?php

namespace MagicConvert\AlterHtml;

use KubAT\PhpSimple\HtmlDomParser;

/**
 * Class PictureTags - convert an <img> tag to a <picture> tag and add converted (webp / avif) versions.
 *
 * ---------------------------------------------------------------------------------------------------
 * ATTRIBUTION (MIT)
 *
 * This is a fork of rosell-dk/dom-util-for-webp (src/PictureTags.php), MIT-licensed:
 *
 *   Copyright (c) Bjørn Rosell
 *   https://github.com/rosell-dk/dom-util-for-webp
 *
 *   Permission is hereby granted, free of charge, to any person obtaining a copy of this software
 *   and associated documentation files (the "Software"), to deal in the Software without restriction,
 *   including without limitation the rights to use, copy, modify, merge, publish, distribute,
 *   sublicense, and/or sell copies of the Software ... (full MIT terms).
 *
 * The donor library's own attribution: "Code is based on code from the ShortPixel plugin, which in
 * turn used code from Responsify WP plugin."
 *
 * WHY FORKED (not wrapped): the donor library hardcodes a single WebP <source>. Magic Convert needs
 * to emit MULTIPLE <source> tags (avif first, then webp) in browser-preference order, each gated on
 * whether the corresponding converted file actually exists. That is a structural change to
 * replaceCallback(), so we fork rather than subclass. Per the fork rules, vendor/ is never modified.
 * ---------------------------------------------------------------------------------------------------
 *
 * It works like this:
 *
 * 1. Remove existing <picture> tags and their content - replace with tokens in order to reinsert later
 * 2. Process <img> tags.
 *    - The tags are found with regex.
 *    - The attributes are parsed with DOMDocument if it exists, otherwise with the Simple Html Dom library.
 * 3. Re-insert the existing <picture> tags
 *
 * This procedure is very gentle and needle-like. No need for a complete parse - so invalid HTML is no big issue.
 *
 * GENERALIZATION (vs. donor):
 *  - replaceUrl($url) is replaced by replaceUrlForFormat($url, $formatId): subclasses map a source URL to
 *    the converted URL for a SPECIFIC format ('webp' / 'avif'), or null if that format isn't available.
 *  - enabledFormatsInPreferenceOrder() lists the formats to try, most-preferred first (avif before webp).
 *  - replaceCallback() emits one <source type="image/<fmt>"> per format that produced a full set of URLs,
 *    keeping the original <img> as the fallback and preserving all original attributes.
 */
class PictureTags
{

    /**
     * Empty constructor for preventing child classes from creating constructors.
     *
     * We do this because otherwise the "new static()" call inside the ::replace() method
     * would be unsafe. (donor library, see #21)
     * @return  void
     */
    final public function __construct()
    {
        $this->existingPictureTags = [];
    }

    private $existingPictureTags;

    /**
     * Map a source URL to the converted URL for a given output format.
     *
     * @param  string  $url       source image url
     * @param  string  $formatId  'webp' | 'avif'
     * @return string|null  converted url, or null if not available for this format
     */
    public function replaceUrlForFormat($url, $formatId)
    {
        // Default behaviour mirrors the donor (webp-only, append ".webp"). Subclasses override
        // this to consult the real cache + per-format file-exists logic.
        if ($formatId !== 'webp') {
            return null;
        }
        if (!preg_match('#(png|jpe?g)$#', $url)) {
            return null;
        }
        return $url . '.webp';
    }

    /**
     * The output formats to emit <source> tags for, MOST-PREFERRED FIRST.
     *
     * Browser preference order: a browser that accepts several formats picks the first matching
     * <source>, so avif must precede webp. Default is webp-only (zero-config baseline); the Magic
     * Convert subclass returns ['avif', 'webp'] when avif serving is enabled.
     *
     * @return string[]
     */
    public function enabledFormatsInPreferenceOrder()
    {
        return ['webp'];
    }

    /**
     * Mime type for a format id. Kept tiny + dependency-free so this class stays portable.
     *
     * @param  string  $formatId
     * @return string
     */
    protected function mimeTypeForFormat($formatId)
    {
        return 'image/' . $formatId;
    }

    public function replaceUrlForFormatOr($url, $formatId, $returnValueIfDenied)
    {
        $url = $this->replaceUrlForFormat($url, $formatId);
        return (isset($url) ? $url : $returnValueIfDenied);
    }

    /**
     * Look for attribute such as "src", but also with prefixes such as "data-lazy-src" and "data-src".
     *
     * @param  array  $attributes  an array of all attributes for the element
     * @param  string  $attrName    ie "src", "srcset" or "sizes"
     * @return array  attrName => value, for each prefix that is present
     */
    private static function findAttributesWithNameOrPrefixed($attributes, $attrName)
    {
        $tryThesePrefixes = ['', 'data-lazy-', 'data-'];
        $result = [];
        foreach ($tryThesePrefixes as $prefix) {
            $name = $prefix . $attrName;
            if (isset($attributes[$name]) && strlen($attributes[$name])) {
                $result[$name] = trim($attributes[$name]);
            }
        }
        return $result;
    }

    /**
     * Look for attributes such as "data-lazy-src" and "data-src" and prefer them over "src".
     *
     * @return array  ['value' => ..., 'attrName' => ...]
     */
    private static function lazyGet($attributes, $attrName)
    {
        return array(
            'value' =>
                (isset($attributes['data-lazy-' . $attrName]) && strlen($attributes['data-lazy-' . $attrName])) ?
                    trim($attributes['data-lazy-' . $attrName])
                    : (isset($attributes['data-' . $attrName]) && strlen($attributes['data-' . $attrName]) ?
                        trim($attributes['data-' . $attrName])
                        : (isset($attributes[$attrName]) && strlen($attributes[$attrName]) ?
                            trim($attributes[$attrName]) : false)),
            'attrName' =>
                (isset($attributes['data-lazy-' . $attrName]) && strlen($attributes['data-lazy-' . $attrName])) ?
                    'data-lazy-' . $attrName
                    : (isset($attributes['data-' . $attrName]) && strlen($attributes['data-' . $attrName]) ?
                        'data-' . $attrName
                        : (isset($attributes[$attrName]) && strlen($attributes[$attrName]) ? $attrName : false))
        );
    }

    /**
     *  Convert to UTF-8 and encode chars outside of ascii-range.
     */
    private static function textToUTF8WithNonAsciiEncoded($html)
    {
        if (function_exists("mb_convert_encoding")) {
            $html = mb_convert_encoding($html, 'UTF-8');
            $html = mb_encode_numericentity($html, array (0x7f, 0xffff, 0, 0xffff), 'UTF-8');
        }
        return $html;
    }

    private static function getAttributes($html)
    {
        if (class_exists('\\DOMDocument')) {
            $dom = new \DOMDocument();

            if (function_exists("mb_encode_numericentity")) {
                $html = mb_encode_numericentity($html, array (0x7f, 0xffff, 0, 0xffff));  // (donor #41)
            }

            @$dom->loadHTML($html);
            $image = $dom->getElementsByTagName('img')->item(0);
            $attributes = [];
            foreach ($image->attributes as $attr) {
                $attributes[$attr->nodeName] = $attr->nodeValue;
            }
            return $attributes;
        } else {
            $html =  self::textToUTF8WithNonAsciiEncoded($html);
            $dom = HtmlDomParser::str_get_html($html, false, true, 'UTF-8', false);
            if ($dom !== false) {
                $elems = $dom->find('img,IMG');
                foreach ($elems as $index => $elem) {
                    $attributes = [];
                    foreach ($elem->getAllAttributes() as $attrName => $attrValue) {
                        $attributes[strtolower($attrName)] = $attrValue;
                    }
                    return $attributes;
                }
            }
            return [];
        }
    }

    /**
     * Makes a string with all attributes.
     */
    private static function createAttributes($attribute_array)
    {
        $attributes = '';
        foreach ($attribute_array as $attribute => $value) {
            $attributes .= $attribute . '="' . $value . '" ';
        }
        if ($attributes == '') {
            return '';
        }
        // Removes the extra space after the last attribute. Add space before
        return ' ' . substr($attributes, 0, -1);
    }

    /**
     * Build the <source> attribute set for ONE format, given the img's src/srcset attributes.
     *
     * Returns null if this format cannot fully cover the image (we require ALL srcset entries to
     * have a converted variant — see donor #42 — otherwise the responsive set would be broken).
     *
     * @param  string  $formatId
     * @param  array   $srcSetAttributes  attrName => value (from findAttributesWithNameOrPrefixed)
     * @param  array   $srcAttributes     attrName => value
     * @return array|null  source-tag attributes (without the type attribute), or null
     */
    private function buildSourceAttributesForFormat($formatId, $srcSetAttributes, $srcAttributes)
    {
        $atLeastOne = false;
        $sourceTagAttributes = [];

        // Process srcset (also data-srcset etc)
        foreach ($srcSetAttributes as $attrName => $attrValue) {
            $srcsetArr = explode(', ', $attrValue);
            $srcsetArrConverted = [];
            foreach ($srcsetArr as $i => $srcSetEntry) {
                // $srcSetEntry is ie "http://example.com/image.jpg 520w"
                $result = preg_split('/\s+/', trim($srcSetEntry));
                $src = trim($srcSetEntry);
                $width = null;
                if ($result && count($result) >= 2) {
                    list($src, $width) = $result;
                }

                $convertedUrl = $this->replaceUrlForFormatOr($src, $formatId, false);
                if ($convertedUrl == false) {
                    // We want ALL of the sizes in this format. If we cannot have that, this format
                    // is not usable for this image (donor #42).
                    return null;
                } else {
                    if (substr($src, 0, 5) != 'data:') {
                        $atLeastOne = true;
                        $srcsetArrConverted[] = $convertedUrl . (isset($width) ? ' ' . $width : '');
                    }
                }
            }
            $sourceTagAttributes[$attrName] = implode(', ', $srcsetArrConverted);
        }

        foreach ($srcAttributes as $attrName => $attrValue) {
            if (substr($attrValue, 0, 5) == 'data:') {
                // ignore tags with data urls
                return null;
            }
            // Make sure not to override existing srcset with src
            if (!isset($sourceTagAttributes[$attrName . 'set'])) {
                $converted = $this->replaceUrlForFormatOr($attrValue, $formatId, false);
                if ($converted === false) {
                    return null;
                }
                $atLeastOne = true;
                $sourceTagAttributes[$attrName . 'set'] = $converted;
            }
        }

        if (!$atLeastOne) {
            return null;
        }
        return $sourceTagAttributes;
    }

    /**
     *  Replace <img> tag with <picture> tag (emitting one <source> per available format).
     */
    private function replaceCallback($match)
    {
        $imgTag = $match[0];

        // Do nothing with images that have the 'webpexpress-processed' class (idempotency guard).
        // NOTE: the marker string is deliberately kept as the donor's 'webpexpress-processed' (NOT
        // rebranded) so the webp-only <picture> output stays byte-for-byte identical to today's,
        // and so content already processed by the previously-vendored library is still recognised.
        if (strpos($imgTag, 'webpexpress-processed')) {
            return $imgTag;
        }
        $imgAttributes = self::getAttributes($imgTag);

        $sizesInfo = self::lazyGet($imgAttributes, 'sizes');

        $srcSetAttributes = self::findAttributesWithNameOrPrefixed($imgAttributes, 'srcset');
        $srcAttributes = self::findAttributesWithNameOrPrefixed($imgAttributes, 'src');

        if ((!isset($srcSetAttributes['srcset'])) && (!isset($srcAttributes['src']))) {
            // better not mess with this html...
            return $imgTag;
        }

        // add the exclude class so if this content is processed again in another filter,
        // the img is not converted again into a picture
        $imgAttributes['class'] = (isset($imgAttributes['class']) ? $imgAttributes['class'] . " " : "") .
            "webpexpress-processed";

        // Build one <source> per enabled format (most-preferred first => avif before webp).
        $sourceTags = '';
        foreach ($this->enabledFormatsInPreferenceOrder() as $formatId) {
            $sourceTagAttributes = $this->buildSourceAttributesForFormat($formatId, $srcSetAttributes, $srcAttributes);
            if ($sourceTagAttributes === null) {
                continue;
            }
            if ($sizesInfo['value']) {
                $sourceTagAttributes[$sizesInfo['attrName']] = $sizesInfo['value'];
            }
            $sourceTags .= '<source' . self::createAttributes($sourceTagAttributes) .
                ' type="' . $this->mimeTypeForFormat($formatId) . '">';
        }

        if ($sourceTags === '') {
            // No converted variants in any format -> no reason to create a <picture> tag.
            return $imgTag;
        }

        return '<picture>'
            . $sourceTags
            . '<img' . self::createAttributes($imgAttributes) . '>'
            . '</picture>';
    }

    public function removePictureTagsTemporarily($content)
    {
        $this->existingPictureTags[] = $content[0];
        return 'PICTURE_TAG_' . (count($this->existingPictureTags) - 1) . '_';
    }

    public function insertPictureTagsBack($content)
    {
        $numberString = $content[1];
        $numberInt = intval($numberString);
        return $this->existingPictureTags[$numberInt];
    }

    public function replaceHtml($content)
    {
        if (!class_exists('\\DOMDocument') && function_exists('mb_detect_encoding')) {
            if (mb_detect_encoding($content, ["ASCII", "UTF8", "Windows-1251"]) == 'Windows-1251') {
                $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            }
        }

        $this->existingPictureTags = [];

        // Temporarily remove existing <picture> tags (their <img> children must NOT be re-wrapped).
        $content = preg_replace_callback(
            '/<picture[^>]*>.*?<\/picture>/is',
            array($this, 'removePictureTagsTemporarily'),
            $content
        );

        // Replace "<img>" tags
        $content = preg_replace_callback('/<img[^>]*>/i', array($this, 'replaceCallback'), $content);

        // Re-insert <picture> tags that were removed
        $content = preg_replace_callback('/PICTURE_TAG_(\d+)_/', array($this, 'insertPictureTagsBack'), $content);

        return $content;
    }

    /* Main replacer function */
    public static function replace($html)
    {
        $pt = new static();
        return $pt->replaceHtml($html);
    }
}
