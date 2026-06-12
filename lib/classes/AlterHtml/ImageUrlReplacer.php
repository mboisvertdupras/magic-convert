<?php

namespace MagicConvert\AlterHtml;

use KubAT\PhpSimple\HtmlDomParser;

/**
 *  Highly configurable class for replacing image URLs in HTML (both src and srcset syntax).
 *
 * ---------------------------------------------------------------------------------------------------
 * ATTRIBUTION (MIT)
 *
 * This is a fork of rosell-dk/dom-util-for-webp (src/ImageUrlReplacer.php), MIT-licensed:
 *
 *   Copyright (c) Bjørn Rosell
 *   https://github.com/rosell-dk/dom-util-for-webp
 *
 *   Permission is hereby granted, free of charge, to any person obtaining a copy of this software
 *   and associated documentation files (the "Software"), to deal in the Software without restriction,
 *   ... (full MIT terms).
 * ---------------------------------------------------------------------------------------------------
 *
 *  Uses kub-at/php-simple-html-dom-parser - a library for easily manipulating HTML by means of a DOM.
 *  The great thing about this library is that it supports working on invalid HTML and it only applies the
 *  changes you make - very gently (however, not as gently as we do in PictureTags).
 *
 *  Behaviour can be customized by overriding the public methods (replaceUrl, $searchInTags, etc).
 *
 *  ===========================================================================================
 *  URL-REPLACEMENT MODE IS SINGLE-FORMAT (WEBP-ONLY) BY DESIGN.
 *
 *  A plain URL swap rewrites the markup that EVERY browser receives — there is no per-request
 *  content negotiation at the HTML layer, so we cannot serve avif to avif-capable browsers and
 *  webp to the rest from the same cached HTML. Picking avif here would break every browser that
 *  doesn't support it. Therefore replaceUrl() targets WebP only (the near-universally supported
 *  next-gen format). To ALSO serve AVIF, use picture-tag mode (PictureTags), which emits a
 *  <source> per format and lets the browser choose. This is surfaced to the admin in the
 *  alter-html options UI as well.
 *  ===========================================================================================
 *
 *  Default behaviour:
 *  - The modified URL is the same as the original, with ".webp" appended                   (replaceUrl)
 *  - Limits to these tags (see $searchInTags)
 *  - Limits to these attributes: "src", "srcset" and any attribute starting with "data-"   (attributeFilter)
 *  - Only replaces URLs that ends with "png", "jpg" or "jpeg" (no query strings either)    (replaceUrl)
 */
class ImageUrlReplacer
{

    // define tags to be searched.
    public static $searchInTags = ['img', 'source', 'input', 'iframe', 'div', 'li', 'link', 'a', 'section', 'video'];

    /**
     * Empty constructor for preventing child classes from creating constructors.
     * (donor library, see #21)
     * @return  void
     */
    final public function __construct()
    {
    }

    /**
     * @return string|null webp url or, if URL should not be changed, return nothing
     **/
    public function replaceUrl($url)
    {
        // WEBP-ONLY by design (see class docblock): URL replacement cannot negotiate per-browser.
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
        // $attrValue is ie: <img data-x="1.jpg 1000w, 2.jpg">
        $srcsetArr = explode(',', $attrValue);
        foreach ($srcsetArr as $i => $srcSetEntry) {
            // $srcSetEntry is ie "image.jpg 520w", but can also lack width, ie just "image.jpg"
            // it can also be ie "image.jpg 2x"
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

    /**
     *  Test if attribute value looks like it has srcset syntax.
     */
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
                // https://regexr.com/46qdg
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

        // MAX_FILE_SIZE is defined in simple_html_dom. For safety, ensure it is defined.
        defined('MAX_FILE_SIZE') || define('MAX_FILE_SIZE', 600000);

        if ($dom === false) {
            if (strlen($html) > MAX_FILE_SIZE) {
                return '<!-- Alter HTML was skipped because the HTML is too big to process! ' .
                    '(limit is set to ' . MAX_FILE_SIZE . ' bytes) -->' . "\n" . $html;
            }
            return '<!-- Alter HTML was skipped because the helper library refused to process the html -->' .
                "\n" . $html;
        }

        // Replace attributes (src, srcset, data-src, etc)
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

        // Replace <style> elements
        $elems = $dom->find('style');
        foreach ($elems as $index => $elem) {
            $css = $this->processCSS($elem->innertext);
            if ($css != $elem->innertext) {
                $elem->innertext = $css;
            }
        }

        // Replace "style" attributes
        $elems = $dom->find('*[style]');
        foreach ($elems as $index => $elem) {
            $css = $this->processCSS($elem->style);
            if ($css != $elem->style) {
                $elem->style = $css;
            }
        }

        return $dom->save();
    }

    /* Main replacer function */
    public static function replace($html)
    {
        $iur = new static();
        return $iur->replaceHtml($html);
    }
}
