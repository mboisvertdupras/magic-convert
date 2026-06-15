<?php

namespace MagicConvert\AlterHtml;

use KubAT\PhpSimple\HtmlDomParser;

/**
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
 */
class PictureTags
{

    final public function __construct()
    {
        $this->existingPictureTags = [];
    }

    private $existingPictureTags;

    /**
     * @param  string  $url
     * @param  string  $formatId
     * @return string|null
     */
    public function replaceUrlForFormat($url, $formatId)
    {
        if ($formatId !== 'webp') {
            return null;
        }
        if (!preg_match('#(png|jpe?g)$#', $url)) {
            return null;
        }
        return $url . '.webp';
    }

    /**
     * @return string[]
     */
    public function enabledFormatsInPreferenceOrder()
    {
        return ['webp'];
    }

    /**
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
     * @param  array  $attributes
     * @param  string  $attrName
     * @return array
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
     * @return array
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
                $html = mb_encode_numericentity($html, array (0x7f, 0xffff, 0, 0xffff));
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

    private static function createAttributes($attribute_array)
    {
        $attributes = '';
        foreach ($attribute_array as $attribute => $value) {
            $attributes .= $attribute . '="' . $value . '" ';
        }
        if ($attributes == '') {
            return '';
        }
        return ' ' . substr($attributes, 0, -1);
    }

    /**
     * @param  string  $formatId
     * @param  array   $srcSetAttributes
     * @param  array   $srcAttributes
     * @return array|null
     */
    private function buildSourceAttributesForFormat($formatId, $srcSetAttributes, $srcAttributes)
    {
        $atLeastOne = false;
        $sourceTagAttributes = [];

        foreach ($srcSetAttributes as $attrName => $attrValue) {
            $srcsetArr = explode(', ', $attrValue);
            $srcsetArrConverted = [];
            foreach ($srcsetArr as $i => $srcSetEntry) {
                $result = preg_split('/\s+/', trim($srcSetEntry));
                $src = trim($srcSetEntry);
                $width = null;
                if ($result && count($result) >= 2) {
                    list($src, $width) = $result;
                }

                $convertedUrl = $this->replaceUrlForFormatOr($src, $formatId, false);
                if ($convertedUrl == false) {
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
                return null;
            }
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

    private function replaceCallback($match)
    {
        $imgTag = $match[0];

        if (strpos($imgTag, 'webpexpress-processed')) {
            return $imgTag;
        }
        $imgAttributes = self::getAttributes($imgTag);

        $sizesInfo = self::lazyGet($imgAttributes, 'sizes');

        $srcSetAttributes = self::findAttributesWithNameOrPrefixed($imgAttributes, 'srcset');
        $srcAttributes = self::findAttributesWithNameOrPrefixed($imgAttributes, 'src');

        if ((!isset($srcSetAttributes['srcset'])) && (!isset($srcAttributes['src']))) {
            return $imgTag;
        }

        $imgAttributes['class'] = (isset($imgAttributes['class']) ? $imgAttributes['class'] . " " : "") .
            "webpexpress-processed";

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

        $content = preg_replace_callback(
            '/<picture[^>]*>.*?<\/picture>/is',
            array($this, 'removePictureTagsTemporarily'),
            $content
        );

        $content = preg_replace_callback('/<img[^>]*>/i', array($this, 'replaceCallback'), $content);

        $content = preg_replace_callback('/PICTURE_TAG_(\d+)_/', array($this, 'insertPictureTagsBack'), $content);

        return $content;
    }

    public static function replace($html)
    {
        $pt = new static();
        return $pt->replaceHtml($html);
    }
}
