<?php

namespace MagicConvert;

/**
 * Class AlterHtmlPicture - convert an <img> tag to a <picture> tag and add converted versions.
 *
 * As of Phase 2.4 this extends the FORKED, multi-format PictureTags
 * (MagicConvert\AlterHtml\PictureTags) instead of the vendored dom-util-for-webp class. It emits
 * one <source> per enabled format (avif first, then webp) in browser-preference order, each gated
 * on whether the corresponding converted file actually exists.
 */

use \MagicConvert\AlterHtmlHelper;
use \MagicConvert\OutputFormat;
use \MagicConvert\AlterHtml\PictureTags;

class AlterHtmlPicture extends PictureTags
{
    /**
     * Map a source URL to the converted URL for a specific output format, or null if the
     * converted file for that format isn't available (file-missing, bigger-than-source, etc).
     * Reuses AlterHtmlHelper's existing URL-mapping + file-exists logic, generalized over format.
     */
    public function replaceUrlForFormat($url, $formatId)
    {
        return AlterHtmlHelper::getConvertedUrl($url, null, $formatId);
    }

    /**
     * Formats to emit, MOST-PREFERRED FIRST. Avif precedes webp so an avif-capable browser picks
     * it. Avif is only included when serving is enabled (the 'avif-enabled' autoloaded option,
     * written by Config::updateAutoloadedOptions). With avif disabled this is ['webp'] — identical
     * to the historical webp-only behaviour.
     */
    public function enabledFormatsInPreferenceOrder()
    {
        AlterHtmlHelper::getOptions();
        $options = AlterHtmlHelper::$options;
        if (isset($options['avif-enabled']) && $options['avif-enabled']) {
            return ['avif', 'webp'];
        }
        return ['webp'];
    }

    /**
     * Mime type for a format id, sourced from the canonical OutputFormat registry.
     */
    protected function mimeTypeForFormat($formatId)
    {
        return OutputFormat::byId($formatId)->mimeType();
    }
}
