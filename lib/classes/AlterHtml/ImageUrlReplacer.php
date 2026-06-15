<?php

namespace MagicConvert\AlterHtml;

use KubAT\PhpSimple\HtmlDomParser;

/**
 * This is a fork of rosell-dk/dom-util-for-webp (src/ImageUrlReplacer.php), MIT-licensed:
 *
 *   Copyright (c) Bjørn Rosell
 *   https://github.com/rosell-dk/dom-util-for-webp
 *
 *   Permission is hereby granted, free of charge, to any person obtaining a copy of this software
 *   and associated documentation files (the "Software"), to deal in the Software without restriction,
 *   ... (full MIT terms).
 */
class ImageUrlReplacer
{

    public static $searchInTags = ['img', 'source', 'input', 'iframe', 'div', 'li', 'link', 'a', 'section', 'video'];

    final public function __construct()
    {
    }

    /**
     * @return string|null
     **/
    public function replaceUrl($url)
    {
        if (!preg_match('#(png|jpe?g)$#', $url)) {
            return null;
        }
        return $url . '.webp';
    }

    public function replaceUrlOr($url, $returnValueIfDenied)
    {
        $url = $this->replaceUrl($url);
        return (isset($url) ? $url : $returnValueIfDenied);
    }

    public function handleSrc($attrValue)
    {
        return $this->replaceUrlOr($attrValue, $attrValue);
    }

    public function handleSrcSet($attrValue)
    {
        $srcsetArr = explode(',', $attrValue);
        foreach ($srcsetArr as $i => $srcSetEntry) {
            $srcSetEntry = trim($srcSetEntry);
            $entryParts = preg_split('/\s+/', $srcSetEntry, 2);
            if (count($entryParts) == 2) {
                list($src, $descriptors) = $entryParts;
            } else {
                $src = $srcSetEntry;
                $descriptors = null;
            }

            $webpUrl = $this->replaceUrlOr($src, false);
            if ($webpUrl !== false) {
                $srcsetArr[$i] = $webpUrl . (isset($descriptors) ? ' ' . $descriptors : '');
            }
        }
        return implode(', ', $srcsetArr);
    }

    public function looksLikeSrcSet($value)
    {
        if (preg_match('#\s\d*(w|x)#', $value)) {
            return true;
        }
        return false;
    }

    public function handleAttribute($value)
    {
        if (self::looksLikeSrcSet($value)) {
            return self::handleSrcSet($value);
        }
        return self::handleSrc($value);
    }

    public function attributeFilter($attrName)
    {
        $attrName = strtolower($attrName);
        if (($attrName == 'src') || ($attrName == 'srcset') || (strpos($attrName, 'data-') === 0)) {
            return true;
        }
        return false;
    }

    public function processCSSRegExCallback($matches)
    {
        list($all, $pre, $quote, $url, $post) = $matches;
        return $pre . $this->replaceUrlOr($url, $url) . $post;
    }

    public function processCSS($css)
    {
        $declarations = explode(';', $css);
        foreach ($declarations as $i => &$declaration) {
            if (preg_match('#(background(-image)?)\\s*:#', $declaration)) {
                $parts = explode(',', $declaration);
                foreach ($parts as &$part) {
                    $regex = '#(url\\s*\\(([\\"\\\']?))([^\\\'\\";\\)]*)(\\2\\s*\\))#';
                    $part = preg_replace_callback(
                        $regex,
                        array($this, 'processCSSRegExCallback'),
                        $part
                    );
                }
                $declarations[$i] = implode(',', $parts);
            }
        }
        return implode(';', $declarations);
    }

    public function replaceHtml($html)
    {
        if ($html == '') {
            return '';
        }

        $dom = HtmlDomParser::str_get_html($html, false, true, 'UTF-8', false);

        defined('MAX_FILE_SIZE') || define('MAX_FILE_SIZE', 600000);

        if ($dom === false) {
            if (strlen($html) > MAX_FILE_SIZE) {
                return '<!-- Alter HTML was skipped because the HTML is too big to process! ' .
                    '(limit is set to ' . MAX_FILE_SIZE . ' bytes) -->' . "\n" . $html;
            }
            return '<!-- Alter HTML was skipped because the helper library refused to process the html -->' .
                "\n" . $html;
        }

        foreach (self::$searchInTags as $tagName) {
            $elems = $dom->find($tagName);
            foreach ($elems as $index => $elem) {
                foreach ($elem->getAllAttributes() as $attrName => $attrValue) {
                    if ($this->attributeFilter($attrName)) {
                        $elem->setAttribute($attrName, $this->handleAttribute($attrValue));
                    }
                }
            }
        }

        $elems = $dom->find('style');
        foreach ($elems as $index => $elem) {
            $css = $this->processCSS($elem->innertext);
            if ($css != $elem->innertext) {
                $elem->innertext = $css;
            }
        }

        $elems = $dom->find('*[style]');
        foreach ($elems as $index => $elem) {
            $css = $this->processCSS($elem->style);
            if ($css != $elem->style) {
                $elem->style = $css;
            }
        }

        return $dom->save();
    }

    public static function replace($html)
    {
        $iur = new static();
        return $iur->replaceHtml($html);
    }
}
