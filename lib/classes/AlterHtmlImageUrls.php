<?php

namespace MagicConvert;

/**
 * Class AlterHtmlImageUrls - convert image urls to webp.
 *
 * As of Phase 2.4 this extends the FORKED ImageUrlReplacer (MagicConvert\AlterHtml\ImageUrlReplacer)
 * instead of the vendored dom-util-for-webp class.
 *
 * URL-REPLACEMENT MODE IS WEBP-ONLY BY DESIGN: a plain URL swap rewrites the markup that every
 * browser receives, so it cannot negotiate per-browser between avif and webp from the same cached
 * HTML. WebP (near-universal support) is therefore the only safe single-format choice here. To also
 * serve AVIF, use picture-tag mode (AlterHtmlPicture). This is surfaced to the admin in the
 * alter-html options UI.
 */

use \MagicConvert\AlterHtmlHelper;
use \MagicConvert\AlterHtml\ImageUrlReplacer;

class AlterHtmlImageUrls extends ImageUrlReplacer
{
    public function replaceUrl($url) {
        // WEBP-ONLY by design (see class docblock).
        return AlterHtmlHelper::getWebPUrl($url, null);
    }

    public function attributeFilter($attrName) {
        // Allow "src", "srcset" and data-attributes that smells like they are used for images
        // The following rule matches all attributes used for lazy loading images that we know of
        return preg_match('#^(src|srcset|poster|(data-[^=]*(lazy|small|slide|img|large|src|thumb|source|set|bg-url)[^=]*))$#i', $attrName);

        // If you want to limit it further, only allowing attributes known to be used for lazy load,
        // use the following regex instead:
        //return preg_match('#^(src|srcset|data-(src|srcset|cvpsrc|cvpset|thumb|bg-url|large_image|lazyload|source-url|srcsmall|srclarge|srcfull|slide-img|lazy-original))$#i', $attrName);
    }

}
