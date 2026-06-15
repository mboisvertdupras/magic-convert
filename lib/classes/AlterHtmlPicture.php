<?php

namespace MagicConvert;

use \MagicConvert\AlterHtmlHelper;
use \MagicConvert\OutputFormat;
use \MagicConvert\AlterHtml\PictureTags;

class AlterHtmlPicture extends PictureTags
{
    public function replaceUrlForFormat($url, $formatId)
    {
        return AlterHtmlHelper::getConvertedUrl($url, null, $formatId);
    }

    public function enabledFormatsInPreferenceOrder()
    {
        AlterHtmlHelper::getOptions();
        $options = AlterHtmlHelper::$options;
        if (isset($options['avif-enabled']) && $options['avif-enabled']) {
            return ['avif', 'webp'];
        }
        return ['webp'];
    }

    protected function mimeTypeForFormat($formatId)
    {
        return OutputFormat::byId($formatId)->mimeType();
    }
}
