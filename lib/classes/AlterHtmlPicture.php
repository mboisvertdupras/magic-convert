<?php

namespace MagicConvert;

/**
 * Class AlterHtmlPicture - convert an <img> tag to a <picture> tag and add the webp versions of the images
 * Based this code on code from the ShortPixel plugin, which used code from Responsify WP plugin
 */

use \MagicConvert\AlterHtmlHelper;
use DOMUtilForWebP\PictureTags;

class AlterHtmlPicture extends PictureTags
{
    public function replaceUrl($url) {
        return AlterHtmlHelper::getWebPUrl($url, null);
    }
}
